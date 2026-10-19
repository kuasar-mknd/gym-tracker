<?php

declare(strict_types=1);

use App\Exceptions\UneSeanceEstDejaEnCours;
use App\Models\Exercise;
use App\Models\User;
use App\Models\Workout;
use App\Models\WorkoutLine;
use App\Models\WorkoutTemplate;
use App\Models\WorkoutTemplateLine;
use App\Models\WorkoutTemplateSet;
use App\Services\ActiveWorkoutService;
use App\Services\Stats\ClesDeStats;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Exceptions;

use function Pest\Laravel\actingAs;

/*
 * Démarrer une séance par l'API, que la file hors ligne rejoue (#2049).
 *
 * Sans réseau, la séance naît sur l'appareil : sa clé (un UUID) part dans
 * l'en-tête `Idempotency-Key`, l'heure du geste dans `started_at`, et la file
 * rejoue le démarrage tant qu'aucune réponse ne lui est parvenue. Le serveur
 * rend la séance de la clé (200) au lieu d'en ouvrir une seconde, refuse en
 * 409 quand une autre séance est en cours, et borne l'heure de l'appareil.
 */

beforeEach(function (): void {
    Carbon::setTestNow('2026-10-19 12:00:00');
});

/**
 * Démarre une séance par l'API pour ce compte.
 *
 * @param  array<string, mixed>  $corps
 * @return \Illuminate\Testing\TestResponse<\Symfony\Component\HttpFoundation\Response>
 */
function demarrageApi(User $compte, ?string $cle, array $corps = []): \Illuminate\Testing\TestResponse
{
    return actingAs($compte)->postJson(route('api.v1.workouts.store'), $corps, $cle === null ? [] : ['Idempotency-Key' => $cle]);
}

it('démarre la séance à l’heure du geste sur l’appareil, ramenée au fuseau de l’application', function (): void {
    $compte = User::factory()->create();
    $cle = '9a8b7c6d-2049-4e5f-8a9b-0000000000d1';

    $reponse = demarrageApi($compte, $cle, ['started_at' => '2026-10-19T08:00:00Z'])->assertCreated();
    $seance = Workout::query()->where('user_id', $compte->id)->sole();

    expect($reponse->json('data.id'))->toBe($seance->id)
        ->and($seance->started_at->format('Y-m-d H:i:s'))->toBe('2026-10-19 10:00:00')
        ->and($seance->name)->toBe('19/10/2026')
        ->and($seance->idempotency_key)->toBe($cle)
        ->and($seance->ended_at)->toBeNull();
});

it('rend la même séance au rejeu de la clé, sans relire le second envoi', function (): void {
    $compte = User::factory()->create();
    $cle = '9a8b7c6d-2049-4e5f-8a9b-0000000000d2';
    $premier = demarrageApi($compte, $cle, ['started_at' => '2026-10-19T08:00:00Z', 'name' => 'Jambes'])->assertCreated();

    $rejeu = demarrageApi($compte, $cle, ['started_at' => '2026-10-19T09:30:00Z', 'name' => 'Autre chose'])->assertOk();
    $seance = Workout::query()->where('user_id', $compte->id)->sole();

    expect($rejeu->json('data.id'))->toBe($premier->json('data.id'))
        ->and($seance->name)->toBe('Jambes')
        ->and($seance->started_at->format('Y-m-d H:i:s'))->toBe('2026-10-19 10:00:00');
});

/*
 * La séance de la clé est encore en cours : c'est la sienne, et son rejeu
 * n'est pas un conflit. La clé se cherche avant la séance en cours.
 */
it('rend sa propre séance encore en cours au rejeu, pas un refus', function (): void {
    $compte = User::factory()->create();
    $cle = '9a8b7c6d-2049-4e5f-8a9b-0000000000d3';
    actingAs($compte)->post(route('workouts.store'), [], ['Idempotency-Key' => $cle]);
    $seance = Workout::query()->where('user_id', $compte->id)->whereNull('ended_at')->sole();

    demarrageApi($compte, $cle, ['started_at' => '2026-10-19T09:59:00Z'])
        ->assertOk()
        ->assertJsonPath('data.id', $seance->id);
});

/*
 * Une autre séance est ouverte, ici ou depuis un autre appareil : le
 * démarrage est refusé, la raison et la séance en cours nommées, sans rien
 * créer. La file l'annonce comme un refus ; une page qui démarre en ligne y
 * renvoie.
 */
it('refuse en 409 le démarrage quand une autre séance est en cours', function (): void {
    $compte = User::factory()->create();
    $enCours = Workout::factory()->create(['user_id' => $compte->id, 'started_at' => now()->subHour(), 'ended_at' => null]);

    $reponse = demarrageApi($compte, '9a8b7c6d-2049-4e5f-8a9b-0000000000d4', ['started_at' => '2026-10-19T09:55:00Z']);

    $reponse->assertStatus(409)->assertExactJson([
        'message' => 'Une séance est déjà en cours : terminez-la avant d’en commencer une autre.',
        'raison' => UneSeanceEstDejaEnCours::RAISON,
        'seance' => ['id' => $enCours->id],
    ]);

    expect(Workout::query()->where('user_id', $compte->id)->pluck('id')->all())->toBe([$enCours->id]);
});

/*
 * La clé arrive dans un en-tête que le client écrit : celle d'un autre compte
 * ne rend jamais sa séance, et ne bloque pas le démarrage du compte.
 */
it('démarre une séance du compte sous la clé d’un autre, sans jamais rendre la sienne', function (): void {
    $autre = User::factory()->create();
    $compte = User::factory()->create();
    $cle = '9a8b7c6d-2049-4e5f-8a9b-0000000000d5';
    $deLAutre = demarrageApi($autre, $cle)->assertCreated()->json('data.id');

    $mienne = demarrageApi($compte, $cle)->assertCreated()->json('data.id');

    expect($mienne)->not->toBe($deLAutre)
        ->and(Workout::query()->whereKey($mienne)->sole()->user_id)->toBe($compte->id);
});

dataset('débuts que l’appareil ne peut pas avoir donnés', [
    'dans le futur' => ['2026-10-19T10:00:01Z'],
    'de plus de trente jours' => ['2026-09-19T09:59:59Z'],
]);

it('démarre maintenant quand l’heure de l’appareil sort des bornes', function (string $debut): void {
    $compte = User::factory()->create();

    demarrageApi($compte, '9a8b7c6d-2049-4e5f-8a9b-0000000000d6', ['started_at' => $debut])->assertCreated();

    expect(Workout::query()->where('user_id', $compte->id)->sole()->started_at->format('Y-m-d H:i:s'))->toBe('2026-10-19 12:00:00');
})->with('débuts que l’appareil ne peut pas avoir donnés');

it('démarre maintenant sans heure de l’appareil', function (): void {
    $compte = User::factory()->create();

    demarrageApi($compte, '9a8b7c6d-2049-4e5f-8a9b-0000000000d7')->assertCreated();

    expect(Workout::query()->where('user_id', $compte->id)->sole()->started_at->format('Y-m-d H:i:s'))->toBe('2026-10-19 12:00:00');
});

/*
 * Une clé hors de l'ASCII imprimable, ou trop longue pour sa colonne, est
 * ignorée comme pour les séries (#2071) : la séance démarre sans clé, au lieu
 * d'une erreur 500 que la file réessaierait sans fin.
 */
it('démarre sans clé quand la clé n’est pas de l’ASCII imprimable', function (string $cle): void {
    Exceptions::fake();
    $compte = User::factory()->create();

    demarrageApi($compte, $cle)->assertCreated();

    expect(Workout::query()->where('user_id', $compte->id)->sole()->idempotency_key)->toBeNull();
    Exceptions::assertNothingReported();
})->with([
    'octets hors UTF-8' => ["\xFF\xFE"],
    'é en latin-1' => ["cl\xE9"],
    'é en UTF-8' => ['clé'],
    'espace' => ['une cle'],
    'trop longue' => [str_repeat('a', 65)],
]);

/*
 * La séance démarrée devient la séance en cours de la prop partagée, même
 * quand le cache de deux heures disait qu'il n'y en avait pas, et les
 * statistiques des séances se recalculent.
 */
it('oublie la séance en cours du cache et vide les statistiques', function (): void {
    $compte = User::factory()->create();
    expect(app(ActiveWorkoutService::class)->for($compte))->toBeNull();
    Cache::put(ClesDeStats::seances($compte, 'duration_history.20'), ['périmé'], 600);

    $id = demarrageApi($compte, '9a8b7c6d-2049-4e5f-8a9b-0000000000d8')->assertCreated()->json('data.id');

    expect(app(ActiveWorkoutService::class)->for($compte)?->id)->toBe($id)
        ->and(Cache::has(ClesDeStats::seances($compte, 'duration_history.20')))->toBeFalse();
});

/**
 * Un modèle du compte : un exercice de deux séries.
 */
function demarrageApiModele(User $compte): WorkoutTemplate
{
    $modele = WorkoutTemplate::factory()->create(['user_id' => $compte->id, 'name' => 'Dos']);
    $ligne = WorkoutTemplateLine::factory()->create([
        'workout_template_id' => $modele->id,
        'exercise_id' => Exercise::factory()->create(['user_id' => $compte->id])->id,
        'order' => 0,
    ]);
    WorkoutTemplateSet::factory()->create(['workout_template_line_id' => $ligne->id, 'reps' => 10, 'weight' => 50, 'order' => 0]);
    WorkoutTemplateSet::factory()->create(['workout_template_line_id' => $ligne->id, 'reps' => 8, 'weight' => 55, 'order' => 1]);

    return $modele;
}

it('lance un modèle sous sa clé, lignes et séries sous les clés de leur rang', function (): void {
    $compte = User::factory()->create();
    $modele = demarrageApiModele($compte);
    $cle = '9a8b7c6d-2049-4e5f-8a9b-0000000000d9';

    $reponse = demarrageApi($compte, $cle, ['template_id' => $modele->id, 'started_at' => '2026-10-19T09:00:00Z'])->assertCreated();
    $seance = Workout::query()->where('user_id', $compte->id)->sole();
    $ligne = WorkoutLine::query()->where('workout_id', $seance->id)->sole();

    expect($reponse->json('data.id'))->toBe($seance->id)
        ->and($seance->name)->toBe('Dos')
        ->and($seance->idempotency_key)->toBe($cle)
        ->and($seance->started_at->format('Y-m-d H:i:s'))->toBe('2026-10-19 11:00:00')
        ->and($ligne->idempotency_key)->toBe("modele-ligne:0:{$ligne->exercise_id}")
        ->and($ligne->sets()->orderBy('order')->pluck('idempotency_key')->all())->toBe(['modele-serie:0:0', 'modele-serie:0:1']);

    // Rejoué : la même séance, rien de recopié deux fois.
    demarrageApi($compte, $cle, ['template_id' => $modele->id])->assertOk()->assertJsonPath('data.id', $seance->id);

    expect($ligne->sets()->count())->toBe(2);
});

/*
 * Le modèle se cherche parmi ceux du compte : celui d'un autre et un modèle
 * absent reçoivent le même refus, sur le champ, et rien ne démarre.
 */
it('refuse en 422 le modèle d’un autre compte comme un modèle absent', function (): void {
    $compte = User::factory()->create();
    $modeleDUnAutre = demarrageApiModele(User::factory()->create());

    $dUnAutre = demarrageApi($compte, '9a8b7c6d-2049-4e5f-8a9b-0000000000da', ['template_id' => $modeleDUnAutre->id])->assertUnprocessable();
    $absent = demarrageApi($compte, '9a8b7c6d-2049-4e5f-8a9b-0000000000db', ['template_id' => $modeleDUnAutre->id + 1000])->assertUnprocessable();

    expect($dUnAutre->json())->toBe($absent->json())
        ->and($dUnAutre->json('errors.template_id.0'))->toBe('Le champ modèle sélectionné est invalide.')
        ->and(Workout::query()->where('user_id', $compte->id)->count())->toBe(0);
});

/*
 * La clé nomme la tentative ; l'appareil la connaît déjà, et rien ne doit la
 * faire sortir de l'API. La page de séance, qui ne montre que la séance du
 * compte, la porte (IdempotentCreateTest).
 */
it('ne montre jamais la clé dans la réponse', function (): void {
    $compte = User::factory()->create();
    $cle = '9a8b7c6d-2049-4e5f-8a9b-0000000000dc';

    $creation = demarrageApi($compte, $cle)->assertCreated();
    $rejeu = demarrageApi($compte, $cle)->assertOk();

    expect($creation->json('data'))->not->toHaveKey('idempotency_key')
        ->and((string) $creation->getContent())->not->toContain($cle)
        ->and((string) $rejeu->getContent())->not->toContain($cle);
});
