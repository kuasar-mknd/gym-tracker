<?php

declare(strict_types=1);

use App\Models\User;

/*
 * `User::ouverts()` et `User::enAttente()` disent en requête ce qu'`estEnAttente()`
 * dit d'un compte chargé : un compte attend tant que `en_attente_depuis` est
 * posée (`AttendLaPreuveDeSonAdresse`). Les deux portées se partagent la table
 * sans reste ni recouvrement, et chaque compte tombe du côté que lui donne
 * `estEnAttente()` : ce que le panneau compte comme un compte ouvert est ce que
 * la connexion laisse entrer (`ConnecterLeCompte`).
 */
it('partage les comptes entre ouverts et en attente, comme estEnAttente()', function (): void {
    $enAttente = User::factory()->enAttente()->create();
    $ouvert = User::factory()->create();
    $jamaisVerifie = User::factory()->unverified()->create();
    $ouvertParUnePreuve = User::factory()->enAttente()->create();
    $ouvertParUnePreuve->markEmailAsVerified();

    expect(User::enAttente()->pluck('id')->all())->toBe([$enAttente->id])
        ->and(User::ouverts()->orderBy('id')->pluck('id')->all())->toBe([$ouvert->id, $jamaisVerifie->id, $ouvertParUnePreuve->id]);

    foreach (User::query()->get() as $compte) {
        expect(User::enAttente()->whereKey($compte->id)->exists())->toBe($compte->estEnAttente())
            ->and(User::ouverts()->whereKey($compte->id)->exists())->toBe(! $compte->estEnAttente());
    }
});
