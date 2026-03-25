<?php

declare(strict_types=1);

use App\Models\BodyPartMeasurement;
use App\Models\User;
use Inertia\Testing\AssertableInertia;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\assertDatabaseCount;
use function Pest\Laravel\assertDatabaseHas;
use function Pest\Laravel\assertDatabaseMissing;

test('user can view body parts index', function (): void {
    $user = User::factory()->create();
    BodyPartMeasurement::factory()->count(3)->create([
        'user_id' => $user->id,
        'part' => 'Chest',
    ]);

    actingAs($user)
        ->get(route('body-parts.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): \Inertia\Testing\AssertableInertia => $page
            ->component('Measurements/Parts/Index')
            ->has('latestMeasurements')
            ->has('commonParts')
        );
});

test('user can view body part show history', function (): void {
    $user = User::factory()->create();
    BodyPartMeasurement::factory()->count(2)->create([
        'user_id' => $user->id,
        'part' => 'Biceps L',
    ]);

    actingAs($user)
        ->get(route('body-parts.show', 'Biceps L'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): \Inertia\Testing\AssertableInertia => $page
            ->component('Measurements/Parts/Show')
            ->where('part', 'Biceps L')
            ->has('history', 2)
        );
});

test('user is redirected to index if no history exists for a body part on show', function (): void {
    $user = User::factory()->create();

    actingAs($user)
        ->get(route('body-parts.show', 'Unknown Part'))
        ->assertRedirect(route('body-parts.index'));
});

test('user can store a new body part measurement', function (): void {
    $user = User::factory()->create();

    actingAs($user)
        ->post(route('body-parts.store'), [
            'part' => 'Waist',
            'value' => 85.5,
            'unit' => 'cm',
            'measured_at' => now()->format('Y-m-d'),
            'notes' => 'Testing measurement',
        ])
        ->assertRedirect();

    assertDatabaseHas('body_part_measurements', [
        'user_id' => $user->id,
        'part' => 'Waist',
        'value' => 85.5,
        'unit' => 'cm',
        'notes' => 'Testing measurement',
    ]);
});

test('user cannot store a body part measurement with invalid data', function (): void {
    $user = User::factory()->create();

    actingAs($user)
        ->post(route('body-parts.store'), [
            'part' => '', // Invalid part
            'value' => -10, // Invalid value
            'unit' => 'kg', // Invalid unit
            // Missing measured_at
        ])
        ->assertSessionHasErrors(['part', 'value', 'unit', 'measured_at']);

    assertDatabaseCount('body_part_measurements', 0);
});

test('user can delete their own body part measurement', function (): void {
    $user = User::factory()->create();
    $measurement = BodyPartMeasurement::factory()->create([
        'user_id' => $user->id,
    ]);

    actingAs($user)
        ->delete(route('body-parts.destroy', $measurement))
        ->assertRedirect();

    assertDatabaseMissing('body_part_measurements', [
        'id' => $measurement->id,
    ]);
});

test('user cannot delete another users body part measurement', function (): void {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $measurement = BodyPartMeasurement::factory()->create([
        'user_id' => $otherUser->id,
    ]);

    actingAs($user)
        ->delete(route('body-parts.destroy', $measurement))
        ->assertForbidden();

    assertDatabaseHas('body_part_measurements', [
        'id' => $measurement->id,
    ]);
});
