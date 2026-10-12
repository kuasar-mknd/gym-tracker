<?php

declare(strict_types=1);

namespace App\Services\Diagnostic\Expurgation;

/**
 * Le texte du serveur d'une erreur SQL, ses littéraux devenus `'?'`.
 *
 * Ne reste entre apostrophes qu'un nom (index, colonne, table) à la place
 * que lui réserve une forme connue du serveur : en tête (« Column 'x' cannot
 * be null », « Table 'x' doesn't exist »), ou en queue d'une clé en double
 * (« … for key 'x' ») ou d'une valeur mal formée (« … for column 'x' at row
 * 1 »). Un nom n'a que des lettres, des chiffres, `_`, `$` et `.`. Tout le
 * reste, de la première apostrophe à la dernière, devient `'?'` : une valeur
 * qui contient elle-même des apostrophes, ou qui imite la queue d'une forme,
 * ne laisse rien passer. Une apostrophe seule masque jusqu'au bout.
 */
final class MasqueLeTexteDuServeur
{
    private const string NOM = "'[\\w$.]{1,128}'";

    private const string FORMES = '/^(?|'
        .'(?<tete>(?:Column|Field|Unknown column|Table|Unknown table) '.self::NOM.')(?<milieu>.*)(?<queue>)'
        .'|(?<tete>Duplicate entry)(?<milieu>.*)(?<queue> for key '.self::NOM.')'
        ."|(?<tete>Incorrect [^:'\\n]{1,64} value:|Data too long|Data truncated|Out of range value)(?<milieu>.*)(?<queue> for column ".self::NOM.' at row \d{1,10})'
        .'|(?<tete>)(?<milieu>.*)(?<queue>)'
        .')$/sD';

    public function __invoke(string $texte): string
    {
        $morceaux = Motifs::trouver(self::FORMES, $texte) ?? ['tete' => '', 'milieu' => $texte, 'queue' => ''];

        return $morceaux['tete'].$this->sansLitteraux($morceaux['milieu']).$morceaux['queue'];
    }

    private function sansLitteraux(string $milieu): string
    {
        $premiere = strpos($milieu, "'");
        $derniere = (int) strrpos($milieu, "'");

        return match (true) {
            $premiere === false => $milieu,
            $premiere === $derniere => substr($milieu, 0, $premiere)."'?'",
            default => substr($milieu, 0, $premiere)."'?'".substr($milieu, $derniere + 1),
        };
    }
}
