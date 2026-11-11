<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Ce que `base:mesurer` conclut d'une fenêtre de mesure (#1668), dans l'ordre
 * où le verdict les préfère : le 03/09, avant le réglage de la base, c'était
 * les deux premiers à la fois, et le convoi l'emportait.
 */
enum VerdictDeLaBase: string
{
    case ConvoiDeVerrous = 'convoi de verrous';
    case CoutDeValidation = 'coût de validation';
    case RienASignaler = 'rien à signaler';
}
