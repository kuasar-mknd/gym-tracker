<?php

declare(strict_types=1);

use App\Models\Fast;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\delete;
use function Pest\Laravel\get;
use function Pest\Laravel\patch;
use function Pest\Laravel\post;

uses(RefreshDatabase::class);

describe('FastingController', function (): void {
    describe('index', function (): void {
        it('allows a user to view the fasting index', function (): void {
            $user = User::factory()->create();

            actingAs($user)
                ->get(route('tools.fasting.index'))
                ->assertOk()
                ->assertInertia(fn (\Inertia\Testing\AssertableInertia $page): \Inertia\Testing\AssertableInertia => $page
                    ->component('Tools/Fasting/Index')
                );
        });

        it('redirects guests', function (): void {
            get(route('tools.fasting.index'))->assertRedirect(route('login'));
        });
    });

    describe('store', function (): void {
        it('allows a user to start a new fast', function (): void {
            $user = User::factory()->create();

            $payload = [
                'start_time' => now()->toDateTimeString(),
                'target_duration_minutes' => 960,
                'type' => '16:8',
            ];

            actingAs($user)
                ->post(route('tools.fasting.store'), $payload)
                ->assertRedirect()
                ->assertSessionHasNoErrors();

            $this->assertDatabaseHas('fasts', [
                'user_id' => $user->id,
                'target_duration_minutes' => 960,
                'type' => '16:8',
                'status' => 'active',
            ]);
        });

        it('prevents starting a fast when one is already active', function (): void {
            $user = User::factory()->create();
            Fast::factory()->create(['user_id' => $user->id, 'status' => 'active']);

            $payload = [
                'start_time' => now()->toDateTimeString(),
                'target_duration_minutes' => 960,
                'type' => '16:8',
            ];

            actingAs($user)
                ->post(route('tools.fasting.store'), $payload)
                ->assertRedirect()
                ->assertSessionHasErrors(['base']);

            $this->assertDatabaseCount('fasts', 1);
        });

        it('validates required fields', function (): void {
            $user = User::factory()->create();

            actingAs($user)
                ->post(route('tools.fasting.store'), [])
                ->assertRedirect()
                ->assertSessionHasErrors(['start_time', 'target_duration_minutes', 'type']);
        });

        it('redirects guests', function (): void {
            post(route('tools.fasting.store'), [])->assertRedirect(route('login'));
        });
    });

    describe('update', function (): void {
        it('allows a user to update their fast', function (): void {
            $user = User::factory()->create();
            $fast = Fast::factory()->create(['user_id' => $user->id, 'status' => 'active']);

            $payload = [
                'status' => 'completed',
                'end_time' => now()->toDateTimeString(),
            ];

            actingAs($user)
                ->patch(route('tools.fasting.update', $fast), $payload)
                ->assertRedirect()
                ->assertSessionHasNoErrors();

            $this->assertDatabaseHas('fasts', [
                'id' => $fast->id,
                'status' => 'completed',
            ]);
        });

        it('prevents a user from updating another user\'s fast', function (): void {
            $user1 = User::factory()->create();
            $user2 = User::factory()->create();
            $fast = Fast::factory()->create(['user_id' => $user2->id, 'status' => 'active']);

            actingAs($user1)
                ->patch(route('tools.fasting.update', $fast), ['status' => 'completed'])
                ->assertForbidden();
        });

        it('validates update fields', function (): void {
            $user = User::factory()->create();
            $fast = Fast::factory()->create(['user_id' => $user->id]);

            actingAs($user)
                ->patch(route('tools.fasting.update', $fast), ['status' => 'invalid'])
                ->assertRedirect()
                ->assertSessionHasErrors(['status']);
        });

        it('redirects guests', function (): void {
            $fast = Fast::factory()->create();
            patch(route('tools.fasting.update', $fast), [])->assertRedirect(route('login'));
        });
    });

    describe('destroy', function (): void {
        it('allows a user to delete their fast', function (): void {
            $user = User::factory()->create();
            $fast = Fast::factory()->create(['user_id' => $user->id]);

            actingAs($user)
                ->delete(route('tools.fasting.destroy', $fast))
                ->assertRedirect()
                ->assertSessionHasNoErrors();

            $this->assertDatabaseMissing('fasts', ['id' => $fast->id]);
        });

        it('prevents a user from deleting another user\'s fast', function (): void {
            $user1 = User::factory()->create();
            $user2 = User::factory()->create();
            $fast = Fast::factory()->create(['user_id' => $user2->id]);

            actingAs($user1)
                ->delete(route('tools.fasting.destroy', $fast))
                ->assertForbidden();

            $this->assertDatabaseHas('fasts', ['id' => $fast->id]);
        });

        it('redirects guests', function (): void {
            $fast = Fast::factory()->create();
            delete(route('tools.fasting.destroy', $fast))->assertRedirect(route('login'));
        });
    });
});
