<?php

declare(strict_types=1);

namespace App\Actions\Diagnostic;

use RuntimeException;

/**
 * La création d'un jeton de diagnostic est refusée côté serveur (#2032).
 * `motif` est un mot fermé que l'appelant range ; le message ne cite aucune
 * valeur saisie.
 */
final class CreationDeJetonRefusee extends RuntimeException
{
    public const string INTERRUPTEUR_COUPE = 'interrupteur-coupe';

    public const string CAPACITE_ABSENTE = 'capacite-absente';

    public const string DOUBLE_AUTHENTIFICATION_ABSENTE = 'double-authentification-absente';

    public const string CODE_REFUSE = 'code-refuse';

    public const string TROP_D_ESSAIS = 'trop-d-essais';

    public const string ADRESSE_DES_ALERTES_VIDE = 'adresse-des-alertes-vide';

    public const string JETONS_ACTIFS_AU_MAXIMUM = 'jetons-actifs-au-maximum';

    public const string DUREE_INVALIDE = 'duree-invalide';

    public const string PORTEES_INVALIDES = 'portees-invalides';

    public function __construct(public readonly string $motif)
    {
        parent::__construct('Création du jeton de diagnostic refusée : '.$motif);
    }
}
