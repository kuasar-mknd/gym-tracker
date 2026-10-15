<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\User;
use App\Support\LiensDesCourriels;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use LogicException;

/**
 * Ce que la page de réinitialisation envoie quand on l'ouvre par le lien du
 * courriel « mot de passe oublié » : le jeton du chemin, l'adresse chiffrée
 * telle que le lien la porte, et le nouveau mot de passe deux fois.
 *
 * Le lien ne porte plus l'adresse du compte en clair, et la page ne la saisit
 * plus : un test qui réinitialise un mot de passe part donc du lien que
 * bâtit l'application (`LiensDesCourriels::lienDeReinitialisation()`), plutôt
 * que d'écrire l'adresse lui-même.
 */
final class FormulaireDeReinitialisation
{
    /**
     * Le formulaire du lien qu'aurait reçu ce compte, avec un jeton neuf du
     * courtier, ou celui donné.
     *
     * @return array{token: string, adresse: string, password: string, password_confirmation: string}
     */
    public static function pour(User $compte, string $motDePasse, ?string $jeton = null): array
    {
        return self::depuisLeLien(
            LiensDesCourriels::lienDeReinitialisation($compte, $jeton ?? Password::createToken($compte)),
            $motDePasse,
        );
    }

    /**
     * Le formulaire d'un lien de réinitialisation donné, lu comme la route le
     * lit : le jeton est le dernier segment du chemin, l'adresse chiffrée le
     * paramètre `adresse`.
     *
     * @return array{token: string, adresse: string, password: string, password_confirmation: string}
     */
    public static function depuisLeLien(string $lien, string $motDePasse): array
    {
        $chemin = (string) parse_url($lien, PHP_URL_PATH);
        parse_str((string) parse_url($lien, PHP_URL_QUERY), $parametres);
        $adresse = $parametres['adresse'] ?? null;

        if (! str_contains($chemin, '/reset-password/') || ! is_string($adresse)) {
            throw new LogicException("Ce lien n'est pas un lien de réinitialisation : {$lien}");
        }

        return [
            'token' => rawurldecode(Str::afterLast($chemin, '/reset-password/')),
            'adresse' => $adresse,
            'password' => $motDePasse,
            'password_confirmation' => $motDePasse,
        ];
    }
}
