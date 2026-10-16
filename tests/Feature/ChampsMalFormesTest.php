<?php

declare(strict_types=1);

use App\Models\Goal;
use App\Models\User;
use App\Models\Workout;
use Illuminate\Support\Facades\Exceptions;

use function Pest\Laravel\actingAs;

/*
 * Des champs que la validation laissait atteindre ce qui ne sait pas les lire
 * (#2071) : `current_password` passait un tableau à `password_verify()`, une
 * comparaison de dates (`after:today`, `before_or_equal:today`) un nombre
 * trop grand à Carbon, même quand `date` l'avait déjà refusé, et le début
 * d'une séance, effacé, allait jusqu'à la colonne qui l'exige. Chacun
 * répondait 500 ; chacun est désormais refusé sur son champ, en 422, sans
 * qu'aucune exception ne soit rapportée.
 */

beforeEach(function (): void {
    Exceptions::fake();
});

/**
 * Une requête à envoyer au nom du compte : méthode, adresse, corps, et le
 * champ que la réponse doit refuser.
 *
 * @return array{0: string, 1: string, 2: array<string, mixed>, 3: string}
 */
function champsMalFormesRequete(string $cas, User $compte): array
{
    $objectif = ['title' => 'Séances', 'type' => 'frequency', 'target_value' => 12];

    return match ($cas) {
        'mot de passe en tableau, suppression du compte' => ['DELETE', route('profile.destroy'), ['password' => ['password']], 'password'],
        'mot de passe actuel en tableau, changement de mot de passe' => ['PUT', route('password.update'), [
            'current_password' => ['password'],
            'password' => 'Un-Nouveau-Mot-De-Passe-42',
            'password_confirmation' => 'Un-Nouveau-Mot-De-Passe-42',
        ], 'current_password'],
        'mot de passe actuel en tableau, changement d’adresse' => ['PATCH', route('profile.update'), [
            'name' => 'Nom',
            'email' => 'nouvelle@example.org',
            'current_password' => ['password'],
        ], 'current_password'],
        'échéance d’un nouvel objectif trop grande' => ['POST', route('goals.store'), [...$objectif, 'deadline' => PHP_INT_MAX], 'deadline'],
        'échéance d’un objectif déplacée, nombre flottant démesuré' => [
            'PUT',
            route('goals.update', Goal::factory()->create(['user_id' => $compte->id, 'type' => 'frequency'])),
            [...$objectif, 'deadline' => 1e300],
            'deadline',
        ],
        'date d’une mensuration trop grande' => ['POST', route('body-parts.store'), [
            'part' => 'Biceps',
            'value' => 35,
            'unit' => 'cm',
            'measured_at' => -PHP_INT_MAX,
        ], 'measured_at'],
        default => throw new LogicException("Cas inconnu : {$cas}"),
    };
}

it('refuse sur son champ une valeur que la validation laissait passer jusqu’à une erreur', function (string $cas): void {
    $compte = User::factory()->create();
    [$methode, $adresse, $corps, $champ] = champsMalFormesRequete($cas, $compte);

    actingAs($compte)
        ->json($methode, $adresse, $corps)
        ->assertUnprocessable()
        ->assertJsonValidationErrors([$champ]);

    Exceptions::assertNothingReported();
})->with([
    'mot de passe en tableau, suppression du compte',
    'mot de passe actuel en tableau, changement de mot de passe',
    'mot de passe actuel en tableau, changement d’adresse',
    'échéance d’un nouvel objectif trop grande',
    'échéance d’un objectif déplacée, nombre flottant démesuré',
    'date d’une mensuration trop grande',
]);

it('refuse d’effacer le début d’une séance, et le laisse tel qu’il était', function (mixed $debut): void {
    $compte = User::factory()->create();
    $seance = Workout::withoutEvents(fn (): Workout => Workout::factory()->create([
        'user_id' => $compte->id,
        'started_at' => '2026-10-15 18:00:00',
    ]));

    actingAs($compte)
        ->patchJson(route('workouts.update', $seance), ['started_at' => $debut])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['started_at']);

    expect($seance->fresh()?->started_at?->format('Y-m-d H:i:s'))->toBe('2026-10-15 18:00:00');
    Exceptions::assertNothingReported();
})->with([
    'nul' => [null],
    'vide' => [''],
]);

it('laisse changer le nom d’une séance sans renvoyer son début', function (): void {
    $compte = User::factory()->create();
    $seance = Workout::withoutEvents(fn (): Workout => Workout::factory()->create([
        'user_id' => $compte->id,
        'started_at' => '2026-10-15 18:00:00',
    ]));

    actingAs($compte)
        ->patch(route('workouts.update', $seance), ['name' => 'Jambes'])
        ->assertSessionHasNoErrors();

    expect($seance->fresh()?->only(['name']))->toBe(['name' => 'Jambes'])
        ->and($seance->fresh()?->started_at?->format('Y-m-d H:i:s'))->toBe('2026-10-15 18:00:00');
    Exceptions::assertNothingReported();
});
