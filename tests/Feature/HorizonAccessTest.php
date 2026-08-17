<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Gate;

/*
 * La liste des adresses autorisees etait litteralement vide :
 * `in_array($user?->email, [], true)`. Horizon tournait donc derriere une porte
 * que personne ne pouvait ouvrir — un dispositif d'observation inconsultable ne
 * vaut pas mieux que pas de dispositif (#1443).
 *
 * PHPStan le voyait, d'ailleurs : l'appel etait signale comme toujours faux, et
 * l'avertissement dormait dans le baseline. Le figer avait transforme un defaut
 * en decor.
 */
it('n’ouvre Horizon à personne quand aucune adresse n’est configurée', function (): void {
    config(['horizon.allowed_emails' => '']);

    expect(Gate::forUser(User::factory()->create())->allows('viewHorizon'))->toBeFalse();
});

it('ouvre Horizon aux adresses configurées, et à elles seules', function (): void {
    $autorise = User::factory()->create(['email' => 'ops@example.org']);
    $autre = User::factory()->create(['email' => 'quelquun@example.org']);

    config(['horizon.allowed_emails' => ' ops@example.org , second@example.org ']);

    expect(Gate::forUser($autorise)->allows('viewHorizon'))->toBeTrue()
        ->and(Gate::forUser($autre)->allows('viewHorizon'))->toBeFalse();
});

/**
 * Un visiteur non authentifie n'est pas « une adresse absente de la liste » :
 * c'est l'absence d'utilisateur, et l'ancienne ecriture la traitait par un
 * `?->` qui rendait null — compare a une liste vide, donc toujours faux par
 * accident plutot que par intention.
 */
it('n’ouvre Horizon à personne quand nul n’est authentifié', function (): void {
    config(['horizon.allowed_emails' => 'ops@example.org']);

    expect(Gate::forUser(null)->allows('viewHorizon'))->toBeFalse();
});
