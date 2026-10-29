<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\Attributes\DeleteWhenMissingModels;

/**
 * Une classe en file qui déclare `#[DeleteWhenMissingModels]` pour celles qui
 * l'étendent : Laravel lit l'attribut sur les parentes (`ReadsClassAttributes`),
 * et la garde `UnTravailEnFileSansSonModeleSeSupprimeTest` doit l'y lire aussi
 * (#2109). La classe vit ici et non dans le fichier de la garde, pour
 * l'autochargement PSR-4.
 */
#[DeleteWhenMissingModels]
abstract class ParenteQuiSeSupprimeSansSonModele implements ShouldQueue
{
}
