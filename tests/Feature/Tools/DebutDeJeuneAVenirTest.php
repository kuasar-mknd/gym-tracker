<?php

declare(strict_types=1);

use App\Models\Fast;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;

use function Pest\Laravel\actingAs;

/*
 * Un début de jeûne ne passe pas de plus de cinq minutes l'instant de la
 * requête (#2198).
 *
 * Le serveur enregistrait tel quel un début tapé dans le futur, et la page
 * comptait un écoulé négatif (« -1:-30:00 », « 16:30:00 restants »), puis,
 * le jeûne terminé avant son début, une durée négative dans l'historique et
 * une barre sous l'axe. Laissé tel quel, le champ « Début » part à l'instant
 * du geste, lu sur l'horloge de l'appareil : cinq minutes d'avance passent,
 * celles d'une horloge un peu en avance. Au-delà, le début est une heure
 * choisie dans le futur, refusée sur son champ, à la création comme à la
 * modification. La fin, prise à l'instant du geste et sans champ pour la
 * corriger, n'est pas jugée : un jeûne terminé avant son début dure zéro sur
 * la page (`tests/js/pages/heuresEtDureesDuJeune.test.js`).
 *
 * Le 10 mars 2026 à 12:00Z : 13:00 à Paris, le fuseau de l'application,
 * 08:00 à Toronto.
 */

const DEBUT_A_VENIR_REFUS = 'Ton jeûne ne peut pas commencer dans le futur : vérifie l\'heure du début et celle de ton appareil.';

beforeEach(function (): void {
    Carbon::setTestNow(Carbon::parse('2026-03-10T12:00:00Z'));
});

afterEach(function (): void {
    Carbon::setTestNow();
});

/**
 * Envoie le formulaire comme la page, depuis la page du jeûne : en JSON, avec
 * les en-têtes d'une visite d'Inertia (`.ai/rules/tests.md`).
 *
 * @param  array<string, mixed>  $donnees
 * @return TestResponse<Response>
 */
function debutAVenirEnvoyer(User $compte, string $methode, string $adresse, array $donnees): TestResponse
{
    /** @var TestResponse<Response> $reponse */
    $reponse = actingAs($compte)->from(route('tools.fasting.index'))->json($methode, $adresse, $donnees, [
        'X-Inertia' => 'true',
        'X-Requested-With' => 'XMLHttpRequest',
        'Accept' => 'text/html, application/xhtml+xml',
    ]);

    return $reponse;
}

/**
 * Démarre un jeûne de seize heures comme la page, avec le début donné.
 *
 * @return TestResponse<Response>
 */
function debutAVenirDemarrer(User $compte, string $debut): TestResponse
{
    return debutAVenirEnvoyer($compte, 'POST', route('tools.fasting.store'), [
        'start_time' => $debut,
        'target_duration_minutes' => 960,
        'type' => '16:8',
    ]);
}

/**
 * L'instant d'une date relue en base, en UTC.
 */
function debutAVenirEnUtc(mixed $date): string
{
    assert($date instanceof CarbonInterface);

    return $date->toIso8601ZuluString();
}

it('refuse sur son champ un début à plus de cinq minutes, et n’enregistre aucun jeûne', function (string $debut): void {
    $compte = User::factory()->create();

    debutAVenirDemarrer($compte, $debut)
        ->assertRedirect(route('tools.fasting.index'))
        ->assertSessionHasErrors(['start_time' => DEBUT_A_VENIR_REFUS]);

    expect(Fast::query()->where('user_id', $compte->id)->exists())->toBeFalse();
})->with([
    'trente minutes, l’instant en UTC' => '2026-03-10T12:30:00.000Z',
    'trente minutes, avec le décalage de Toronto' => '2026-03-10T08:30:00-04:00',
    'cinq minutes et une seconde' => '2026-03-10T12:05:01.000Z',
    'le lendemain, à l’heure murale de l’application' => '2026-03-11 13:00',
]);

it('enregistre tel quel un début jusqu’à cinq minutes en avance, celle d’une horloge d’appareil', function (string $debut): void {
    $compte = User::factory()->create();

    debutAVenirDemarrer($compte, $debut)
        ->assertRedirect(route('tools.fasting.index'))
        ->assertSessionHasNoErrors();

    $jeune = Fast::query()->where('user_id', $compte->id)->sole();

    expect(debutAVenirEnUtc($jeune->start_time))->toBe(Carbon::parse($debut)->toIso8601ZuluString())
        ->and($jeune->status)->toBe('active');
})->with([
    'l’instant de la requête' => '2026-03-10T12:00:00.000Z',
    'deux secondes en avance' => '2026-03-10T12:00:02.000Z',
    'cinq minutes pile' => '2026-03-10T12:05:00.000Z',
    'cinq minutes, avec le décalage de Toronto' => '2026-03-10T08:05:00-04:00',
    'huit heures plus tôt' => '2026-03-10T04:00:00.000Z',
]);

it('refuse de même un début déplacé à plus de cinq minutes, et garde celui du jeûne', function (): void {
    $compte = User::factory()->create();
    debutAVenirDemarrer($compte, '2026-03-10T11:00:00.000Z')->assertSessionHasNoErrors();
    $jeune = Fast::query()->where('user_id', $compte->id)->sole();

    debutAVenirEnvoyer($compte, 'PATCH', route('tools.fasting.update', $jeune), ['start_time' => '2026-03-10T12:30:00.000Z'])
        ->assertRedirect(route('tools.fasting.index'))
        ->assertSessionHasErrors(['start_time' => DEBUT_A_VENIR_REFUS]);

    expect(debutAVenirEnUtc($jeune->refresh()->start_time))->toBe('2026-03-10T11:00:00Z');

    debutAVenirEnvoyer($compte, 'PATCH', route('tools.fasting.update', $jeune), ['start_time' => '2026-03-10T12:05:00.000Z'])
        ->assertSessionHasNoErrors();

    expect(debutAVenirEnUtc($jeune->refresh()->start_time))->toBe('2026-03-10T12:05:00Z');
});

it('termine sans refus un jeûne dont le début est encore à venir', function (): void {
    // Commencé sur un appareil en avance de trois minutes, terminé une minute plus tard sur un autre, à l'heure.
    $compte = User::factory()->create();
    debutAVenirDemarrer($compte, '2026-03-10T12:03:00.000Z')->assertSessionHasNoErrors();
    $jeune = Fast::query()->where('user_id', $compte->id)->sole();

    Carbon::setTestNow(Carbon::parse('2026-03-10T12:01:00Z'));

    debutAVenirEnvoyer($compte, 'PATCH', route('tools.fasting.update', $jeune), [
        'end_time' => '2026-03-10T12:01:00.000Z',
        'status' => 'completed',
    ])
        ->assertRedirect(route('tools.fasting.index'))
        ->assertSessionHasNoErrors();

    $jeune->refresh();

    expect($jeune->status)->toBe('completed')
        ->and(debutAVenirEnUtc($jeune->start_time))->toBe('2026-03-10T12:03:00Z')
        ->and(debutAVenirEnUtc($jeune->end_time))->toBe('2026-03-10T12:01:00Z');
});
