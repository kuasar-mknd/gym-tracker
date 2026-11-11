<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Sante\LecteurDesReglagesDeLaBase;
use App\Support\Sante\MesureDeLaBase;
use Illuminate\Console\Command;
use Illuminate\Support\Sleep;
use UnexpectedValueException;

/**
 * `php artisan base:mesurer [--pendant=200] [--json]` : le protocole de mesure
 * du 03/09 (#1668), rejoué depuis l'application, sans outil extérieur. Un
 * relevé des réglages et des compteurs de la base, une attente pendant
 * laquelle on saisit une dizaine de séries, un second relevé, puis les écarts
 * et un verdict : « convoi de verrous », « coût de validation » ou « rien à
 * signaler » (`MesureDeLaBase` dit les seuils et leur raison).
 *
 * La commande ne fait que lire : quatre `SHOW GLOBAL`, qu'aucun droit au-delà
 * de celui du compte de l'application ne demande. Avec `--json`, la mesure
 * tient sur une ligne, seule, pour être lue par un programme ; un refus reste
 * un message en clair, comme celui de `diagnostic:resume`.
 */
final class MesurerLaBase extends Command
{
    #[\Override]
    protected $signature = 'base:mesurer
        {--pendant=200 : secondes entre les deux relevés}
        {--json : La mesure seule, en JSON sur une ligne}';

    #[\Override]
    protected $description = 'Mesure en lecture seule les attentes de verrou et les synchronisations de la base sur une fenêtre, puis conclut';

    public function handle(LecteurDesReglagesDeLaBase $lecteur): int
    {
        $pendant = MesureDeLaBase::secondes($this->option('pendant'));

        if ($pendant === null) {
            $this->error(sprintf(
                '« pendant » attend un nombre entier de secondes, de %s à %s.',
                MesureDeLaBase::entier(MesureDeLaBase::PENDANT_AU_MOINS),
                MesureDeLaBase::entier(MesureDeLaBase::PENDANT_AU_PLUS),
            ));

            return self::INVALID;
        }

        $json = $this->option('json') === true;
        $avant = MesureDeLaBase::relever($lecteur);

        if (! $json) {
            $this->line(sprintf('Premier relevé fait. Saisir des séries pendant %s s…', MesureDeLaBase::entier($pendant)));
        }

        Sleep::for($pendant)->seconds();

        try {
            $mesure = MesureDeLaBase::entre($avant, MesureDeLaBase::relever($lecteur), $pendant);
        } catch (UnexpectedValueException $refus) {
            $this->error($refus->getMessage());

            return self::FAILURE;
        }

        $this->line($mesure->rendu($json));

        return self::SUCCESS;
    }
}
