<?php

declare(strict_types=1);

use App\Models\Plate;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

use function Pest\Laravel\actingAs;

test('PlateController@store respects PlatePolicy::create authorization', function (): void {
    $user = User::factory()->create();

    // Mock the 'create' gate for Plate class to return false, overriding the policy
    Gate::before(function ($user, $ability, $arguments) {
        if ($ability === 'create' && isset($arguments[0]) && $arguments[0] === Plate::class) {
            return false;
        }
    });

    // Attempt to store a plate, which should fail with 403 if authorization is checked
    actingAs($user)
        ->post(route('plates.store'), [
            'weight' => 20,
            'quantity' => 2,
        ])
        ->assertForbidden();
});
