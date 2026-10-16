<?php

declare(strict_types=1);

use App\Models\User;
use App\Services\Stats\ClesDeStats;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Inertia\Testing\AssertableInertia as Assert;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

test('authenticated user can view stats dashboard', function (): void {
    $user = User::factory()->create();

    actingAs($user)
        ->get(route('stats.index'))
        ->assertStatus(200)
        ->assertInertia(
            fn (Assert $page): Assert => $page
                ->component('Stats/Index')
                ->has('latestWeight')
                ->has('weightChange')
                ->has('bodyFat')
                ->has('exercises')
                ->has('selectedPeriod')
        );
});

test('stats dashboard loads deferred performance and body stats props', function (): void {
    $user = User::factory()->create();

    actingAs($user)
        ->get(route('stats.index'))
        ->assertStatus(200)
        ->assertInertia(
            fn (Assert $page): Assert => $page
                ->component('Stats/Index')
                ->loadDeferredProps(
                    fn (Assert $page): Assert => $page
                        ->has('deferredData.performance')
                        ->has('deferredData.body')
                )
        );
});

test('unauthenticated user cannot view stats dashboard', function (): void {
    get(route('stats.index'))
        ->assertRedirect(route('login'));
});

test('stats dashboard accepts invalid period and passes it through to view while defaulting internally to 30 days', function (): void {
    $user = User::factory()->create();

    actingAs($user)
        ->get(route('stats.index', ['period' => 'invalid-period']))
        ->assertStatus(200)
        ->assertInertia(
            fn (Assert $page): Assert => $page
                ->component('Stats/Index')
                ->where('selectedPeriod', 'invalid-period')
        );
});

/**
 * La page des statistiques ouverte avec cette chaîne de requête, ses props
 * différées chargées : la période affichée, et le nombre de jours que la vue
 * « performance » a calculés, lu dans la clef sous laquelle elle les garde.
 *
 * @return array{periode: mixed, jours: list<int>}
 */
function statsPeriodeLue(string $chaineDeRequete): array
{
    Carbon::setTestNow('2026-10-16 10:00:00');
    $compte = User::factory()->create();
    $periode = null;

    actingAs($compte)
        ->get(route('stats.index').'?'.$chaineDeRequete)
        ->assertOk()
        ->assertInertia(
            function (Assert $page) use (&$periode): Assert {
                $periode = data_get($page->toArray(), 'props.selectedPeriod');

                return $page->component('Stats/Index')->loadDeferredProps(
                    fn (Assert $differees): Assert => $differees->has('deferredData.performance')
                );
            }
        );

    $jours = array_values(array_filter(
        [7, 30, 90, 365],
        fn (int $nombre): bool => Cache::has(ClesDeStats::seances($compte, "performance_overview.{$nombre}.2026-10")),
    ));

    return ['periode' => $periode, 'jours' => $jours];
}

test('une période qui n’est pas une chaîne non vide donne trente jours, sans erreur', function (string $chaineDeRequete): void {
    expect(statsPeriodeLue($chaineDeRequete))->toBe(['periode' => '30j', 'jours' => [30]]);
})->with([
    'tableau' => 'period[]=7j',
    'tableau imbriqué' => 'period[a][b]=7j',
    'chaîne vide' => 'period=',
]);

test('une période en chaîne se lit comme avant, période inconnue comprise', function (string $chaineDeRequete, string $periode, int $jours): void {
    expect(statsPeriodeLue($chaineDeRequete))->toBe(['periode' => $periode, 'jours' => [$jours]]);
})->with([
    'sept jours' => ['period=7j', '7j', 7],
    'un an' => ['period=1a', '1a', 365],
    'inconnue' => ['period=inconnue', 'inconnue', 30],
    'zéro, une chaîne que PHP tient pour fausse' => ['period=0', '0', 30],
]);

test('authenticated user can fetch progress for their exercise', function (): void {
    $user = User::factory()->create();
    $exercise = \App\Models\Exercise::factory()->create(['user_id' => $user->id]);

    actingAs($user)
        ->get(route('stats.exercise', $exercise))
        ->assertStatus(200)
        ->assertJsonStructure(['progress']);
});

test('unauthenticated user cannot fetch exercise progress', function (): void {
    $exercise = \App\Models\Exercise::factory()->create();

    get(route('stats.exercise', $exercise))
        ->assertRedirect(route('login'));
});

test('user gets 404 for non-existent exercise progress', function (): void {
    $user = User::factory()->create();

    actingAs($user)
        ->get(route('stats.exercise', 9999))
        ->assertStatus(404);
});

test('user cannot fetch progress for another users exercise', function (): void {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $exercise = \App\Models\Exercise::factory()->create(['user_id' => $otherUser->id]);

    actingAs($user)
        ->get(route('stats.exercise', $exercise))
        ->assertNotFound()
        // Un 404 qui rendrait quand meme la progression serait pire qu'un 403.
        ->assertDontSee('progress');
});
