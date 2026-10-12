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
     * Les sommeils simulés pendant la dernière mesure, en microsecondes.
     *
     * @var list<float>
     */
    private array $sommeils = [];

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
            $releve->sommeils[] = $duree->totalMicroseconds;
        });

        return $releve;
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
        $debut = hrtime(true);
        $reponse = $requete();
        $reel = (hrtime(true) - $debut) / 1_000;
        $dormi = (float) array_sum($this->sommeils);

        return ['reponse' => $reponse, 'reel' => $reel, 'dormi' => $dormi, 'total' => $reel + $dormi];
    }
}
