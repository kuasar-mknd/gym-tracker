<?php

declare(strict_types=1);

namespace App\Models;

use BezhanSalleh\FilamentExceptions\Models\Exception;

/**
 * Une exception gardée en base pour le panneau, sans ce qu'une requête porte
 * de secret : les cookies ne sont pas gardés, et les champs et en-têtes
 * sensibles sont masqués avant l'écriture.
 *
 * Et à taille bornée : le paquet garde le corps entier de la requête, et le
 * message d'une erreur SQL recopie les valeurs envoyées, comme la pile et le
 * texte Markdown. Une requête de plusieurs mégaoctets qui finit en erreur
 * écrivait une ligne aussi grosse qu'elle. Chaque colonne est désormais
 * coupée à un budget (`OCTETS_MAX_PAR_COLONNE`), de sorte qu'une ligne ne
 * dépasse jamais 128 Kio, quel que soit le corps reçu (`ExceptionsTest`). Ce qui
 * est coupé le dit : un texte finit par « [tronqué] », un objet JSON porte une
 * clé `…` qui compte ce qui manque. Une liste (pile, requêtes SQL) garde ses
 * premiers éléments sans marque, parce que le panneau lit chacun comme un
 * tableau : le haut de la pile, là où l'exception est levée, reste.
 */
final class ExceptionEnregistree extends Exception
{
    /**
     * Le budget de chaque colonne longue, en octets (texte, ou JSON tel que
     * Laravel l'écrit). La pile et le Markdown sont les plus utiles à lire,
     * et les plus gros d'ordinaire : ils ont la plus grande part.
     *
     * @var array<string, int>
     */
    public const array OCTETS_MAX_PAR_COLONNE = [
        'message' => 8_192,
        'markdown' => 24_576,
        'trace' => 24_576,
        'body' => 8_192,
        'headers' => 8_192,
        'query' => 12_288,
        'route_context' => 2_048,
        'route_parameters' => 4_096,
    ];

    /**
     * La longueur des colonnes textuelles courtes, en caractères, celle de
     * leur migration : au-delà, MySQL refusait l'écriture, et l'exception
     * n'était pas gardée du tout.
     *
     * @var array<string, int>
     */
    private const array CARACTERES_MAX_PAR_COLONNE = [
        'type' => 255,
        'code' => 255,
        'file' => 255,
        'method' => 10,
        'path' => 2_048,
        'ip' => 45,
    ];

    /** Le plus d'octets que garde une valeur d'un objet JSON (un champ du corps, un en-tête). */
    private const int OCTETS_MAX_PAR_VALEUR = 1_024;

    /** Le plus d'octets que garde une clé d'un objet JSON. */
    private const int OCTETS_MAX_PAR_CLE = 128;

    /** Au-delà de cette profondeur, un tableau imbriqué n'est plus gardé. */
    private const int PROFONDEUR_MAX = 16;

    /** @var list<string> */
    private const array CLEFS_MASQUEES = [
        'password',
        'password_confirmation',
        'current_password',
        'token',
        '_token',
        'secret',
        'authorization',
        'cookie',
        'x-csrf-token',
        'x-xsrf-token',
    ];

    protected static function booted(): void
    {
        self::saving(function (self $exception): void {
            $exception->cookies = null;

            /** @var array<string, mixed>|null $corps */
            $corps = self::bornerLeJson(self::masquer($exception->body), self::OCTETS_MAX_PAR_COLONNE['body']);
            $exception->body = $corps;

            /** @var array<string, array<int, string>|string> $entetes */
            $entetes = self::bornerLeJson(self::masquer($exception->headers), self::OCTETS_MAX_PAR_COLONNE['headers']) ?? [];
            $exception->headers = $entetes;

            /** @var array<int, array<string, mixed>> $pile */
            $pile = self::bornerLeJson($exception->trace, self::OCTETS_MAX_PAR_COLONNE['trace']) ?? [];
            $exception->trace = $pile;

            /** @var array<int, array<string, mixed>> $requetes */
            $requetes = self::bornerLeJson($exception->query, self::OCTETS_MAX_PAR_COLONNE['query']) ?? [];
            $exception->query = $requetes;

            /** @var array<string, string>|null $contexte */
            $contexte = self::bornerLeJson($exception->route_context, self::OCTETS_MAX_PAR_COLONNE['route_context']);
            $exception->route_context = $contexte;

            /** @var array<string, mixed>|null $parametres */
            $parametres = self::bornerLeJson($exception->route_parameters, self::OCTETS_MAX_PAR_COLONNE['route_parameters']);
            $exception->route_parameters = $parametres;

            $exception->message = self::couper((string) $exception->message, self::OCTETS_MAX_PAR_COLONNE['message']);
            $exception->markdown = $exception->markdown === null
                ? null
                : self::couper($exception->markdown, self::OCTETS_MAX_PAR_COLONNE['markdown']);

            foreach (self::CARACTERES_MAX_PAR_COLONNE as $colonne => $caracteres) {
                $valeur = $exception->getAttribute($colonne);

                if (is_string($valeur) && mb_strlen($valeur) > $caracteres) {
                    $exception->setAttribute($colonne, mb_substr($valeur, 0, $caracteres));
                }
            }
        });
    }

    /**
     * @param  array<mixed>|null  $valeurs
     * @return array<mixed>|null
     */
    private static function masquer(?array $valeurs): ?array
    {
        if ($valeurs === null) {
            return null;
        }

        foreach ($valeurs as $clef => $valeur) {
            if (is_string($clef) && in_array(strtolower($clef), self::CLEFS_MASQUEES, true)) {
                $valeurs[$clef] = '[masqué]';
            } elseif (is_array($valeur)) {
                $valeurs[$clef] = self::masquer($valeur);
            }
        }

        return $valeurs;
    }

    /**
     * Un tableau qui tient dans `$octets` une fois écrit en JSON.
     *
     * Le parcours garde les premiers éléments tant que le budget le permet, en
     * comptant chaque valeur telle que JSON l'écrit, échappements compris. Le
     * résultat est mesuré quand même, et le parcours recommence avec un budget
     * réduit d'un quart tant qu'il déborde.
     *
     * @param  array<mixed>|null  $valeurs
     * @return array<mixed>|null
     */
    private static function bornerLeJson(?array $valeurs, int $octets): ?array
    {
        if ($valeurs === null) {
            return null;
        }

        for ($budget = $octets; $budget >= 64; $budget = intdiv($budget * 3, 4)) {
            $reste = $budget;
            $bornees = self::borner($valeurs, $reste, 0);
            $json = json_encode($bornees);

            if ($json === false || strlen($json) <= $octets) {
                return $bornees;
            }
        }

        return [];
    }

    /**
     * Les premiers éléments d'un tableau, chacun borné, tant que `$reste`
     * octets de JSON le permettent.
     *
     * Dans un objet, une valeur imbriquée ne prend que la moitié de ce qui
     * reste : un champ démesuré ne prive pas ceux qui le suivent (un mot de
     * passe masqué reste visible comme tel). Dans une liste, chaque élément
     * prend ce qui reste, dans l'ordre : le haut de la pile d'abord.
     *
     * @param  array<mixed>  $valeurs
     * @return array<mixed>
     */
    private static function borner(array $valeurs, int &$reste, int $profondeur): array
    {
        $estUneListe = array_is_list($valeurs);
        $bornees = [];
        $gardes = 0;
        $reste -= 2;

        foreach ($valeurs as $clef => $valeur) {
            if ($reste <= 0) {
                break;
            }

            $clef = is_string($clef) ? self::couper($clef, self::OCTETS_MAX_PAR_CLE) : $clef;
            $reste -= ($estUneListe ? 0 : self::longueurEnJson((string) $clef) + 1) + 1;

            if (is_object($valeur)) {
                $valeur = json_decode((string) json_encode($valeur), true);
            }

            if (is_array($valeur) && $profondeur < self::PROFONDEUR_MAX) {
                $part = $estUneListe ? $reste : min($reste, max(256, intdiv($reste, 2)));
                $partAccordee = $part;
                $bornees[$clef] = self::borner($valeur, $part, $profondeur + 1);
                $reste -= $partAccordee - $part;
            } elseif (is_array($valeur)) {
                $bornees[$clef] = '[tronqué]';
                $reste -= 13;
            } else {
                $bornees[$clef] = is_string($valeur)
                    ? self::couper($valeur, max(16, min(self::OCTETS_MAX_PAR_VALEUR, $reste)))
                    : $valeur;
                $reste -= self::longueurEnJson($bornees[$clef]);
            }

            $gardes++;
        }

        if (! $estUneListe && $gardes < count($valeurs)) {
            $bornees['…'] = sprintf('[tronqué : %d élément(s) non gardé(s)]', count($valeurs) - $gardes);
        }

        return $bornees;
    }

    /**
     * La longueur d'une valeur écrite en JSON, comme Laravel l'écrit.
     */
    private static function longueurEnJson(mixed $valeur): int
    {
        return strlen((string) json_encode($valeur));
    }

    /**
     * Un texte coupé à `$octets` octets au plus, sur une limite de caractère,
     * et qui dit qu'il l'a été.
     */
    private static function couper(string $texte, int $octets): string
    {
        if (strlen($texte) <= $octets) {
            return $texte;
        }

        $marque = '… [tronqué]';

        return mb_strcut($texte, 0, max(0, $octets - strlen($marque)), 'UTF-8').$marque;
    }
}
