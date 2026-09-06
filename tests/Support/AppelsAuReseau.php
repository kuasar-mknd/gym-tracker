<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * Combien de fois le reseau a ete sollicite.
 *
 * Un objet plutot qu'une propriete sur la classe anonyme : le type de retour
 * de `resolveurQuiRepond()` est `ResolveurDns`, qui ne connait pas de
 * compteur. Le passer separement le rend lisible pour l'analyse statique
 * autant que pour qui lit le test.
 */
final class AppelsAuReseau
{
    public int $total = 0;
}
