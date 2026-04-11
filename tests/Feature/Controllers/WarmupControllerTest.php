<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\WarmupPreference;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\assertDatabaseHas;
use function Pest\Laravel\get;
use function Pest\Laravel\post;

uses(RefreshDatabase::class);

describe('WarmupController', function (): void {
    it('renders warmup calculator with default preferences for authenticated users', function (): void {
        $user = User::factory()->create();

        actingAs($user)
            ->get(route('tools.warmup'))
            ->assertStatus(200)
            ->assertInertia(fn (Assert $page) => $page
                ->component('Tools/WarmupCalculator')
                ->has('preference', fn (Assert $page) => $page
                    ->where('bar_weight', 20)
                    ->where('rounding_increment', 2.5)
                    ->has('steps', 4)
                    ->etc()
                )
            );
    });

    it('renders warmup calculator with existing preferences for authenticated users', function (): void {
        $user = User::factory()->create();
        WarmupPreference::factory()->create([
            'user_id' => $user->id,
            'bar_weight' => 15,
            'rounding_increment' => 1,
        ]);

        actingAs($user)
            ->get(route('tools.warmup'))
            ->assertStatus(200)
            ->assertInertia(fn (Assert $page) => $page
                ->component('Tools/WarmupCalculator')
                ->has('preference', fn (Assert $page) => $page
                    ->where('bar_weight', 15)
                    ->where('rounding_increment', 1)
                    ->etc()
                )
            );
    });

    it('creates a new warmup preference when updated', function (): void {
        $user = User::factory()->create();

        $data = [
            'bar_weight' => 15,
            'rounding_increment' => 1.25,
            'steps' => [
                ['percent' => 50, 'reps' => 8, 'label' => 'Step 1'],
            ],
        ];

        actingAs($user)
            ->post(route('tools.warmup.update'), $data)
            ->assertRedirect()
            ->assertSessionHas('success', 'Préférences de récupération sauvegardées.');

        assertDatabaseHas('warmup_preferences', [
            'user_id' => $user->id,
            'bar_weight' => 15,
            'rounding_increment' => 1.25,
        ]);
    });

    it('modifies an existing warmup preference', function (): void {
        $user = User::factory()->create();
        $preference = WarmupPreference::factory()->create([
            'user_id' => $user->id,
            'bar_weight' => 20,
        ]);

        $data = [
            'bar_weight' => 25,
            'rounding_increment' => 5,
            'steps' => [
                ['percent' => 50, 'reps' => 5, 'label' => 'New Step'],
            ],
        ];

        actingAs($user)
            ->post(route('tools.warmup.update'), $data)
            ->assertRedirect()
            ->assertSessionHas('success', 'Préférences de récupération sauvegardées.');

        assertDatabaseHas('warmup_preferences', [
            'id' => $preference->id,
            'bar_weight' => 25,
            'rounding_increment' => 5,
        ]);
    });

    it('fails validation with invalid inputs', function (): void {
        $user = User::factory()->create();

        $data = [
            'bar_weight' => -5,
            'rounding_increment' => 'invalid_string',
            'steps' => 'not_an_array',
        ];

        actingAs($user)
            ->post(route('tools.warmup.update'), $data)
            ->assertStatus(302)
            ->assertSessionHasErrors(['bar_weight', 'rounding_increment', 'steps']);
    });

    it('fails validation with invalid steps inputs', function (): void {
        $user = User::factory()->create();

        $data = [
            'bar_weight' => 20,
            'rounding_increment' => 2.5,
            'steps' => [
                ['percent' => 150, 'reps' => -1],
            ],
        ];

        actingAs($user)
            ->post(route('tools.warmup.update'), $data)
            ->assertStatus(302)
            ->assertSessionHasErrors(['steps.0.percent', 'steps.0.reps']);
    });

    it('prevents modifying others preferences by ensuring user ID is respected', function (): void {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        $preference = WarmupPreference::factory()->create([
            'user_id' => $otherUser->id,
            'bar_weight' => 20,
        ]);

        $data = [
            'bar_weight' => 25,
            'rounding_increment' => 5,
            'steps' => [
                ['percent' => 50, 'reps' => 5, 'label' => 'New Step'],
            ],
        ];

        actingAs($user)
            ->post(route('tools.warmup.update'), $data)
            ->assertRedirect();

        // The other user's preference should be unchanged
        assertDatabaseHas('warmup_preferences', [
            'id' => $preference->id,
            'bar_weight' => 20,
        ]);

        // A new preference should be created for $user
        assertDatabaseHas('warmup_preferences', [
            'user_id' => $user->id,
            'bar_weight' => 25,
        ]);
    });

    it('redirects unauthenticated users', function (): void {
        get(route('tools.warmup'))->assertRedirect(route('login'));

        post(route('tools.warmup.update'), [
            'bar_weight' => 20,
            'rounding_increment' => 2.5,
            'steps' => [],
        ])->assertRedirect(route('login'));
    });
});
