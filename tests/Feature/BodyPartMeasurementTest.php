<?php

declare(strict_types=1);

use App\Models\BodyPartMeasurement;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

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

test('index page receives correct latest measurements', function (): void {
    $user = User::factory()->create();

    // Create a previous measurement for Chest (older)
    BodyPartMeasurement::factory()->create([
        'user_id' => $user->id,
        'part' => 'Chest',
        'value' => 95.0,
        'measured_at' => now()->subDays(10),
        'unit' => 'cm',
    ]);

    // Create a latest measurement for Chest (newer)
    BodyPartMeasurement::factory()->create([
        'user_id' => $user->id,
        'part' => 'Chest',
        'value' => 100.5,
        'measured_at' => now(),
        'unit' => 'cm',
    ]);

    // Create a measurement for Biceps with no previous history
    BodyPartMeasurement::factory()->create([
        'user_id' => $user->id,
        'part' => 'Biceps L',
        'value' => 35.0,
        'measured_at' => now(),
        'unit' => 'cm',
    ]);

    $response = $this->actingAs($user)->get(route('body-parts.index'));

    $response->assertOk();

    $response->assertInertia(fn (Assert $page) => $page
        ->component('Measurements/Parts/Index')
        ->has('latestMeasurements', 2)
    );

    $props = $response->inertiaProps();
    $measurements = collect($props['latestMeasurements']);

    $chest = $measurements->firstWhere('part', 'Chest');
    expect($chest)->not->toBeNull();
    // Due to decimal:2 cast, it is string "100.50"
    expect($chest['current'])->toBe('100.50');
    // Diff is calculated as round(100.5 - 95.0, 2) = 5.5
    expect($chest['diff'])->toBe(5.5);

    $biceps = $measurements->firstWhere('part', 'Biceps L');
    expect($biceps)->not->toBeNull();
    expect($biceps['current'])->toBe('35.00');
    expect($biceps['diff'])->toBe(0);
});
