<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Admin;
use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Laravel\Pulse\Users;

/**
 * Ce que Pulse retient et affiche d'un compte dans sa carte « Usage » : sous
 * quelle clé il compte ses requêtes, et le nom, l'adresse et l'avatar dessiné
 * par l'application (`AvatarDesInitiales`) qu'il montre pour cette clé.
 *
 * Le résolveur de Pulse prenait l'avatar chez des tiers : celui du fournisseur
 * de connexion du compte, sinon un service d'avatars, d'après l'empreinte de
 * son adresse, et ce même service pour un identifiant qu'il ne retrouve pas.
 * Chaque affichage de la carte leur envoyait l'adresse IP et le navigateur de
 * l'administrateur. `Pulse::user()` ne suffisait pas : Pulse ne l'appelle que
 * pour un compte retrouvé.
 *
 * Pulse compte aussi les requêtes du panneau, sous l'administrateur que la
 * garde du panneau authentifie, et cherchait chaque clé chez le fournisseur de
 * la garde par défaut. La carte se charge sous la pile de Pulse, où le
 * middleware du panneau fait de cette garde la garde par défaut : un compte de
 * l'application y était cherché parmi les administrateurs, et la carte montrait
 * l'administrateur qui porte le même numéro. Un administrateur compte donc sous
 * `admin:` suivi de son numéro, un compte de l'application sous son numéro, et
 * chaque clé se cherche dans sa table, quelle que soit la garde de la requête.
 * Une clé comptée avant ce préfixe reste lue comme un compte de l'application,
 * tant que Pulse la garde (`PULSE_STORAGE_KEEP`, sept jours par défaut).
 */
final class ComptesVusParPulse extends Users
{
    /**
     * Ce qui précède le numéro d'un administrateur dans sa clé.
     */
    public const string PREFIXE_ADMINISTRATEUR = 'admin:';

    public function __construct(private readonly AvatarDesInitiales $avatars)
    {
    }

    /**
     * La clé sous laquelle Pulse compte les requêtes et les tâches du compte :
     * son numéro, précédé de `admin:` pour un administrateur.
     */
    #[\Override]
    public function key(Authenticatable $user): int|string|null
    {
        $identifiant = $user->getAuthIdentifier();

        if (! is_int($identifiant) && ! is_string($identifiant)) {
            return null;
        }

        return $user instanceof Admin ? self::PREFIXE_ADMINISTRATEUR.$identifiant : $identifiant;
    }

    /**
     * Charge les comptes des clés, chacun dans sa table : les administrateurs
     * par leur préfixe, les comptes de l'application par leur numéro.
     *
     * @param  Collection<int, int|string|null>  $keys
     */
    #[\Override]
    public function load(Collection $keys): self
    {
        $cles = $keys
            ->map(static fn (int|string|null $cle): string => (string) $cle)
            ->reject(static fn (string $cle): bool => $cle === '');
        $numerosDesAdministrateurs = $cles
            ->filter(static fn (string $cle): bool => str_starts_with($cle, self::PREFIXE_ADMINISTRATEUR))
            ->map(static fn (string $cle): string => substr($cle, strlen(self::PREFIXE_ADMINISTRATEUR)));
        $numerosDesComptes = $cles->reject(static fn (string $cle): bool => str_starts_with($cle, self::PREFIXE_ADMINISTRATEUR));

        /** @var list<Authenticatable> $retrouves */
        $retrouves = [
            ...User::query()->findMany($numerosDesComptes->values()->all())->all(),
            ...Admin::query()->findMany($numerosDesAdministrateurs->values()->all())->all(),
        ];
        $this->resolvedUsers = collect($retrouves);

        return $this;
    }

    /**
     * Le compte de la clé, tel que la carte l'affiche : `name`, `extra` (son
     * adresse, précédée de son rôle pour un administrateur) et `avatar`.
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
        $adresse = self::texteDe($compte, 'email');

        return (object) [
            'name' => $nom,
            'extra' => $compte instanceof Admin ? 'Administrateur · '.$adresse : $adresse,
            'avatar' => $this->avatars->pourLeNom($nom),
        ];
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
