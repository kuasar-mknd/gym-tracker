<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\PorteeDeDiagnostic;
use App\Services\Diagnostic\FenetreDeDiagnostic;
use App\Services\Diagnostic\ResumeDuDiagnostic;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Date;
use InvalidArgumentException;

/**
 * `php artisan diagnostic:resume [--depuis=…] [--json]` : le résumé du
 * diagnostic, toutes sources comprises, tel que la page « Aperçu du
 * diagnostic » le montre et que l'agent le lirait (#2032). Les listes
 * seulement : de la structure, aucun texte libre.
 *
 * Sans `--json`, le JSON est mis en forme et suivi du nombre de caractères
 * invisibles retirés ; avec, il tient sur une ligne, seul, pour être lu par
 * un programme. La commande ne fait que lire.
 */
final class ResumerLeDiagnostic extends Command
{
    #[\Override]
    protected $signature = 'diagnostic:resume
        {--depuis= : Instant ISO 8601 à partir duquel lire les exceptions et les erreurs navigateur (vingt-quatre heures par défaut, trente jours au plus)}
        {--json : Le JSON seul, sur une ligne}';

    #[\Override]
    protected $description = 'Rend le résumé expurgé du diagnostic : santé, tâches, versions, exceptions, files, erreurs navigateur';

    public function handle(ResumeDuDiagnostic $resume): int
    {
        try {
            $fenetre = FenetreDeDiagnostic::depuis($this->depuis(), Date::now());
        } catch (InvalidArgumentException $erreur) {
            $this->error($erreur->getMessage());

            return self::INVALID;
        }

        $enveloppe = $resume(PorteeDeDiagnostic::SOURCES, $fenetre);
        $json = $this->option('json') === true;

        $this->line($enveloppe->json(lisible: ! $json));

        if (! $json) {
            $this->info(sprintf('%d caractère(s) invisible(s) retiré(s).', $enveloppe->invisiblesRetires));
        }

        return self::SUCCESS;
    }

    private function depuis(): ?string
    {
        $depuis = $this->option('depuis');

        return is_string($depuis) ? $depuis : null;
    }
}
