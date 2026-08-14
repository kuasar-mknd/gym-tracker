<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Models\User;
use Laravel\Sanctum\Sanctum;

test('StoreUserRequest enforces password defaults', function (): void {
    $admin = User::factory()->create();
    Sanctum::actingAs($admin);

    // Mocking an admin because UserController::store requires 'create' authorization.
    // In this app, admins are usually handled by filament but here we are testing the API.
    // The UserPolicy requires 'Create:User' permission.
    // For simplicity in this test, we just want to see if the request validation fails on a weak password.

    $response = $this->postJson(route('api.v1.users.store'), [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'short',
    ]);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors(['password']);
});

/*
 * L'utilisateur se met a jour lui-meme, la ou ce test faisait modifier un
 * inconnu par un « admin » qui n'avait aucun droit d'administration — un
 * User::factory() nu. La regle de mot de passe n'etait donc jamais atteinte au
 * titre de ce qu'elle verifie : la reponse 422 venait d'une validation qui
 * tournait avant un refus d'autorisation, et ce refus repond maintenant 404
 * pour ne pas confirmer l'existence du compte vise (#1418).
 *
 * Le test porte a nouveau sur ce que son nom annonce.
 */
test('UpdateUserRequest enforces password defaults', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $response = $this->patchJson(route('api.v1.users.update', $user), [
        'password' => 'short',
    ]);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors(['password']);
});
