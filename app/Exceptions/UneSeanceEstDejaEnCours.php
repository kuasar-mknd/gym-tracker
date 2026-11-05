<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Models\Workout;
use RuntimeException;

/**
 * Une séance ne démarre pas : le compte en a déjà une en cours (#2049).
 *
 * `DemarrerUneSeanceAction` la lève sous le verrou du compte, quand la clé
 * du démarrage ne désigne aucune séance du compte et qu'une autre est
 * ouverte, ici ou depuis un autre appareil. Chaque appelant en fait sa
 * réponse : la page de séance renvoie vers celle qui est en cours, l'API
 * répond 409 avec la raison `RAISON` et l'identifiant de cette séance, que
 * la file hors ligne annonce comme un refus.
 */
final class UneSeanceEstDejaEnCours extends RuntimeException
{
    /**
     * La raison que porte le refus de l'API, à côté de celle d'une écriture
     * rejouée pour un autre compte (`compte-different`).
     */
    public const string RAISON = 'seance-deja-en-cours';

    /**
     * Ce que la personne lit d'un démarrage refusé, déclaré ici seulement
     * (#2119) : le 409 de l'API le porte (`message`), le lancement d'un
     * modèle par la voie web (`WorkoutTemplateController::execute`) le met
     * après le nom du modèle, et la page qui lance un modèle le relit de la
     * réponse de l'API. Elle n'en garde qu'une copie, pour le dire sans
     * réponse du serveur (`SEANCE_DEJA_EN_COURS`,
     * `useDemarrageDUneSeance.js`), que `DemarrageDeSeanceIdempotentTest`
     * garde égale à celle-ci.
     */
    public const string MESSAGE = "Une séance est déjà en cours : termine-la avant d'en commencer une autre.";

    public function __construct(public readonly Workout $seanceEnCours)
    {
        parent::__construct(sprintf('La séance %d est déjà en cours.', $seanceEnCours->id));
    }
}
