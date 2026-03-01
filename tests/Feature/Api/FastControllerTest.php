<?php

declare(strict_types=1);

use App\Models\Fast;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->user = User::factory()->create();
    Sanctum::actingAs($this->user);
});

describe('FastController API', function (): void {
    it('can list user fasts', function (): void {
        Fast::factory()->count(3)->create(['user_id' => $this->user->id]);
        $otherUserFast = Fast::factory()->create();

        $response = $this->getJson(route('api.v1.fasts.index'));

        $response->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonStructure([
                'data' => [
                    '*' => ['id', 'start_time', 'end_time', 'target_duration_minutes', 'type', 'status'],
                ],
                'links',
                'meta',
            ]);

        $this->assertDatabaseHas('fasts', ['id' => $otherUserFast->id]);

        $responseData = $response->json('data');
        $ids = array_column($responseData, 'id');
        expect($ids)->not->toContain($otherUserFast->id);
    });

    it('can create a fast', function (): void {
        $data = [
            'start_time' => now()->toIso8601String(),
            'target_duration_minutes' => 960, // 16 hours
            'type' => '16:8',
        ];

        $response = $this->postJson(route('api.v1.fasts.store'), $data);

        $response->assertCreated()
            ->assertJsonPath('data.target_duration_minutes', 960)
            ->assertJsonPath('data.type', '16:8')
            ->assertJsonPath('data.status', 'active');

        $this->assertDatabaseHas('fasts', [
            'user_id' => $this->user->id,
            'target_duration_minutes' => 960,
            'type' => '16:8',
            'status' => 'active',
        ]);
    });

    it('returns validation error if required fields are missing on store', function (): void {
        $response = $this->postJson(route('api.v1.fasts.store'), []);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['start_time', 'target_duration_minutes', 'type']);
    });

    it('returns validation error if user already has an active fast', function (): void {
        Fast::factory()->create([
            'user_id' => $this->user->id,
            'status' => 'active',
        ]);

        $data = [
            'start_time' => now()->toIso8601String(),
            'target_duration_minutes' => 960,
            'type' => '16:8',
        ];

        $response = $this->postJson(route('api.v1.fasts.store'), $data);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['base']);

        expect($response->json('errors.base.0'))->toBe('Un jeûne est déjà en cours.');
    });

    it('can show a specific fast', function (): void {
        $fast = Fast::factory()->create(['user_id' => $this->user->id]);

        $response = $this->getJson(route('api.v1.fasts.show', $fast));

        $response->assertOk()
            ->assertJsonPath('data.id', $fast->id);
    });

    it('cannot show a fast belonging to another user', function (): void {
        $otherUserFast = Fast::factory()->create();

        $response = $this->getJson(route('api.v1.fasts.show', $otherUserFast));

        $response->assertForbidden();
    });

    it('can update a fast', function (): void {
        $fast = Fast::factory()->create([
            'user_id' => $this->user->id,
            'status' => 'active',
        ]);

        $updateData = [
            'status' => 'completed',
            'end_time' => now()->addHours(16)->toIso8601String(),
        ];

        $response = $this->putJson(route('api.v1.fasts.update', $fast), $updateData);

        $response->assertOk()
            ->assertJsonPath('data.status', 'completed');

        $this->assertDatabaseHas('fasts', [
            'id' => $fast->id,
            'status' => 'completed',
        ]);
    });

    it('returns validation error on invalid update data', function (): void {
        $fast = Fast::factory()->create(['user_id' => $this->user->id]);

        $response = $this->putJson(route('api.v1.fasts.update', $fast), [
            'status' => 'invalid_status',
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['status']);
    });

    it('cannot update a fast belonging to another user', function (): void {
        $otherUserFast = Fast::factory()->create();

        $response = $this->putJson(route('api.v1.fasts.update', $otherUserFast), [
            'status' => 'completed',
        ]);

        $response->assertForbidden();
    });

    it('can delete a fast', function (): void {
        $fast = Fast::factory()->create(['user_id' => $this->user->id]);

        $response = $this->deleteJson(route('api.v1.fasts.destroy', $fast));

        $response->assertNoContent();

        $this->assertDatabaseMissing('fasts', ['id' => $fast->id]);
    });

    it('cannot delete a fast belonging to another user', function (): void {
        $otherUserFast = Fast::factory()->create();

        $response = $this->deleteJson(route('api.v1.fasts.destroy', $otherUserFast));

        $response->assertForbidden();

        $this->assertDatabaseHas('fasts', ['id' => $otherUserFast->id]);
    });
});
