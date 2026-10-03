<?php

declare(strict_types=1);

namespace App\Support\Sante;

use Spatie\Health\Checks\Check;
use Spatie\Health\Checks\Result;

/**
 * Les réglages qui ont fait tomber chaque écriture de production de 250–500 ms à
 * quelques millisecondes (#1668) vivent dans la pile déployée : un
 * réalignement qui les perdrait ne se verrait qu'au ressenti. Rouge quand
 * MySQL synchronise chaque validation ou tient un journal binaire, orange
 * quand Pulse enregistre. Production seulement : Sail et la CI gardent les
 * défauts de MySQL. Aucune écriture SQL, et la lecture se fait dans run(),
 * pas au démarrage.
 */
final class ReglagesDeLaBaseCheck extends Check
{
    /**
     * Le libellé de la page « Santé », qui découperait sinon le nom de classe.
     */
    #[\Override]
    protected ?string $label = 'Réglages de la base';

    public function __construct(private readonly LecteurDesReglagesDeLaBase $lecteur)
    {
        parent::__construct();
    }

    #[\Override]
    public function getName(): string
    {
        return 'ReglagesDeLaBase';
    }

    public function run(): Result
    {
        if (! app()->isProduction()) {
            return Result::make()
                ->shortSummary('Non jugé hors production')
                ->ok('Ce contrôle ne juge que la production : Sail et la CI gardent les réglages par défaut de MySQL.');
        }

        $variables = $this->lecteur->lire();
        $synchronisation = $variables['innodb_flush_log_at_trx_commit'] ?? '?';
        $journalBinaire = strtoupper($variables['log_bin'] ?? '?');
        $pulse = (bool) config('pulse.enabled');

        $rouges = [];

        if ($synchronisation === '1') {
            $rouges[] = 'innodb_flush_log_at_trx_commit vaut 1 : chaque validation se synchronise sur le disque, et chaque écriture '
                .'repaie 250 à 500 ms sur le disque de production. Remettre --innodb-flush-log-at-trx-commit=2 dans la commande du service db.';
        }

        if ($journalBinaire === 'ON') {
            $rouges[] = 'log_bin vaut ON : chaque écriture est doublée dans un journal binaire que rien ne lit. '
                .'Remettre --skip-log-bin dans la commande du service db.';
        }

        $oranges = $pulse
            ? ['Pulse enregistre : chaque requête et chaque job écrivent leurs agrégats en base, ce qui provoquait un convoi de verrous. '
                .'Poser PULSE_ENABLED=false dans la pile, ou l\'en retirer : la composition le coupe par défaut.']
            : [];

        // Un réglage que MySQL n'a pas rendu n'est pas un bon réglage : le vert
        // dirait vérifié ce que personne n'a lu.
        $illisibles = array_diff(['innodb_flush_log_at_trx_commit', 'log_bin'], array_keys($variables));

        if ($illisibles !== []) {
            $oranges[] = sprintf('%s illisible dans SHOW GLOBAL VARIABLES : le réglage n\'a pas pu être vérifié.', implode(' et ', $illisibles));
        }

        $result = Result::make()
            ->meta([
                'innodb_flush_log_at_trx_commit' => $synchronisation,
                'log_bin' => $journalBinaire,
                'pulse' => $pulse,
            ])
            ->shortSummary(sprintf('flush %s · log_bin %s · Pulse %s', $synchronisation, $journalBinaire, $pulse ? 'actif' : 'coupé'));

        if ($rouges !== []) {
            return $result->failed(implode(' ', [...$rouges, ...$oranges]));
        }

        if ($oranges !== []) {
            return $result->warning(implode(' ', $oranges));
        }

        return $result->ok();
    }
}
