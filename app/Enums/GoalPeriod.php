<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Sur quelle durée un objectif de fréquence compte ses séances : depuis
 * toujours (« Atteindre 100 séances au total »), ou sur la semaine du calendrier,
 * du lundi au dimanche (« 3 séances par semaine »). Les autres types
 * d'objectif ne s'en servent pas.
 */
enum GoalPeriod: string
{
    case Total = 'total';
    case Week = 'week';
}
