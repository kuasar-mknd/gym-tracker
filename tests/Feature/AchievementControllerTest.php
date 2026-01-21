<?php

use App\Models\Achievement;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

test('authenticated user can view achievements page', function (): void {
    $user = User::factory()->create();

    // Create achievements
    $unlockedAchievement = Achievement::factory()->create([
        'name' => 'Unlocked Badge',
        'slug' => 'unlocked-badge',
    ]);

    $lockedAchievement = Achievement::factory()->create([
        'name' => 'Locked Badge',
        'slug' => 'locked-badge',
    ]);

    // Attach unlocked achievement to user
    $user->achievements()->attach($unlockedAchievement, ['achieved_at' => now()]);

    actingAs($user)
        ->get(route('achievements.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Achievements/Index')
            ->has('achievements', 2)
            ->has('summary', fn (Assert $json) => $json
                ->where('total', 2)
                ->where('unlocked', 1)
            )
            ->where('achievements.0.id', $unlockedAchievement->id)
            ->where('achievements.0.is_unlocked', true)
            ->where('achievements.1.id', $lockedAchievement->id)
            ->where('achievements.1.is_unlocked', false)
        );
});

test('unauthenticated user cannot view achievements page', function (): void {
    get(route('achievements.index'))
        ->assertRedirect(route('login'));
});

test('achievements list returns correct structure', function (): void {
    $user = User::factory()->create();
    $achievement = Achievement::factory()->create();

    actingAs($user)
        ->get(route('achievements.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('achievements.0', fn (Assert $json) => $json
                ->where('id', $achievement->id)
                ->where('slug', $achievement->slug)
                ->where('name', $achievement->name)
                ->where('description', $achievement->description)
                ->where('icon', $achievement->icon)
                ->where('category', $achievement->category)
                ->where('is_unlocked', false)
                ->where('unlocked_at', null)
            )
        );
});

test('achievements are ordered or retrieved correctly', function (): void {
    // The controller currently retrieves Achievement::all(), so order might depend on DB insertion or default ID sort.
    // The controller maps them.

    $user = User::factory()->create();
    Achievement::factory()->count(5)->create();

    actingAs($user)
        ->get(route('achievements.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('achievements', 5)
        );
});
