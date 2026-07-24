<?php

declare(strict_types=1);

use App\Models\User;
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
        ->assertStatus(403);
});
