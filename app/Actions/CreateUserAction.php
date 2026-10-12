<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\User;
use Illuminate\Auth\Events\Registered;

/**
 * Le compte que l'inscription crée, en attente de la preuve de son adresse.
 *
 * L'inscription ne prouve pas l'adresse : n'importe qui peut taper celle d'un
 * autre. Le compte naît donc en attente (`en_attente_depuis`, posée avant
 * l'insertion) et ne se connecte qu'une fois son adresse prouvée
 * (`AttendLaPreuveDeSonAdresse`, `ConnecterLeCompte`). `Registered` fait
 * partir le lien qui l'ouvre (`User::sendEmailVerificationNotification()`).
 * Seule l'inscription s'en sert, depuis la file (`TraiterUneInscription`) :
 * le panneau et les fournisseurs de connexion créent des comptes ouverts.
 */
final readonly class CreateUserAction
{
    public function __construct(private CreerLesPlaquesParDefautAction $plaquesParDefaut)
    {
    }

    /**
     * @param  array<string, string>  $input  `password` : le mot de passe, ou son empreinte, que le cast `hashed` garde telle quelle.
     */
    public function execute(array $input): User
    {
        $user = new User([
            'name' => $input['name'],
            'email' => $input['email'],
            'password' => $input['password'],
        ]);

        $user->forceFill(['en_attente_depuis' => $user->freshTimestamp()])->save();

        $this->plaquesParDefaut->execute($user);

        event(new Registered($user));

        return $user;
    }
}
