<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Pourquoi un jeton de diagnostic a été révoqué (#2032). La raison s'écrit
 * avec la révocation et se lit dans la page des accès.
 */
enum RaisonDeRevocationDuJeton: string
{
    case Manuelle = 'manuelle';
    case Interrupteur = 'interrupteur';
    case MotDePasseChange = 'mot-de-passe-change';
    case DoubleAuthentificationRetiree = 'double-authentification-retiree';
    case AdministrateurSupprime = 'administrateur-supprime';
}
