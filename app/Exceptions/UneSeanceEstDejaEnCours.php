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

    public function __construct(public readonly Workout $seanceEnCours)
    {
        parent::__construct(sprintf('La séance %d est déjà en cours.', $seanceEnCours->id));
    }
}
