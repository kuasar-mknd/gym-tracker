<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\FichiersDuJour;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;

/**
 * Efface, dans le dossier des journaux, les fichiers du jour du canal `daily`
 * qui ont atteint sa durée (`days`), pour tous les conteneurs qui y écrivent.
 *
 * Monolog garde les `days` fichiers les plus récents de chaque conteneur, pas
 * `days` jours de calendrier, et n'efface le plus ancien qu'en ouvrant le
 * fichier d'un nouveau jour : un conteneur qui écrit peu gardait ses traces,
 * celles d'un compte supprimé comprises, bien au-delà (#2053). La purge garde
 * le jour même et les `days - 1` jours d'avant : pour un conteneur qui écrit
 * chaque jour, exactement ce que Monolog garde.
 *
 * L'âge d'un fichier se lit dans son nom, comme Monolog le lit : ses lignes
 * sont celles de ce jour-là. Seuls partent les fichiers du jour
 * (`App\Support\FichiersDuJour`) ; celui du jour en cours, que le canal tient
 * ouvert, n'atteint jamais la limite. Une durée qui ne borne rien (zéro, que
 * Monolog lit « sans limite », ou ce qui n'est pas un entier) fait échouer la
 * purge sans rien effacer ; un fichier resté en place la fait échouer aussi,
 * comme un dossier absent ou illisible, dont l'exception fait sortir le
 * processus en 1 : le moniteur des tâches le dit, puisque la page de profil
 * promet cette durée.
 */
class PurgerLesJournaux extends Command
{
    /**
     * @var string
     */
    #[\Override]
    protected $signature = 'journaux:purger';

    /**
     * @var string
     */
    #[\Override]
    protected $description = 'Efface les fichiers du jour du canal daily qui ont atteint sa durée';

    public function handle(): int
    {
        $jours = (int) filter_var(Config::get('logging.channels.daily.days'), FILTER_VALIDATE_INT);

        if ($jours < 1) {
            $this->error('La durée du canal daily (days) ne borne pas ses fichiers : rien n’est effacé.');

            return self::FAILURE;
        }

        $perimes = FichiersDuJour::datesAuPlusLe(
            Config::string('logging.channels.daily.path'),
            now()->subDays($jours)->toDateString(),
        );
        $restes = array_values(array_filter($perimes, static fn (string $fichier): bool => ! self::effacer($fichier)));

        $this->info(sprintf('Fichiers du jour effacés : %d, de %d jours et plus.', count($perimes) - count($restes), $jours));

        if ($restes === []) {
            return self::SUCCESS;
        }

        $this->error('Fichiers du jour restés en place : '.implode(', ', $restes));

        return self::FAILURE;
    }

    /**
     * Efface ce fichier, et dit s'il n'est plus là : celui qu'un conteneur a
     * effacé entre-temps, en ouvrant le fichier d'un nouveau jour, compte
     * comme effacé.
     */
    private static function effacer(string $fichier): bool
    {
        File::delete($fichier);

        return ! File::exists($fichier);
    }
}
