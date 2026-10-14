<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Ce qu'on a voulu faire avec l'adresse d'un compte existant, et dont son
 * titulaire est prévenu (`TentativeSurTonAdresse`).
 */
enum MotifDeLaTentative: string
{
    /** Une inscription avec cette adresse (`TraiterUneInscription`). */
    case Inscription = 'inscription';

    /** Un autre compte a demandé à prendre cette adresse (`DonnerSuiteAUnChangementDAdresse`). */
    case ChangementDAdresse = 'changement-d-adresse';
}
