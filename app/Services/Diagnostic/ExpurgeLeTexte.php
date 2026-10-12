<?php

declare(strict_types=1);

namespace App\Services\Diagnostic;

use App\Services\Diagnostic\Expurgation\MarqueLesValeursDeLaConfiguration;
use App\Services\Diagnostic\Expurgation\MasqueLesCoordonnees;
use App\Services\Diagnostic\Expurgation\MasqueLesSecrets;
use App\Services\Diagnostic\Expurgation\NormaliseLeTexte;
use App\Services\Diagnostic\Expurgation\RameneLesChemins;
use App\Services\Diagnostic\Expurgation\ReduitLesErreursSql;
use App\Services\Diagnostic\Expurgation\RetireLesInvisibles;
use App\Services\Diagnostic\Expurgation\TexteIllisible;

/**
 * Un texte libre rendu montrable au diagnostic (#2032), sans état : chaque
 * appel part du texte reçu et de la configuration du moment.
 *
 * Dans l'ordre :
 *
 * 1. l'entrée est coupée à 8 Kio, avant tout motif ;
 * 2. normalisée en NFKC, ses `%xx` décodés (`NormaliseLeTexte`) ;
 * 3. sans caractères invisibles ni séquences ANSI (`RetireLesInvisibles`) ;
 * 4. un message d'erreur SQL réduit (`ReduitLesErreursSql`) ;
 * 5. les valeurs connues de la configuration marquées
 *    (`MarqueLesValeursDeLaConfiguration`) ;
 * 6. courriels, adresses IP, noms d'hôte, chaînes de requête et fragments
 *    marqués (`MasqueLesCoordonnees`) ;
 * 7. ce qui ressemble à un secret devient `[secret]` (`MasqueLesSecrets`) ;
 * 8. les chemins absolus ramenés à la racine de l'application, ou marqués
 *    (`RameneLesChemins`) ;
 * 9. le tout coupé au budget du champ.
 *
 * Chaque motif a des répétitions bornées, et chaque appel est vérifié : une
 * expression qui échoue fait de tout le champ `[illisible]`, jamais le texte
 * d'origine. L'expurgation est faite au mieux ; la première barrière reste de
 * ne pas rendre le texte, qui ne sort qu'au détail, sous `non_fiable`.
 */
final class ExpurgeLeTexte
{
    /** Ce qui est lu d'une entrée, en octets, avant tout motif. */
    public const int OCTETS_LUS = 8_192;

    /** Le budget d'un champ, en octets, quand l'appel n'en donne pas. */
    public const int BUDGET_PAR_DEFAUT = 2_048;

    public const string ILLISIBLE = '[illisible]';

    public const string MARQUE_DE_COUPURE = '… [tronqué]';

    public function __invoke(string $texte, int $budget = self::BUDGET_PAR_DEFAUT): TexteExpurge
    {
        try {
            $visible = new RetireLesInvisibles()(new NormaliseLeTexte()(mb_strcut($texte, 0, self::OCTETS_LUS, 'UTF-8')));
            $masque = array_reduce(
                [
                    new ReduitLesErreursSql(),
                    new MarqueLesValeursDeLaConfiguration(),
                    new MasqueLesCoordonnees(),
                    new MasqueLesSecrets(),
                    new RameneLesChemins(),
                ],
                static fn (string $partiel, callable $etape): string => $etape($partiel),
                $visible->texte,
            );

            return new TexteExpurge($this->couper($masque, $budget), $visible->invisiblesRetires);
        } catch (TexteIllisible) {
            return new TexteExpurge(self::ILLISIBLE, 0);
        }
    }

    /**
     * Le texte coupé à `$octets` octets au plus, sur une limite de
     * caractère, et qui dit qu'il l'a été.
     */
    private function couper(string $texte, int $octets): string
    {
        return match (strlen($texte) <= $octets) {
            true => $texte,
            false => mb_strcut($texte, 0, max(0, $octets - strlen(self::MARQUE_DE_COUPURE)), 'UTF-8').self::MARQUE_DE_COUPURE,
        };
    }
}
