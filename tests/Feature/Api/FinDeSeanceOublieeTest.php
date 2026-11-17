<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Workout;
use App\Support\HeureDeLAppareil;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;

use function Pest\Laravel\actingAs;

/*
 * Terminer une séance oubliée à l'heure que la personne a choisie (#2319).
 *
 * Au-delà de douze heures, la page propose « l'heure de la dernière série » ou
 * une heure à la main : une heure absolue (`fin_choisie`), que l'âge du geste
 * ne remplace pas. Le serveur la juge : jamais avant le début, jamais après
 * maintenant (Paris, `.ai/rules/horloge.md`). Une séance de moins de douze heures
 * n'envoie rien de tel et garde la fin du geste.
 */

beforeEach(function (): void {
    Carbon::setTestNow('2026-10-19 12:00:00');
});

/** Une séance ouverte hier à 9 h, oubliée depuis. */
function finOublieeSeance(User $compte): Workout
{
    return Workout::factory()->create(['user_id' => $compte->id, 'started_at' => '2026-10-18 09:00:00', 'ended_at' => null]);
}

/**
 * @param  array<string, mixed>  $corps
 * @return TestResponse<Response>
 */
function finOubliee(User $compte, array $corps): TestResponse
{
    return actingAs($compte)->patchJson(
        route('api.v1.workouts.finish'),
        $corps,
        [HeureDeLAppareil::ENTETE => '2026-10-19T10:00:00.000Z'],
    );
}

it('clôt la séance oubliée à l’heure choisie, lue en UTC et écrite à l’heure de Paris', function (): void {
    $compte = User::factory()->create();
    $seance = finOublieeSeance($compte);

    $reponse = finOubliee($compte, [
        'workout_id' => $seance->id,
        'ended_at' => '2026-10-19T09:59:00Z',
        'fin_choisie' => '2026-10-18T10:30:00Z',
    ])->assertOk();

    expect($seance->refresh()->ended_at?->format('Y-m-d H:i:s'))->toBe('2026-10-18 12:30:00')
        ->and($reponse->json('data.ended_at'))->toBe($seance->ended_at?->toJSON())
        ->and((int) $seance->started_at->diffInMinutes($seance->ended_at))->toBe(210);
});

it('rejoue la même heure choisie, des heures plus tard', function (): void {
    $compte = User::factory()->create();
    $seance = finOublieeSeance($compte);
    Carbon::setTestNow('2026-10-19 18:00:00');

    finOubliee($compte, [
        'workout_id' => $seance->id,
        'ended_at' => '2026-10-19T09:59:00Z',
        'fin_choisie' => '2026-10-18T10:30:00Z',
    ])->assertOk();

    expect($seance->refresh()->ended_at?->format('Y-m-d H:i:s'))->toBe('2026-10-18 12:30:00');
});

it('refuse une fin choisie avant le début', function (): void {
    $compte = User::factory()->create();
    $seance = finOublieeSeance($compte);

    finOubliee($compte, ['workout_id' => $seance->id, 'fin_choisie' => '2026-10-18T06:59:59Z'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['fin_choisie']);

    expect($seance->refresh()->ended_at)->toBeNull();
});

it('accepte une fin choisie égale au début', function (): void {
    $compte = User::factory()->create();
    $seance = finOublieeSeance($compte);

    finOubliee($compte, ['workout_id' => $seance->id, 'fin_choisie' => '2026-10-18T07:00:00Z'])->assertOk();

    expect($seance->refresh()->ended_at?->format('Y-m-d H:i:s'))->toBe('2026-10-18 09:00:00');
});

it('refuse une fin choisie dans le futur, et accepte maintenant', function (): void {
    $compte = User::factory()->create();
    $seance = finOublieeSeance($compte);

    finOubliee($compte, ['workout_id' => $seance->id, 'fin_choisie' => '2026-10-19T10:00:01Z'])
        ->assertUnprocessable()
        ->assertJsonPath('errors.fin_choisie.0', 'La fin de la séance ne peut pas être dans le futur.');

    finOubliee($compte, ['workout_id' => $seance->id, 'fin_choisie' => '2026-10-19T10:00:00Z'])->assertOk();

    expect($seance->refresh()->ended_at?->format('Y-m-d H:i:s'))->toBe('2026-10-19 12:00:00');
});

it('refuse une fin choisie qui n’est pas une date', function (): void {
    $compte = User::factory()->create();
    $seance = finOublieeSeance($compte);

    finOubliee($compte, ['workout_id' => $seance->id, 'fin_choisie' => 'hier soir'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['fin_choisie']);
});

it('garde la première fin d’une séance déjà close, même avec une autre heure choisie', function (): void {
    $compte = User::factory()->create();
    $seance = finOublieeSeance($compte);
    finOubliee($compte, ['workout_id' => $seance->id, 'fin_choisie' => '2026-10-18T10:30:00Z'])->assertOk();

    finOubliee($compte, ['workout_id' => $seance->id, 'fin_choisie' => '2026-10-18T12:00:00Z'])->assertOk();

    expect($seance->refresh()->ended_at?->format('Y-m-d H:i:s'))->toBe('2026-10-18 12:30:00');
});

it('juge aussi la fin choisie quand l’identifiant de la séance arrive en texte', function (): void {
    $compte = User::factory()->create();
    $seance = finOublieeSeance($compte);

    finOubliee($compte, ['workout_id' => (string) $seance->id, 'fin_choisie' => '2026-10-18T06:59:59Z'])
        ->assertUnprocessable()
        ->assertJsonPath('errors.fin_choisie.0', 'La fin de la séance ne peut pas précéder son début.');
});

it('ne refuse qu’une seule fois une fin choisie à venir', function (): void {
    $compte = User::factory()->create();
    $seance = finOublieeSeance($compte);

    finOubliee($compte, ['workout_id' => $seance->id, 'fin_choisie' => '2026-10-19T10:00:01Z'])
        ->assertJsonCount(1, 'errors.fin_choisie');
});

it('ne juge pas la fin choisie contre la séance d’un autre compte', function (): void {
    $compte = User::factory()->create();
    $autre = finOublieeSeance(User::factory()->create());

    finOubliee($compte, ['workout_id' => $autre->id, 'fin_choisie' => '2026-10-18T06:00:00Z'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['workout_id'])
        ->assertJsonMissingValidationErrors(['fin_choisie']);

    expect($autre->refresh()->ended_at)->toBeNull();
});

it('garde le comportement d’une séance de moins de douze heures : la fin du geste', function (): void {
    $compte = User::factory()->create();
    $seance = Workout::factory()->create(['user_id' => $compte->id, 'started_at' => '2026-10-19 11:00:00', 'ended_at' => null]);

    finOubliee($compte, ['workout_id' => $seance->id, 'ended_at' => '2026-10-19T09:30:00Z'])->assertOk();

    expect($seance->refresh()->ended_at?->format('Y-m-d H:i:s'))->toBe('2026-10-19 11:30:00');
});
