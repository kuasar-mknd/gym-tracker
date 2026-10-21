<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Workout;
use Tests\Support\CeQueLeClientLit;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

/*
 * L'adresse d'une séance démarrée sans réseau (#2049) : `/workouts/en-attente/{clé}`,
 * la clé que l'appareil a tirée. Tant que la file ne l'a pas créée, la page vit
 * sur l'appareil ; rechargée en ligne, ou rouverte depuis l'historique une fois
 * la séance créée, l'adresse mène à la séance. Une clé inconnue et celle d'un
 * autre compte reçoivent la même réponse, entière : aucun oracle.
 */

/**
 * Une séance de ce compte sous cette clé.
 */
function seanceEnAttenteDeCle(User $compte, string $cle): Workout
{
    $seance = Workout::factory()->for($compte)->make(['ended_at' => null]);
    $seance->idempotency_key = $cle;
    $seance->save();

    return $seance;
}

it('mène à la séance que la file a créée sous cette clé', function (): void {
    $compte = User::factory()->create();
    $seance = seanceEnAttenteDeCle($compte, '1f2e3d4c-2049-4b5a-9c8d-0000000000f1');

    actingAs($compte)
        ->get(route('workouts.en-attente', ['cle' => '1f2e3d4c-2049-4b5a-9c8d-0000000000f1']))
        ->assertRedirect(route('workouts.show', $seance));
});

/*
 * La clé est comparée à l'octet près : en capitales, ce n'est pas la même
 * (`ascii_bin`, une égalité d'identité ne se confie pas à une collation).
 */
it('ne confond pas une clé avec la même en capitales', function (): void {
    $compte = User::factory()->create();
    seanceEnAttenteDeCle($compte, '1f2e3d4c-2049-4b5a-9c8d-0000000000fa');

    actingAs($compte)
        ->get(route('workouts.en-attente', ['cle' => '1F2E3D4C-2049-4B5A-9C8D-0000000000FA']))
        ->assertRedirect(route('dashboard'));
});

it('répond pareil à une clé inconnue et à la clé d’une séance d’un autre compte', function (): void {
    // L'échéance des cookies suit l'horloge : une seconde qui passait entre les deux relevés les faisait différer.
    $this->freezeSecond();
    $compte = User::factory()->create();
    seanceEnAttenteDeCle(User::factory()->create(), '1f2e3d4c-2049-4b5a-9c8d-0000000000f2');

    // Tout ce que le client lit, page suivante comprise. Les deux adresses
    // diffèrent par construction (la clé) : la session les garde sous
    // `_previous`, seul champ ôté (`.ai/rules/tests.md`).
    $releve = function (string $cle) use ($compte): array {
        $reponse = actingAs($compte)->get(route('workouts.en-attente', ['cle' => $cle]));
        $reponse->assertRedirect(route('dashboard'));
        $lu = CeQueLeClientLit::de($reponse, route('dashboard'));
        unset($lu['session']['_previous']);

        return $lu;
    };

    // La première page de la session jette la clé de l'historique (le
    // titulaire change, `HandleInertiaRequests`) : une visite d'abord, pour
    // comparer deux visites pareilles.
    actingAs($compte)->get(route('dashboard'))->assertOk();

    expect($releve('1f2e3d4c-2049-4b5a-9c8d-0000000000f2'))->toBe($releve('1f2e3d4c-2049-4b5a-9c8d-0000000000f3'));
});

it('renvoie un invité à la connexion', function (): void {
    get(route('workouts.en-attente', ['cle' => '1f2e3d4c-2049-4b5a-9c8d-0000000000f4']))
        ->assertRedirect(route('login'));
});

it('ne connaît pas une clé mal formée', function (string $cle): void {
    actingAs(User::factory()->create())
        ->get('/workouts/en-attente/'.$cle)
        ->assertNotFound();
})->with([
    'deux-points' => ['modele-ligne:0:3'],
    'trop longue' => [str_repeat('a', 65)],
    'point' => ['cle.json'],
]);
