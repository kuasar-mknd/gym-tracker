<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Models\WaterLog;

beforeEach(function (): void {
    $this->user = User::factory()->create();
});

it('can display the water tracker page', function (): void {
    $response = $this->actingAs($this->user)->get(route('tools.water.index'));

    $response->assertStatus(200);
});

it('can store a new water log entry', function (): void {
    $amount = 500;
    $consumedAt = now()->toDateTimeString();

    $response = $this->actingAs($this->user)->post(route('tools.water.store'), [
        'amount' => $amount,
        'consumed_at' => $consumedAt,
    ]);

    $response->assertRedirect();
    $this->assertDatabaseHas('water_logs', [
        'user_id' => $this->user->id,
        'amount' => $amount,
        'consumed_at' => $consumedAt,
    ]);
});

it('fails validation if consumed_at is not provided', function (): void {
    $amount = 300;

    $response = $this->actingAs($this->user)->post(route('tools.water.store'), [
        'amount' => $amount,
    ]);

    $response->assertSessionHasErrors('consumed_at');
});

it('validates amount and consumed_at when storing a water log', function (): void {
    $response = $this->actingAs($this->user)->post(route('tools.water.store'), []);

    $response->assertStatus(302);
    $response->assertSessionHasErrors(['amount', 'consumed_at']);
});

it('can delete a water log entry', function (): void {
    $waterLog = WaterLog::factory()->create([
        'user_id' => $this->user->id,
    ]);

    $response = $this->actingAs($this->user)->delete(route('tools.water.destroy', $waterLog));

    $response->assertRedirect();
    $this->assertDatabaseMissing('water_logs', [
        'id' => $waterLog->id,
    ]);
});

it('cannot delete another users water log entry', function (): void {
    $otherUser = User::factory()->create();
    $waterLog = WaterLog::factory()->create([
        'user_id' => $otherUser->id,
    ]);

    $response = $this->actingAs($this->user)->delete(route('tools.water.destroy', $waterLog));

    $response->assertForbidden();
    $this->assertDatabaseHas('water_logs', [
        'id' => $waterLog->id,
    ]);
});

it('redirects unauthenticated users', function (): void {
    $response = $this->get(route('tools.water.index'));
    $response->assertRedirect(route('login'));

    $response = $this->post(route('tools.water.store'), []);
    $response->assertRedirect(route('login'));

    $waterLog = WaterLog::factory()->create();
    $response = $this->delete(route('tools.water.destroy', $waterLog));
    $response->assertRedirect(route('login'));
});
