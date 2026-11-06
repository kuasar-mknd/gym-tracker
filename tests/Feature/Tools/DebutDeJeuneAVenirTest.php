<?php

declare(strict_types=1);

use App\Models\Fast;
use App\Models\User;
use App\Support\HeureDeLAppareil;
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
 * une barre sous l'axe. Une heure tapée, ou envoyée par une page chargée
 * avant ce correctif, se juge telle quelle : cinq minutes d'avance passent,
 * celles d'une horloge un peu en avance ; au-delà, le début est une heure
 * choisie dans le futur, refusée sur son champ, à la création comme à la
 * modification. Laissé tel quel, le champ « Début » part à l'instant du
 * geste, lu sur l'horloge de l'appareil, avec l'heure de l'appareil au
 * départ (`X-Heure-De-L-Appareil`) : le serveur n'en garde que l'âge et le
 * place sur sa propre horloge, si bien qu'un appareil en avance de plus de
 * cinq minutes commence son jeûne, et à l'heure juste. La fin part de même
 * avec l'heure de l'appareil, et se place de même ; elle n'est jamais jugée,
 * même envoyée sans elle depuis un appareil en avance : un jeûne terminé
 * avant son début dure zéro sur la page
 * (`tests/js/pages/heuresEtDureesDuJeune.test.js`).
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
 * les en-têtes d'une visite d'Inertia (`.ai/rules/tests.md`), et l'heure de
 * l'appareil au départ quand la page l'envoie (un début laissé tel quel, la
 * fin).
 *
 * @param  array<string, mixed>  $donnees
 * @return TestResponse<Response>
 */
function debutAVenirEnvoyer(User $compte, string $methode, string $adresse, array $donnees, ?string $heureAuDepart = null): TestResponse
{
    $entetes = [
        'X-Inertia' => 'true',
        'X-Requested-With' => 'XMLHttpRequest',
        'Accept' => 'text/html, application/xhtml+xml',
    ];

    if ($heureAuDepart !== null) {
        $entetes[HeureDeLAppareil::ENTETE] = $heureAuDepart;
    }

    /** @var TestResponse<Response> $reponse */
    $reponse = actingAs($compte)->from(route('tools.fasting.index'))->json($methode, $adresse, $donnees, $entetes);

    return $reponse;
}

/**
 * Démarre un jeûne de seize heures comme la page, avec le début donné, et
 * l'heure de l'appareil au départ s'il y en a une.
 *
 * @return TestResponse<Response>
 */
function debutAVenirDemarrer(User $compte, string $debut, ?string $heureAuDepart = null): TestResponse
{
    return debutAVenirEnvoyer($compte, 'POST', route('tools.fasting.store'), [
        'start_time' => $debut,
        'target_duration_minutes' => 960,
        'type' => '16:8',
    ], $heureAuDepart);
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

it('termine sans refus un jeûne dont la fin, envoyée sans l’heure de l’appareil, vient d’un appareil en avance de plus de cinq minutes', function (): void {
    // La fin n'est jamais jugée : envoyée à l'instant du geste, lue sur une horloge d'appareil qui peut avancer de plus
    // que la tolérance du début, sans champ pour la corriger, son refus empêcherait de terminer le jeûne. Le cas
    // précédent l'envoie à l'instant de la requête, et ne verrait pas une borne posée avec la tolérance du début.
    $compte = User::factory()->create();
    debutAVenirDemarrer($compte, '2026-03-10T12:00:00.000Z')->assertSessionHasNoErrors();
    $jeune = Fast::query()->where('user_id', $compte->id)->sole();

    Carbon::setTestNow(Carbon::parse('2026-03-10T12:01:00Z'));

    debutAVenirEnvoyer($compte, 'PATCH', route('tools.fasting.update', $jeune), [
        'end_time' => '2026-03-10T12:11:00.000Z',
        'status' => 'completed',
    ])
        ->assertRedirect(route('tools.fasting.index'))
        ->assertSessionHasNoErrors();

    $jeune->refresh();

    expect($jeune->status)->toBe('completed')
        ->and(debutAVenirEnUtc($jeune->end_time))->toBe('2026-03-10T12:11:00Z');
});

it('place sur l’horloge du serveur un début laissé tel quel, quelle que soit l’avance ou le retard de l’appareil', function (string $geste): void {
    // La page envoie l'instant du geste et l'heure de l'appareil au départ, la même : l'âge du geste est nul, et le
    // début est l'instant de la requête, lu sur l'horloge du serveur.
    $compte = User::factory()->create();

    debutAVenirDemarrer($compte, $geste, heureAuDepart: $geste)
        ->assertRedirect(route('tools.fasting.index'))
        ->assertSessionHasNoErrors();

    $jeune = Fast::query()->where('user_id', $compte->id)->sole();

    expect(debutAVenirEnUtc($jeune->start_time))->toBe('2026-03-10T12:00:00Z')
        ->and($jeune->status)->toBe('active');
})->with([
    'six minutes en avance' => '2026-03-10T12:06:00.000Z',
    'trois heures en avance' => '2026-03-10T15:00:00.000Z',
    'six minutes en avance, avec le décalage de Toronto' => '2026-03-10T08:06:00-04:00',
    'deux minutes en retard' => '2026-03-10T11:58:00.000Z',
]);

it('retranche du maintenant du serveur l’âge du geste, et prend maintenant pour un âge négatif ou plus vieux que la file', function (string $debut, string $heureAuDepart, string $attendu): void {
    $compte = User::factory()->create();

    debutAVenirDemarrer($compte, $debut, $heureAuDepart)->assertSessionHasNoErrors();

    expect(debutAVenirEnUtc(Fast::query()->where('user_id', $compte->id)->sole()->start_time))->toBe($attendu);
})->with([
    'cinq secondes avant le départ, sur un appareil en avance de six minutes' => ['2026-03-10T12:05:55.000Z', '2026-03-10T12:06:00.000Z', '2026-03-10T11:59:55Z'],
    'un geste qui suivrait son départ' => ['2026-03-10T12:30:00.000Z', '2026-03-10T12:29:00.000Z', '2026-03-10T12:00:00Z'],
    'un geste de plus de trente jours' => ['2026-01-01T12:00:00.000Z', '2026-03-10T12:00:00.000Z', '2026-03-10T12:00:00Z'],
]);

it('juge tel quel un début dont l’heure de l’appareil au départ ne se lit pas', function (): void {
    $compte = User::factory()->create();

    debutAVenirDemarrer($compte, '2026-03-10T12:06:00.000Z', '2026-03-10T12:06:00.000Z next monday')
        ->assertSessionHasErrors(['start_time' => DEBUT_A_VENIR_REFUS]);

    expect(Fast::query()->where('user_id', $compte->id)->exists())->toBeFalse();
});

it('termine à l’heure du serveur un jeûne fini sur un appareil en avance, pour sa vraie durée', function (): void {
    // Le début et la fin se placent sur la même horloge : la durée ne prend pas l'écart de celle de l'appareil.
    $compte = User::factory()->create();
    debutAVenirDemarrer($compte, '2026-03-10T12:10:00.000Z', '2026-03-10T12:10:00.000Z')->assertSessionHasNoErrors();
    $jeune = Fast::query()->where('user_id', $compte->id)->sole();

    Carbon::setTestNow(Carbon::parse('2026-03-11T04:00:00Z'));

    debutAVenirEnvoyer($compte, 'PATCH', route('tools.fasting.update', $jeune), [
        'end_time' => '2026-03-11T04:10:00.000Z',
        'status' => 'completed',
    ], '2026-03-11T04:10:00.000Z')
        ->assertRedirect(route('tools.fasting.index'))
        ->assertSessionHasNoErrors();

    $jeune->refresh();

    expect(debutAVenirEnUtc($jeune->start_time))->toBe('2026-03-10T12:00:00Z')
        ->and(debutAVenirEnUtc($jeune->end_time))->toBe('2026-03-11T04:00:00Z')
        ->and($jeune->status)->toBe('completed');
});

it('place un début pris au geste dans la première occurrence de l’heure répétée, et l’y relit', function (): void {
    // `setTestNow()` ne pose pas un instant de la première occurrence (`.ai/rules/tests.md`) : l'horloge est posée dans
    // la seconde, à 02:10 (01:10Z), et l'âge du geste, cinquante minutes, ramène le début à 02:20 de l'heure d'été
    // (00:20Z), sur un appareil en avance de six minutes.
    Carbon::setTestNow(Carbon::parse('2026-10-25T01:10:00Z'));
    expect(now()->utc()->toIso8601ZuluString())->toBe('2026-10-25T01:10:00Z');

    $compte = User::factory()->create();

    debutAVenirDemarrer($compte, '2026-10-25T00:26:00.000Z', '2026-10-25T01:16:00.000Z')->assertSessionHasNoErrors();

    expect(debutAVenirEnUtc(Fast::query()->where('user_id', $compte->id)->sole()->start_time))->toBe('2026-10-25T00:20:00Z');
});
