<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Ce que dit un courriel d'`AlerteDeDiagnostic` (#2032).
 */
enum EvenementDAlerteDeDiagnostic: string
{
    case Creation = 'creation';
    case Revocation = 'revocation';
    case PremierUsage = 'premier-usage';
    case JetonInvalidePresente = 'jeton-invalide-presente';
    case DebitDepasse = 'debit-depasse';
}
