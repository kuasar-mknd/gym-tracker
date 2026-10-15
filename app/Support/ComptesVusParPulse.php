<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Laravel\Pulse\Users;

/**
 * Ce que Pulse affiche d'un compte dans sa carte « Usage » : son nom, son
 * adresse, et un avatar dessiné par l'application (`AvatarDesInitiales`).
 *
 * Le résolveur de Pulse prenait l'avatar chez des tiers : celui du fournisseur
 * de connexion du compte, sinon un service d'avatars, d'après l'empreinte de
 * son adresse, et ce même service pour un identifiant qu'il ne retrouve pas.
 * Chaque affichage de la carte leur envoyait l'adresse IP et le navigateur de
 * l'administrateur. `Pulse::user()` ne suffisait pas : Pulse ne l'appelle que
 * pour un compte retrouvé. Le chargement des comptes reste celui de Pulse.
 */
final class ComptesVusParPulse extends Users
{
    public function __construct(private readonly AvatarDesInitiales $avatars)
    {
    }

    /**
     * Le compte de la clé, tel que la carte l'affiche : `name`, `extra` (son
     * adresse) et `avatar`.
     *
     * @return \stdClass&object{name: string, extra: string, avatar: string}
     */
    #[\Override]
    public function find(int|string|null $key): \stdClass
    {
        $compte = $this->resolvedUsers->first(
            fn (mixed $compte): bool => $compte instanceof Authenticatable && (string) $this->key($compte) === (string) $key,
        );

        if (! $compte instanceof Authenticatable) {
            return (object) ['name' => 'ID: '.$key, 'extra' => '', 'avatar' => $this->avatars->pourLeNom('')];
        }

        $nom = self::texteDe($compte, 'name');

        return (object) ['name' => $nom, 'extra' => self::texteDe($compte, 'email'), 'avatar' => $this->avatars->pourLeNom($nom)];
    }

    /**
     * Un attribut texte du compte, chaîne vide s'il n'en a pas.
     */
    private static function texteDe(Authenticatable $compte, string $attribut): string
    {
        $valeur = $compte instanceof Model ? $compte->getAttribute($attribut) : null;

        return is_string($valeur) ? $valeur : '';
    }
}
