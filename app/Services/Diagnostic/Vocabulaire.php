<?php

declare(strict_types=1);

namespace App\Services\Diagnostic;

/**
 * Le vocabulaire fermé du diagnostic (#2032) : hors de `non_fiable`, une
 * réponse ne contient que des valeurs de ces formes, des nombres et des
 * dates (`ValeurLue`).
 *
 * Chaque valeur lue en base passe par la forme de ce qu'elle est censée
 * être : un nom de classe, un fichier du dépôt, un nom de route, une valeur
 * d'une liste. Une valeur qui n'a pas sa forme devient `null`, jamais un
 * texte approché : un nom de job écrit par un tiers, un fichier hors du
 * dépôt, une méthode HTTP inventée ne passent pas. Une forme ne ferme pas à
 * elle seule le vocabulaire d'un nom qu'un tiers peut écrire : un fichier
 * doit en plus exister, dans le dépôt ou dans le build en place.
 */
final class Vocabulaire
{
    public const string CLASSE = '/^\\\\?[A-Za-z_][A-Za-z0-9_]{0,127}(?:\\\\[A-Za-z_][A-Za-z0-9_]{0,127}){0,15}(?:@anonymous)?$/D';

    public const string FICHIER = '/^(?!.*\.\.)[A-Za-z0-9_][A-Za-z0-9_.\/-]{0,254}$/D';

    public const string NOM_DE_ROUTE = '/^[A-Za-z0-9_][A-Za-z0-9_.-]{0,127}$/D';

    public const string GABARIT_DE_ROUTE = '/^[A-Za-z0-9_{}\/.?-]{0,255}$/D';

    public const string APPEL = '/^(?:[A-Za-z_][A-Za-z0-9_\\\\]{0,254}(?:@anonymous)?(?:->|::))?(?:[A-Za-z_][A-Za-z0-9_]{0,127}|\{closure\})$/D';

    public const string CODE = '/^[A-Za-z0-9]{1,16}$/D';

    public const string IDENTIFIANT = '/^[A-Za-z0-9_.:-]{1,64}$/D';

    public const string LIBELLE = '/^[\pL\pN][\pL\pN ._\'’()-]{0,63}$/uD';

    public const string COMMANDE = '/^[A-Za-z0-9_:\\\\][A-Za-z0-9_:\\\\ =.,\'"\/-]{0,254}$/D';

    public const string CRON = '/^[0-9*\/,\- ]{9,64}$/D';

    public const string UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D';

    public const string EMPREINTE = '/^[0-9a-f]{32}$/D';

    public const string FICHIER_DU_BUILD = '/^build\/assets\/[A-Za-z0-9_.-]{1,128}\.js$/D';

    /**
     * La valeur si elle a la forme donnée, sinon `null`.
     */
    public static function forme(string $motif, mixed $valeur): ?string
    {
        return match (is_string($valeur) && preg_match($motif, $valeur) === 1) {
            true => $valeur,
            false => null,
        };
    }

    /**
     * La valeur si elle est dans la liste, sinon `null`.
     *
     * @param  list<string>  $liste
     */
    public static function parmi(mixed $valeur, array $liste): ?string
    {
        return match (in_array($valeur, $liste, true)) {
            true => $valeur,
            false => null,
        };
    }

    /**
     * Un fichier du dépôt qui existe, relatif à sa racine. Un chemin hors de
     * l'application, ou un nom qui n'a que la forme d'un fichier, n'est pas
     * un fichier du dépôt : `null`. Le disque est relu à chaque appel.
     */
    public static function fichier(mixed $chemin): ?string
    {
        $racine = base_path().'/';
        $relatif = match (is_string($chemin) && str_starts_with($chemin, $racine)) {
            true => self::forme(self::FICHIER, substr($chemin, strlen($racine))),
            false => self::forme(self::FICHIER, $chemin),
        };

        return match ($relatif !== null && is_file($racine.$relatif)) {
            true => $relatif,
            false => null,
        };
    }

    /**
     * Le fichier JavaScript du build en place que désigne la source d'une
     * erreur navigateur, URL ou chemin, sans hôte ni chaîne de requête. La
     * source est écrite par le client : un nom qui a seulement la forme d'un
     * fichier du build porterait le texte de son choix. Seul un fichier que
     * le build a écrit, et qui est encore sous `public/build/assets`, est
     * rendu ; tout autre devient `null`. Le disque est relu à chaque appel.
     */
    public static function fichierDuBuild(mixed $source): ?string
    {
        $chemin = self::forme(self::FICHIER_DU_BUILD, ltrim((string) parse_url(is_string($source) ? $source : '', PHP_URL_PATH), '/'));

        return match ($chemin !== null && is_file(public_path($chemin))) {
            true => $chemin,
            false => null,
        };
    }

    /**
     * Un appel de la pile : `Classe->méthode`, `Classe::méthode` ou une
     * fonction. Une fermeture se nomme `{closure}`, sans le fichier ni la
     * ligne que PHP ajoute à son nom, ni les fermetures qui l'entourent.
     */
    public static function appel(mixed $appel): ?string
    {
        return self::forme(self::APPEL, is_string($appel) ? (string) preg_replace('/\{closure.*$/s', '{closure}', $appel) : null);
    }
}
