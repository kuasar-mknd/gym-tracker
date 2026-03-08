<?php

declare(strict_types=1);

use App\Models\Admin;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

test('regular user cannot list admins', function (): void {
    $user = User::factory()->create();
    Admin::factory()->count(3)->create();

    Sanctum::actingAs($user);

    $response = $this->getJson(route('api.v1.admins.index'));

    // If it's vulnerable, it will return 200 OK.
    // If it's secure, it should return 403 Forbidden.
    $response->assertForbidden();
});

test('regular user cannot list users', function (): void {
    $user = User::factory()->create();
    User::factory()->count(3)->create();

    Sanctum::actingAs($user);

    $response = $this->getJson(route('api.v1.users.index'));

    // If it's vulnerable, it will return 200 OK.
    // If it's secure, it should return 403 Forbidden.
    $response->assertForbidden();
});
