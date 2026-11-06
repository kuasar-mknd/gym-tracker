<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * La version d'une famille de clés de cache : un ULID, jamais réutilisé (#2151).
 *
 * Les statistiques, les valeurs recommandées et les listes d'exercices rangent
 * leurs entrées sous une version. Invalider, c'est en tirer une neuve : les
 * entrées d'avant deviennent inatteignables d'un coup, sans être énumérées.
 *
 * La version vit dans le cache, sans échéance, et Redis l'évince comme le
 * reste quand il manque de place. Les versions étaient des compteurs : évincé,
 * un compteur repartait de zéro, puis remontait vers des numéros déjà servis,
 * et une entrée encore vivante sous l'un d'eux, écrite avant une invalidation,
 * était resservie. Un ULID tiré à neuf ne nomme jamais une entrée d'avant :
 * perdre la version ne coûte plus qu'un recalcul.
 *
 * Écrire un ULID neuf est donc sûr à tout moment ; seule une version déjà lue
 * ne doit jamais être réécrite. Une version absente, ou que l'application n'a
 * pas tirée (le compteur d'une release d'avant, une valeur illisible), est
 * remplacée par une neuve, puis relue : deux requêtes qui la tirent en même
 * temps écrivent chacune la leur, la dernière écrite l'emporte et la relecture
 * y range l'autre. Au pire, un calcul fait deux fois. `Cache::add()` n'y
 * gagnerait rien : sans durée, `Repository::add()` lit puis écrit, comme ici,
 * et il ne remplacerait pas le compteur d'avant, qui occupe la clé.
 *
 * Lue et renouvelée par le mémo du cache (`Cache::memo()`) : une page qui lit
 * la même version pour chacune de ses entrées ne va qu'une fois au magasin.
 * Le mémo ne vit qu'une requête ou un job (Octane et le worker de la file
 * oublient les services `scoped` entre deux) ; une invalidation passe par lui
 * et lui fait oublier sa copie, si bien qu'une requête qui écrit puis relit
 * voit la version neuve.
 */
final class VersionDeCache
{
    /**
     * La version rangée sous `$cle`, tirée à neuf si elle manque ou si
     * l'application ne l'a pas tirée.
     */
    public static function lire(string $cle): string
    {
        $version = Cache::memo()->get($cle);

        if (self::estUneVersion($version)) {
            return $version;
        }

        $tiree = self::ecrireUneVersionNeuve($cle);
        $relue = Cache::memo()->get($cle);

        return self::estUneVersion($relue) ? $relue : $tiree;
    }

    /**
     * Rend inatteignables toutes les entrées rangées sous la version actuelle.
     */
    public static function renouveler(string $cle): void
    {
        self::ecrireUneVersionNeuve($cle);
    }

    private static function ecrireUneVersionNeuve(string $cle): string
    {
        $version = (string) Str::ulid();
        Cache::memo()->forever($cle, $version);

        return $version;
    }

    /**
     * @phpstan-assert-if-true string $valeur
     */
    private static function estUneVersion(mixed $valeur): bool
    {
        return is_string($valeur) && Str::isUlid($valeur);
    }
}
