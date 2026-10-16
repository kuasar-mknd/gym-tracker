<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Un texte sans adresse de courriel en clair (#PR) : le message d'une
 * exception, une ligne du journal.
 *
 * Le serveur de courriel cite souvent le destinataire qu'il refuse
 * (« 550 5.1.1 <ada@example.org>: Recipient address rejected »), et Symfony
 * recopie sa réponse dans le message de son exception ; Symfony Mime cite
 * de même l'adresse qu'il refuse d'écrire. Ce message partait dans le
 * journal, la table des exceptions du panneau, `failed_jobs` et les jobs
 * échoués de Horizon. `CourrielleurQuiTaitLesAdresses` le passe ici avant
 * de relever l'exception ; `ExceptionEnregistree` et `FormateurSansAdresse`
 * aussi, en défense.
 *
 * Toute suite qui a la forme d'une adresse devient « [adresse masquée] » :
 * une partie locale, entre guillemets ou non, commentaire compris
 * (« ada(x)@… »), `@`, puis un domaine, littéral (« […] ») compris. Elle
 * commence au début d'un mot, jamais au milieu : un chemin
 * (« node_modules/@inertiajs ») n'est pas une adresse. Chaque mot n'est
 * essayé qu'une fois, et chaque partie se lit d'un trait, sans retour en
 * arrière : le masque reste linéaire sur une ligne de plusieurs mégaoctets,
 * loin de la limite de PCRE, au-delà de laquelle le texte serait perdu.
 * C'est aussi pourquoi une adresse encodée dans une URL (`%40`) n'est pas
 * reconnue : la reconnaître demande, à chaque octet encodé, une alternative
 * qui épuise cette limite sur une longue suite encodée (mesuré : un million
 * de `%41`), et l'application n'écrit plus d'adresse dans une URL, ses liens
 * la portant chiffrée (`LiensDesCourriels`).
 *
 * Les adresses données (le destinataire d'un courriel) se masquent en plus
 * par leur seule partie locale, quelle que soit la casse : un serveur peut
 * ne citer qu'elle (« 550 ada... User unknown »).
 */
final class TexteSansAdresse
{
    /** Ce qui remplace une adresse. */
    public const string MASQUE = '[adresse masquée]';

    /**
     * Une adresse, au début d'un mot. Les délimiteurs d'un mot sont ce qui ne
     * s'écrit pas dans une adresse sans guillemets : blanc, guillemet,
     * apostrophe, chevrons, crochets, virgule, point-virgule, deux-points,
     * barres obliques, et `@` lui-même.
     */
    private const string ADRESSE = '~(?<![^\s"\'<>\[\],;:@/\\\\])'
        .'(?:"[^"\r\n]*+"|[^\s"\'<>\[\],;:@/\\\\]++)'
        .'@'
        .'(?:\[[^\]\s]*+\]|[^\s"\'<>\[\],;:@/\\\\]++)~';

    /**
     * Ce qui remplace un texte que l'expression n'a pas su lire : rien de
     * l'original, plutôt qu'un original entier.
     */
    private const string ILLISIBLE = '[texte illisible, adresses masquées]';

    /**
     * Le texte, chaque adresse masquée, et chaque partie locale des adresses
     * données.
     *
     * @param  list<string>  $adresses
     */
    public static function de(string $texte, array $adresses = []): string
    {
        $masque = preg_replace(self::ADRESSE, self::MASQUE, $texte) ?? self::ILLISIBLE;

        foreach ($adresses as $adresse) {
            $partieLocale = Str::beforeLast($adresse, '@');
            $motif = '~(?<![\w.+-])'.preg_quote($partieLocale, '~').'(?![\w+-])~i';
            $masque = $partieLocale === '' ? $masque : preg_replace($motif, self::MASQUE, $masque) ?? self::ILLISIBLE;
        }

        return $masque;
    }
}
