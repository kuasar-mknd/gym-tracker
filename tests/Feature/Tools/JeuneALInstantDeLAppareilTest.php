<?php

declare(strict_types=1);

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Fast;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;

use function Pest\Laravel\actingAs;

/*
 * Un jeûne commence et finit à l'instant de l'appareil, où qu'il soit (#2111).
 *
 * La page du jeûne envoyait son début (`2026-03-10T08:00`) et sa fin
 * (`2026-03-11 00:00:00`) en heure murale de l'appareil, sans décalage, et le
 * serveur lit une telle heure comme une heure de l'application : un appareil à
 * Toronto démarrait son jeûne cinq heures trop tôt, et la page, qui relit
 * l'instant servi, affichait dès le départ cinq heures écoulées. Elle envoie
 * désormais le début par `formatToUTC()` et la fin par `toISOString()`.
 * `tests/js/pages/fastingIndex.test.js` vérifie, sous le fuseau de Toronto,
 * qu'elle envoie les valeurs que ce fichier envoie au serveur. Ce fichier les
 * envoie comme elle, en JSON, et vérifie qu'elles sont relues au même instant
 * (`RameneLesDatesAuFuseauDeLApplication`, #1952) et servies à la page telles
 * qu'elle compte un écoulé nul au départ.
 *
 * Le 10 mars 2026 à 12:00Z : 08:00 à Toronto (UTC-4 depuis le 8 mars), 13:00 à
 * Paris (UTC+1), le fuseau de l'application. Seize heures plus tard : minuit à
 * Toronto, 05:00 à Paris.
 */

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
function jeuneALInstantEnvoyer(User $user, string $methode, string $adresse, array $donnees): TestResponse
{
    /** @var TestResponse<Response> $reponse */
    $reponse = actingAs($user)->from(route('tools.fasting.index'))->json($methode, $adresse, $donnees, [
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
function jeuneALInstantDemarrer(User $user, string $debut): TestResponse
{
    return jeuneALInstantEnvoyer($user, 'POST', route('tools.fasting.store'), [
        'start_time' => $debut,
        'target_duration_minutes' => 960,
        'type' => '16:8',
    ]);
}

/**
 * Termine le jeûne comme la page, avec la fin donnée.
 *
 * @return TestResponse<Response>
 */
function jeuneALInstantTerminer(User $user, Fast $jeune, string $fin): TestResponse
{
    return jeuneALInstantEnvoyer($user, 'PATCH', route('tools.fasting.update', $jeune), [
        'end_time' => $fin,
        'status' => 'completed',
    ]);
}

/**
 * La page du jeûne telle que le client d'Inertia la reçoit à une visite : la
 * page seule, en JSON, sans le document qui demanderait les actifs construits.
 *
 * @return TestResponse<Response>
 */
function jeuneALInstantPage(User $user): TestResponse
{
    return actingAs($user)->get(route('tools.fasting.index'), [
        'X-Inertia' => 'true',
        'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(request()),
        'X-Requested-With' => 'XMLHttpRequest',
    ])->assertOk();
}

/**
 * L'instant d'une date relue en base, en UTC, pour le comparer à celui envoyé.
 */
function jeuneALInstantEnUtc(mixed $date): string
{
    assert($date instanceof CarbonInterface);

    return $date->toIso8601ZuluString();
}

/**
 * L'écoulé que la page affiche pour le jeûne en cours qu'elle reçoit :
 * maintenant moins le début servi, en secondes, comme son `updateTimer()`.
 *
 * @param  TestResponse<Response>  $page
 */
function jeuneALInstantEcouleAffiche(TestResponse $page): int
{
    $debut = $page->json('props.activeFast.start_time');

    assert(is_string($debut));

    return now()->getTimestamp() - Carbon::parse($debut)->getTimestamp();
}

it('relit au même instant le début envoyé depuis Toronto, et le sert à la page pour un écoulé nul', function (string $debut): void {
    $user = User::factory()->create();

    jeuneALInstantDemarrer($user, $debut)
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $jeune = Fast::query()->where('user_id', $user->id)->sole();

    expect(jeuneALInstantEnUtc($jeune->start_time))->toBe('2026-03-10T12:00:00Z');

    $page = jeuneALInstantPage($user);

    expect($page->json('props.activeFast.start_time'))->toBe('2026-03-10T12:00:00.000000Z')
        ->and(jeuneALInstantEcouleAffiche($page))->toBe(0);
})->with([
    'ce que la page envoie, formatToUTC() du champ' => '2026-03-10T12:00:00.000Z',
    'la même heure écrite avec le décalage de Toronto' => '2026-03-10T08:00:00-04:00',
]);

it('termine au même instant le jeûne fini depuis Toronto, et le range dans l’historique pour sa vraie durée', function (): void {
    $user = User::factory()->create();

    jeuneALInstantDemarrer($user, '2026-03-10T12:00:00.000Z')->assertSessionHasNoErrors();
    $jeune = Fast::query()->where('user_id', $user->id)->sole();

    Carbon::setTestNow(Carbon::parse('2026-03-11T04:00:00Z'));

    jeuneALInstantTerminer($user, $jeune, '2026-03-11T04:00:00.000Z')
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect(jeuneALInstantEnUtc($jeune->refresh()->end_time))->toBe('2026-03-11T04:00:00Z');

    $page = jeuneALInstantPage($user);
    $debut = $page->json('props.history.data.0.start_time');
    $fin = $page->json('props.history.data.0.end_time');

    assert(is_string($debut) && is_string($fin));

    expect($page->json('props.activeFast'))->toBeNull()
        ->and($debut)->toBe('2026-03-10T12:00:00.000000Z')
        ->and($fin)->toBe('2026-03-11T04:00:00.000000Z')
        ->and(Carbon::parse($fin)->getTimestamp() - Carbon::parse($debut)->getTimestamp())->toBe(16 * 3600);
});

it('enregistre le jeûne d’un appareil à l’heure de Paris comme avant', function (): void {
    $avant = User::factory()->create();
    $apres = User::factory()->create();

    // L'heure murale que la page envoyait, et l'instant qu'elle envoie, pour le même geste.
    jeuneALInstantDemarrer($avant, '2026-03-10T13:00')->assertSessionHasNoErrors();
    jeuneALInstantDemarrer($apres, '2026-03-10T12:00:00.000Z')->assertSessionHasNoErrors();

    Carbon::setTestNow(Carbon::parse('2026-03-11T04:00:00Z'));

    $jeuneDAvant = Fast::query()->where('user_id', $avant->id)->sole();
    $jeuneDApres = Fast::query()->where('user_id', $apres->id)->sole();

    jeuneALInstantTerminer($avant, $jeuneDAvant, '2026-03-11 05:00:00')->assertSessionHasNoErrors();
    jeuneALInstantTerminer($apres, $jeuneDApres, '2026-03-11T04:00:00.000Z')->assertSessionHasNoErrors();

    $jeuneDAvant->refresh();
    $jeuneDApres->refresh();

    expect($jeuneDApres->getRawOriginal('start_time'))->toBe('2026-03-10 13:00:00')
        ->and($jeuneDApres->getRawOriginal('start_time'))->toBe($jeuneDAvant->getRawOriginal('start_time'))
        ->and($jeuneDApres->getRawOriginal('end_time'))->toBe('2026-03-11 05:00:00')
        ->and($jeuneDApres->getRawOriginal('end_time'))->toBe($jeuneDAvant->getRawOriginal('end_time'));
});
