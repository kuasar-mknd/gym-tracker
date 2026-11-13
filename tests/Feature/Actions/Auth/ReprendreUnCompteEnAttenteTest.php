<?php

declare(strict_types=1);

use App\Actions\Auth\ReprendreUnCompteEnAttente;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Laravel\Socialite\Two\User as SocialiteUser;

it('remplace le mot de passe de l’inscrit par un mot de passe de quarante caractères', function (): void {
    $compte = User::factory()->enAttente()->create();
    $fournisseur = new SocialiteUser();
    $fournisseur->map(['id' => 'abc', 'name' => 'Camille', 'avatar' => null]);
    $longueurs = [];

    Hash::shouldReceive('isHashed')->andReturn(false);
    Hash::shouldReceive('make')->andReturnUsing(function (string $valeur) use (&$longueurs): string {
        $longueurs[] = strlen($valeur);

        return 'empreinte';
    });

    resolve(ReprendreUnCompteEnAttente::class)->execute($compte, 'google', 'abc', $fournisseur);

    expect($longueurs)->toBe([40]);
});
