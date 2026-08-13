<?php

declare(strict_types=1);

use App\Models\Exercise;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

use function Pest\Laravel\actingAs;

/**
 * The library is served from a cache keyed by user, so every write has to drop
 * it or the next render replays the list from before the write — the exercise
 * created a second ago is missing, the one just deleted is still there, and
 * only a reload clears it.
 *
 * The first request in each test is what makes these bite: it warms the cache.
 * Without it the write happens against an empty cache and the assertion passes
 * whether or not anything is ever invalidated.
 *
 * Removing the invalidation — from the model's saved/deleted hooks, the action
 * and the controller — fails both of these. The browser test alongside cannot
 * cover this half: Dusk runs on the array store, which does not survive between
 * requests, so no cache staleness can exist there to catch.
 */
it('serves a newly created exercise on the next render', function (): void {
    $user = User::factory()->create();

    // Warms the cache; the assertion below is about what replaces it.
    actingAs($user)->get(route('exercises.index'))
        ->assertInertia(fn (Assert $page): Assert => $page->has('exercises', 0));

    actingAs($user)->post(route('exercises.store'), [
        'name' => 'Développé incliné',
        'type' => 'strength',
        'category' => 'Pectoraux',
    ])->assertSessionHasNoErrors()->assertRedirect();

    actingAs($user)->get(route('exercises.index'))
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('Exercises/Index')
            ->has('exercises', 1)
            ->where('exercises.0.name', 'Développé incliné')
        );
});

it('drops a deleted exercise from the next render', function (): void {
    $user = User::factory()->create();

    // Ordered by category then name, so which one survives is not a guess.
    $doomed = Exercise::factory()->for($user)->create([
        'name' => 'Rowing barre',
        'category' => 'Dos',
    ]);
    Exercise::factory()->for($user)->create([
        'name' => 'Squat',
        'category' => 'Jambes',
    ]);

    actingAs($user)->get(route('exercises.index'))
        ->assertInertia(fn (Assert $page): Assert => $page->has('exercises', 2));

    actingAs($user)->delete(route('exercises.destroy', $doomed))
        ->assertSessionHasNoErrors()->assertRedirect();

    actingAs($user)->get(route('exercises.index'))
        ->assertInertia(fn (Assert $page): Assert => $page
            ->has('exercises', 1)
            ->where('exercises.0.name', 'Squat')
        );
});
