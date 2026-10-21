<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Routing\Route;

/**
 * Un paramètre de route qui désigne un modèle n'admet qu'un identifiant écrit
 * en chiffres (#2049).
 *
 * La liaison implicite cherchait `where id = '12abc'`, que MySQL convertit en
 * 12 : `/workouts/12abc` ou `/workouts/012` ouvraient la séance 12, et
 * `/api/v1/sets/12x` modifiait la série 12, sous une autre adresse que la
 * leur. Le motif répond 404 avant la base.
 *
 * Il se pose route par route, sur les seuls paramètres qu'elle porte, une fois
 * les routes chargées (`bootstrap/app.php`, `then`), et entre ainsi dans le
 * cache des routes. Pas par `Route::pattern()` : Laravel recopie chaque motif
 * global sur toutes les routes, qu'elles aient le paramètre ou non, et Ziggy
 * écrit ces motifs dans chaque page : 63 378 octets au lieu de 11 499 pour
 * dix-huit noms, mesuré le 2026-10-19 (`ZiggyExpositionTest` en admet
 * 12 000). `ParametresDeRouteNumeriquesTest` exige le motif sur chaque
 * paramètre de route de l'application lié à un modèle.
 */
final class IdentifiantsDesRoutes
{
    /**
     * La forme d'un identifiant dans une adresse : des chiffres, sans zéro en
     * tête, celle que les copies hors ligne reconnaissent
     * (resources/js/Utils/pagesGardees.js).
     */
    public const string MOTIF = '[1-9][0-9]*';

    /**
     * Les paramètres de route qui désignent un modèle par son identifiant.
     */
    public const array PARAMETRES_DE_MODELE = [
        'bodyPartMeasurement',
        'body_measurement',
        'daily_journal',
        'exercise',
        'fast',
        'goal',
        'habit',
        'intervalTimer',
        'macroCalculation',
        'plate',
        'set',
        'supplement',
        'template',
        'waterLog',
        'wilksScore',
        'workout',
        'workoutLine',
        'workout_line',
    ];

    /**
     * Pose le motif sur chaque paramètre de modèle que ces routes portent.
     *
     * @param  iterable<Route>  $routes
     */
    public static function poserSur(iterable $routes): void
    {
        foreach ($routes as $route) {
            $parametres = array_filter($route->parameterNames(), is_string(...));

            foreach (array_intersect($parametres, self::PARAMETRES_DE_MODELE) as $parametre) {
                $route->where($parametre, self::MOTIF);
            }
        }
    }
}
