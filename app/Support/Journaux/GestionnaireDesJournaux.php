<?php

declare(strict_types=1);

namespace App\Support\Journaux;

use Illuminate\Log\Logger;
use Illuminate\Log\LogManager;

/**
 * Le gestionnaire de journaux de l'application : le canal de dernier
 * recours, que Laravel bâtit à la main quand un canal ne se construit pas,
 * sort lui aussi ses lignes sans adresse (#2243).
 *
 * `createEmergencyLogger()` ne lit que le chemin de `logging.channels.emergency`
 * et ne passe jamais par `tap()` : la clef `tap` de ce canal ne sert à rien.
 */
final class GestionnaireDesJournaux extends LogManager
{
    protected function createEmergencyLogger()
    {
        $journal = parent::createEmergencyLogger();

        if ($journal instanceof Logger) {
            (new JournalSansAdresse())($journal);
        }

        return $journal;
    }
}
