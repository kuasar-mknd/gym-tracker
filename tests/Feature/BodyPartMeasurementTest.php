<?php

declare(strict_types=1);

use App\Models\BodyPartMeasurement;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('body parts index page is displayed', function (): void {
    $user = User::factory()->create();

    // Create two measurements for the same part to test diff calculation
    BodyPartMeasurement::factory()->create([
        'user_id' => $user->id,
        'part' => 'Chest',
        'value' => 100.00,
        'measured_at' => now()->subDay(),
    ]);

    BodyPartMeasurement::factory()->create([
        'user_id' => $user->id,
        'part' => 'Chest',
        'value' => 105.00,
        'measured_at' => now(),
    ]);

    $response = $this->actingAs($user)->get(route('body-parts.index'));

    $response->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Measurements/Parts/Index')
            ->has('latestMeasurements', 1, fn (Assert $json) => $json
                ->where('part', 'Chest')
                ->where('current', '105.00')
                ->where('diff', 5)
                ->etc()
            )
            ->has('commonParts')
        );
});

test('can add body part measurement', function (): void {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post(route('body-parts.store'), [
        'part' => 'Chest',
        'value' => 100.5,
        'unit' => 'cm',
        'measured_at' => '2023-01-01',
        'notes' => 'Test note',
    ]);

    $response->assertRedirect();
    $this->assertDatabaseHas('body_part_measurements', [
        'user_id' => $user->id,
        'part' => 'Chest',
        'value' => 100.5,
        'unit' => 'cm',
        'measured_at' => '2023-01-01',
    ]);
});

test('can delete body part measurement', function (): void {
    $user = User::factory()->create();
    $measurement = BodyPartMeasurement::factory()->create([
        'user_id' => $user->id,
    ]);

    $response = $this->actingAs($user)->delete(route('body-parts.destroy', $measurement));

    $response->assertRedirect();
    $this->assertDatabaseMissing('body_part_measurements', [
        'id' => $measurement->id,
    ]);
});

test('cannot delete others body part measurement', function (): void {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $measurement = BodyPartMeasurement::factory()->create([
        'user_id' => $otherUser->id,
    ]);

    $response = $this->actingAs($user)->delete(route('body-parts.destroy', $measurement));

    $response->assertForbidden();
    $this->assertDatabaseHas('body_part_measurements', [
        'id' => $measurement->id,
    ]);
});

test('show page displays history for a part', function (): void {
    $user = User::factory()->create();
    BodyPartMeasurement::factory()->create([
        'user_id' => $user->id,
        'part' => 'Chest',
        'value' => 100,
    ]);

    $response = $this->actingAs($user)->get(route('body-parts.show', 'Chest'));

    $response->assertOk();
});
