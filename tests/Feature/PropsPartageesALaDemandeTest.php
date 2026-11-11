<?php

declare(strict_types=1);

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Achievement;
use App\Models\Exercise;
use App\Models\User;
use App\Models\Workout;
use App\Notifications\AchievementUnlocked;
use Illuminate\Cache\Events\CacheHit;
use Illuminate\Cache\Events\CacheMissed;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use Symfony\Component\HttpFoundation\Response;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

/*
 * `auth` et `flash` ne se calculent que pour une réponse qui les porte
 * (#2148, étape 3b).
 *
 * `share()` rendait des valeurs : chaque requête du groupe `web` lisait le
 * compte des notifications non lues, le dernier badge et la séance en cours
 * (`auth.user`, trois lectures du cache au moins), redirections d'écriture,
 * routes JSON et réponses partielles comprises, pour les jeter ensuite. Inertia
 * ne résout une fermeture que si la prop part dans la réponse.
 *
 * Les lectures se suivent par les évènements du cache (`CacheHit`,
 * `CacheMissed`), sous les clés des trois services ; le premier cas vérifie
 * que la page complète les lit bien, sans quoi les autres passeraient pour
 * rien si une clé changeait de nom.
 */

/**
 * Un compte qui a une séance en cours et un badge non lu : chaque lecture de
 * `auth.user` a de quoi lire.
 */
function propsPartageesCompteOccupe(): User
{
    $compte = User::factory()->create();
    Workout::factory()->create(['user_id' => $compte->id, 'started_at' => now()->subHour(), 'ended_at' => null]);
    $compte->notify(new AchievementUnlocked(Achievement::factory()->create()));

    return $compte;
}

/**
 * Les clés que lit `auth.user` : non lus, dernier badge, séance en cours
 * (`NotificationService`, `ActiveWorkoutService`).
 *
 * @return list<string>
 */
function propsPartageesClesDuCompte(User $compte): array
{
    return [
        "user:{$compte->id}:unread_notifications_count",
        "user:{$compte->id}:latest_achievement",
        "user_active_workout_v3_{$compte->id}",
    ];
}

/**
 * Les clés de `auth.user` que l'action lit dans le cache.
 *
 * @param  callable(): TestResponse<Response>  $action
 * @return list<string>
 */
function propsPartageesClesLuesPendant(User $compte, callable $action): array
{
    $lues = [];
    $cles = propsPartageesClesDuCompte($compte);

    Event::listen([CacheHit::class, CacheMissed::class], static function (CacheHit|CacheMissed $lecture) use (&$lues, $cles): void {
        if (in_array($lecture->key, $cles, true)) {
            $lues[] = $lecture->key;
        }
    });

    $action();

    return array_values(array_unique($lues));
}

/**
 * Les en-têtes d'une visite d'Inertia.
 *
 * @param  array<string, string>  $enPlus
 * @return array<string, string>
 */
function propsPartageesEntetesInertia(array $enPlus = []): array
{
    return [
        'X-Inertia' => 'true',
        'X-Requested-With' => 'XMLHttpRequest',
        'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(request()),
        ...$enPlus,
    ];
}

it('lit le compte des non-lus, le dernier badge et la séance en cours pour une page complète (témoin)', function (): void {
    $compte = propsPartageesCompteOccupe();

    $lues = propsPartageesClesLuesPendant(
        $compte,
        fn (): TestResponse => actingAs($compte)->get(route('calendar.index'), propsPartageesEntetesInertia())->assertOk(),
    );

    expect($lues)->toEqualCanonicalizing(propsPartageesClesDuCompte($compte));
});

it('ne lit rien de `auth.user` pour une redirection d’écriture', function (): void {
    $compte = propsPartageesCompteOccupe();

    $lues = propsPartageesClesLuesPendant(
        $compte,
        fn (): TestResponse => actingAs($compte)
            ->from(route('dashboard'))
            ->patch(route('profile.rest-timer.update'), ['auto_rest_timer' => false], propsPartageesEntetesInertia())
            ->assertRedirect(route('dashboard')),
    );

    expect($lues)->toBe([]);
});

it('ne lit rien de `auth.user` pour une route JSON du groupe web', function (): void {
    $compte = propsPartageesCompteOccupe();
    $exercice = Exercise::factory()->create(['user_id' => $compte->id]);

    $lues = propsPartageesClesLuesPendant(
        $compte,
        fn (): TestResponse => actingAs($compte)
            ->getJson(route('stats.exercise', ['exercise' => $exercice->id]))
            ->assertOk(),
    );

    expect($lues)->toBe([]);
});

it('ne lit rien de `auth.user` pour une réponse partielle qui ne le demande pas', function (): void {
    $compte = propsPartageesCompteOccupe();

    $lues = propsPartageesClesLuesPendant(
        $compte,
        fn (): TestResponse => actingAs($compte)
            ->get(route('calendar.index'), propsPartageesEntetesInertia([
                'X-Inertia-Partial-Component' => 'Calendar/Index',
                'X-Inertia-Partial-Data' => 'workouts',
            ]))
            ->assertOk()
            ->assertJsonMissingPath('props.auth'),
    );

    expect($lues)->toBe([]);
});

it('lit `auth.user` pour une réponse partielle qui le demande', function (): void {
    $compte = propsPartageesCompteOccupe();

    $lues = propsPartageesClesLuesPendant(
        $compte,
        fn (): TestResponse => actingAs($compte)
            ->get(route('calendar.index'), propsPartageesEntetesInertia([
                'X-Inertia-Partial-Component' => 'Calendar/Index',
                'X-Inertia-Partial-Data' => 'auth',
            ]))
            ->assertOk()
            ->assertJsonPath('props.auth.user.id', $compte->id),
    );

    expect($lues)->toEqualCanonicalizing(propsPartageesClesDuCompte($compte));
});

/*
 * `auth` reste une prop partagée de toute page, invité compris : la garde de
 * l'historique de chaque onglet y lit le titulaire de la page
 * (`.ai/rules/middleware.md`, « Les pages d'un compte »).
 */
it('donne à un invité `auth.user` nul sur chaque page', function (string $route): void {
    get(route($route))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->has('auth')
            ->where('auth.user', null)
            ->where('flash.success', null)
            ->where('flash.error', null));
})->with(['login', 'register', 'password.request']);

it('donne au compte connecté son `auth.user` et le flash en attente', function (): void {
    $compte = propsPartageesCompteOccupe();

    actingAs($compte)
        ->withSession(['success' => 'Séance enregistrée.'])
        ->get(route('calendar.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->where('auth.user.id', $compte->id)
            ->where('auth.user.unread_notifications_count', 1)
            ->where('auth.user.active_workout.id', $compte->workouts()->firstOrFail()->id)
            ->where('flash.success', 'Séance enregistrée.')
            ->where('flash.error', null));
});
