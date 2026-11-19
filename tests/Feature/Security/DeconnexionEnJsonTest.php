<?php

declare(strict_types=1);

use App\Models\User;
use Tests\Support\Appareil;

/*
 * La déconnexion en XHR (`Accept: application/json`, `X-Requested-With`) ferme
 * la session comme le formulaire : le 401 que le harnais rendait sur
 * `POST /logout` ne se reproduit pas, la route n'a pas de défaut.
 */
it('ferme la session sur un POST /logout en JSON', function (): void {
    $compte = User::factory()->create();
    $appareil = new Appareil();
    $appareil->envoyer('POST', '/login', ['email' => $compte->email, 'password' => 'password'])
        ->assertRedirect(route('dashboard'));
    $entetes = ['Accept' => 'application/json', 'X-Requested-With' => 'XMLHttpRequest'];

    $appareil->envoyer('POST', '/logout', [], $entetes)->assertRedirect('/');

    $appareil->envoyer('GET', '/dashboard', [], $entetes)->assertUnauthorized();
    $this->assertGuest();
});
