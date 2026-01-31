<?php

declare(strict_types=1);

use App\Models\BodyPartMeasurement;
use App\Models\User;

test('body parts index page is displayed', function (): void {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get(route('body-parts.index'));

    $response->assertOk();
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

test('index page correctly calculates latest and diff for parts', function (): void {
    $user = User::factory()->create();

    // Chest: 3 measurements
    BodyPartMeasurement::factory()->create([
        'user_id' => $user->id,
        'part' => 'Chest',
        'value' => 90.0,
        'measured_at' => '2023-01-01',
    ]);
    BodyPartMeasurement::factory()->create([
        'user_id' => $user->id,
        'part' => 'Chest',
        'value' => 95.0,
        'measured_at' => '2023-01-02',
    ]);
    BodyPartMeasurement::factory()->create([
        'user_id' => $user->id,
        'part' => 'Chest',
        'value' => 100.0,
        'measured_at' => '2023-01-03',
    ]);

    // Biceps: 1 measurement
    BodyPartMeasurement::factory()->create([
        'user_id' => $user->id,
        'part' => 'Biceps',
        'value' => 40.0,
        'measured_at' => '2023-01-03',
    ]);

    $response = $this->actingAs($user)->get(route('body-parts.index'));

    $response->assertOk();

    $measurements = $response->inertiaProps('latestMeasurements');

    expect($measurements)->toHaveCount(2);

    $chest = collect($measurements)->firstWhere('part', 'Chest');
    expect($chest)->not->toBeNull();
    expect($chest['current'])->toBe('100.00');
    expect($chest['diff'])->toEqual(5.0);
    expect($chest['date'])->toBe('2023-01-03');

    $biceps = collect($measurements)->firstWhere('part', 'Biceps');
    expect($biceps)->not->toBeNull();
    expect($biceps['current'])->toBe('40.00');
    expect($biceps['diff'])->toEqual(0);
});
