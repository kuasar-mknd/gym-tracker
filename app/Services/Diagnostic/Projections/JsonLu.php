<?php

declare(strict_types=1);

namespace App\Services\Diagnostic\Projections;

/**
 * Une colonne JSON lue sans modèle : le tableau qu'elle contient, ou un
 * tableau vide si elle est nulle, illisible ou n'en contient pas.
 */
final class JsonLu
{
    /**
     * @return array<array-key, mixed>
     */
    public static function tableau(mixed $json): array
    {
        $decode = json_decode(is_string($json) ? $json : '', true);

        return is_array($decode) ? $decode : [];
    }

    /**
     * La valeur d'une clé d'un tableau décodé, `null` si ce n'est pas un
     * tableau ou si la clé manque.
     */
    public static function champ(mixed $tableau, string $cle): mixed
    {
        return is_array($tableau) ? $tableau[$cle] ?? null : null;
    }

    /**
     * La chaîne d'une clé d'un tableau décodé, vide si ce n'en est pas une.
     */
    public static function texte(mixed $tableau, string $cle): string
    {
        $valeur = self::champ($tableau, $cle);

        return is_string($valeur) ? $valeur : '';
    }
}
