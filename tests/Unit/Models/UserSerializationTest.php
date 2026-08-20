<?php

declare(strict_types=1);

use App\Models\User;

/*
 * Le second test de ce fichier verifiait que `User` porte le trait
 * `TwoFactorAuthenticatable` de Fortify. Il est parti avec Fortify (#1355) :
 * la 2FA cote application utilisateur etait activee en configuration sans
 * qu'aucune route ne l'expose, et celle du back-office, qui fonctionne, est
 * celle de Filament et ne doit rien a Fortify.
 *
 * Ce qui reste vaut pour soi : un mot de passe et un jeton de session n'ont
 * rien a faire dans une reponse serialisee.
 */
it('declares sensitive attributes as hidden', function (): void {
    /** @var array<string, mixed> $defaults */
    $defaults = new ReflectionClass(User::class)->getDefaultProperties();
    $hidden = $defaults['hidden'] ?? [];

    expect($hidden)
        ->toContain('password')
        ->toContain('remember_token');
});
