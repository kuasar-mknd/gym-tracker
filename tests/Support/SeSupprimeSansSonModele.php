<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Queue\Attributes\DeleteWhenMissingModels;

/**
 * Un trait qui déclare `#[DeleteWhenMissingModels]` pour la classe qui s'en
 * sert directement : Laravel lit l'attribut sur les traits de chaque niveau
 * (`ReadsClassAttributes`), et la garde `UnTravailEnFileSansSonModeleSeSupprimeTest`
 * doit l'y lire aussi (#2109).
 */
#[DeleteWhenMissingModels]
trait SeSupprimeSansSonModele
{
}
