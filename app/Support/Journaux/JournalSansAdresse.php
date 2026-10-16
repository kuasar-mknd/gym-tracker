<?php

declare(strict_types=1);

namespace App\Support\Journaux;

use Illuminate\Log\Logger;
use Monolog\Handler\FormattableHandlerInterface;
use Monolog\Logger as Monolog;

/**
 * Ce que `config/logging.php` pose (`tap`) sur chaque canal qui écrit : le
 * formateur de chacun de ses gestionnaires passe désormais par
 * `FormateurSansAdresse` (#PR). La pile (`stack`) reprend les gestionnaires
 * de ses canaux, déjà masqués, et ne le porte pas.
 */
final class JournalSansAdresse
{
    public function __invoke(Logger $journal): void
    {
        $monolog = $journal->getLogger();

        if (! $monolog instanceof Monolog) {
            return;
        }

        foreach ($monolog->getHandlers() as $gestionnaire) {
            if ($gestionnaire instanceof FormattableHandlerInterface && ! $gestionnaire->getFormatter() instanceof FormateurSansAdresse) {
                $gestionnaire->setFormatter(new FormateurSansAdresse($gestionnaire->getFormatter()));
            }
        }
    }
}
