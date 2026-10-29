<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * Un trait qui reprend `SeSupprimeSansSonModele` : Laravel ne lit pas
 * l'attribut sur le trait d'un trait (`ReadsClassAttributes` ne lit que les
 * traits que la classe ou l'une de ses parentes déclare), et la garde
 * `UnTravailEnFileSansSonModeleSeSupprimeTest` ne doit pas l'y lire non plus
 * (#2109) : la déclaration resterait sans effet.
 */
trait ReprendSeSupprimeSansSonModele
{
    use SeSupprimeSansSonModele;
}
