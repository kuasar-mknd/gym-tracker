<?php

declare(strict_types=1);

/*
 * L'inscription etait couverte par les tests HTTP, mais deux reecritures de
 * l'action leur echappaient : creer le compte sans mot de passe, et ne plus
 * annoncer l'inscription.
 */

use App\Actions\CreateUserAction;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;

it('cree le compte avec un mot de passe utilisable', function (): void {
    $user = app(CreateUserAction::class)->execute([
        'name' => 'Ada Lovelace',
        'email' => 'ada@example.test',
        'password' => 'un-mot-de-passe-pose',
    ]);

    expect($user->name)->toBe('Ada Lovelace')
        ->and($user->email)->toBe('ada@example.test');

    /*
     * `users.password` accepte NULL — les comptes crees par Socialite n'en ont
     * pas. Cesser d'ecrire la cle produit donc un compte sans mot de passe,
     * sans la moindre erreur SQL : son proprietaire ne peut plus se connecter
     * par formulaire, et rien ne le signalait.
     */
    expect($user->password)->not->toBeNull();
    expect(Hash::check('un-mot-de-passe-pose', (string) $user->password))->toBeTrue();

    $this->assertDatabaseHas(User::class, [
        'name' => 'Ada Lovelace',
        'email' => 'ada@example.test',
    ]);
});

it('annonce l inscription', function (): void {
    Event::fake([Registered::class]);

    $user = app(CreateUserAction::class)->execute([
        'name' => 'Ada Lovelace',
        'email' => 'ada@example.test',
        'password' => 'un-mot-de-passe-pose',
    ]);

    /*
     * C'est cet evenement qui declenche l'envoi du courriel de verification.
     * Le supprimer ne cassait aucun test : le compte etait cree, et personne ne
     * recevait jamais son lien de verification.
     */
    Event::assertDispatched(
        Registered::class,
        fn (Registered $event): bool => $event->user->getAuthIdentifier() === $user->id,
    );
});

it('cree le compte en attente de la preuve de son adresse', function (): void {
    $this->freezeSecond();

    $user = app(CreateUserAction::class)->execute([
        'name' => 'Ada Lovelace',
        'email' => 'ada@example.test',
        'password' => 'un-mot-de-passe-pose',
    ]);

    /*
     * L'inscription ne prouve pas l'adresse : le compte ne se connecte qu'une
     * fois le lien envoye a son adresse suivi (`AttendLaPreuveDeSonAdresse`).
     */
    expect($user->estEnAttente())->toBeTrue()
        ->and($user->refresh()->en_attente_depuis?->equalTo(now()))->toBeTrue()
        ->and($user->hasVerifiedEmail())->toBeFalse();
});

it('garde telle quelle l empreinte que l inscription a calculee', function (): void {
    $empreinte = Hash::make('un-mot-de-passe-pose');

    $user = app(CreateUserAction::class)->execute([
        'name' => 'Ada Lovelace',
        'email' => 'ada@example.test',
        'password' => $empreinte,
    ]);

    expect($user->refresh()->getAuthPassword())->toBe($empreinte)
        ->and(Hash::check('un-mot-de-passe-pose', $user->getAuthPassword()))->toBeTrue();
});
