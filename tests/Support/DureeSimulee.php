<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Support\DureeDesReponsesDAuthentification;
use Carbon\CarbonInterval;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Sleep;

/**
 * La durée d'une réponse d'authentification, mesurée sans dormir pour de vrai.
 *
 * phpunit.xml met le plancher commun à 0 (`AUTH_TIMEBOX_DURATION`) : sans
 * quoi chaque connexion ratée de la suite durerait une seconde. Ce relevé
 * repose le plancher voulu, simule le sommeil des Timebox (`Sleep::fake()`)
 * et additionne, pour chaque requête, le temps réellement passé et le sommeil
 * simulé : c'est la durée qu'aurait vue le client.
 *
 * Pendant une mesure, les Timebox lisent une heure qui avance aussi du
 * sommeil simulé (tests/Support/horloge-des-timebox.php), comme l'heure réelle
 * avance d'un vrai sommeil. Une Timebox qui en englobe une autre, comme celle
 * d'un contrôleur autour de la garde ou du courtier, voit donc passer le
 * plancher que celle-ci vient de dormir, et ne le redort pas : la somme ne
 * compte chaque sommeil qu'une fois, et un travail fait après la Timebox
 * intérieure, que rien ne couvre en vrai, rallonge la mesure comme il
 * rallonge la réponse. Un travail lent se simule de même par un sommeil
 * (`Sleep::usleep()`), que les Timebox voient passer.
 *
 * Deux pièges de la suite, que `mesurer()` désamorce avant chaque requête :
 * la garde de session et le courtier des mots de passe lisent le plancher à
 * leur création, puis le gardent ; et une Timebox qui a rendu la main tôt une
 * fois (une connexion réussie) ne remet jamais ce droit à faux, si bien que
 * la même garde ne pose plus le plancher des refus suivants. En production,
 * chaque requête a sa garde et son courtier (Octane les oublie, php-fpm les
 * recrée) ; dans un test, l'application et ses gardes vivent d'une requête à
 * l'autre, d'où `Auth::forgetGuards()` et l'oubli du courtier.
 */
final class DureeSimulee
{
    /**
     * Le sommeil simulé depuis le début de la mesure en cours, en secondes :
     * ce que l'heure des Timebox ajoute à l'heure réelle. Nul hors d'une mesure.
     */
    private static float $sommeilDeLaMesure = 0.0;

    /**
     * Les sommeils simulés pendant la dernière mesure, en microsecondes.
     *
     * @var list<float>
     */
    private array $sommeils = [];

    /**
     * Une mesure est-elle en cours ? Seul son sommeil fait avancer l'heure des Timebox.
     */
    private bool $mesureEnCours = false;

    private function __construct(public readonly int $plancher)
    {
    }

    /**
     * Pose le plancher (celui de production par défaut) et simule le sommeil.
     */
    public static function demarrer(int $plancher = DureeDesReponsesDAuthentification::PLANCHER_PAR_DEFAUT_MICROSECONDES): self
    {
        config(['auth.timebox_duration' => $plancher]);

        $releve = new self($plancher);

        Sleep::fake();
        Sleep::whenFakingSleep(static function (CarbonInterval $duree) use ($releve): void {
            if (! $releve->mesureEnCours) {
                return;
            }

            $releve->sommeils[] = $duree->totalMicroseconds;
            self::$sommeilDeLaMesure += $duree->totalMicroseconds / 1_000_000;
        });

        return $releve;
    }

    /**
     * L'heure que lisent les Timebox, en secondes, sous la forme que rend
     * `microtime()` : l'heure réelle, plus le sommeil simulé de la mesure en
     * cours.
     */
    public static function heureDesTimebox(bool $enFlottant): string|float
    {
        $heure = \microtime(true) + self::$sommeilDeLaMesure;

        if ($enFlottant) {
            return $heure;
        }

        $secondes = floor($heure);

        return sprintf('%.8F %d', $heure - $secondes, $secondes);
    }

    /**
     * Joue la requête avec une garde et un courtier neufs, comme en production,
     * et rend sa durée vue du client.
     *
     * @template TReponse
     *
     * @param  callable(): TReponse  $requete
     * @return array{reponse: TReponse, reel: float, dormi: float, total: float}
     */
    public function mesurer(callable $requete): array
    {
        Auth::forgetGuards();
        app()->forgetInstance('auth.password');
        Password::clearResolvedInstance('auth.password');

        $this->sommeils = [];
        self::$sommeilDeLaMesure = 0.0;
        $this->mesureEnCours = true;
        $debut = hrtime(true);

        try {
            $reponse = $requete();
            $reel = (hrtime(true) - $debut) / 1_000;
        } finally {
            $this->mesureEnCours = false;
            self::$sommeilDeLaMesure = 0.0;
        }

        $dormi = (float) array_sum($this->sommeils);

        return ['reponse' => $reponse, 'reel' => $reel, 'dormi' => $dormi, 'total' => $reel + $dormi];
    }
}
