<?php

declare(strict_types=1);

namespace App\Support\Sante;

use RuntimeException;
use Spatie\Health\Checks\Check;
use Spatie\Health\Checks\Result;
use Throwable;

/**
 * Le dossier des sauvegardes doit être inscriptible par l'utilisateur du
 * conteneur (#1812). En production, c'est un dossier de l'hôte que le dépôt ne tient
 * pas : quand il ne l'était pas, toute sauvegarde échouait, et la page ne le
 * disait qu'à travers « Backups », vingt-six heures plus tard et sans dire
 * pourquoi. Le contrôle écrit puis efface vraiment une sonde à la racine du
 * disque, parce que `is_writable()` répond côté client sur un partage réseau :
 * une écriture toutes les cinq minutes.
 */
final class DossierDesSauvegardesCheck extends Check
{
    /**
     * Le libellé de la page « Santé », qui découperait sinon le nom de classe.
     */
    #[\Override]
    protected ?string $label = 'Dossier des sauvegardes';

    #[\Override]
    public function getName(): string
    {
        return 'DossierDesSauvegardes';
    }

    public function run(): Result
    {
        // Lue à chaque passage : un disque résolu au démarrage figerait sa racine.
        $dossier = config()->string('filesystems.disks.sauvegardes.root');
        $result = Result::make()->meta(['dossier' => $dossier]);

        try {
            $this->sonder($dossier);
        } catch (Throwable $erreur) {
            return $result->shortSummary('Non inscriptible')->failed(sprintf(
                "Le dossier des sauvegardes %s n'est pas inscriptible par %s (%s) : aucune archive ne peut s'écrire. "
                ."Sur le serveur, donner le dossier BACKUP_HOST_PATH à l'uid 33 (chown -R 33:33), puis lancer une sauvegarde depuis le panneau.",
                $dossier,
                $this->utilisateur(),
                $erreur->getMessage(),
            ));
        }

        return $result->shortSummary('Inscriptible')->ok();
    }

    /**
     * Crée la racine si elle manque, comme la sauvegarde le ferait, puis y
     * écrit et en efface une sonde au nom unique : le contrôle peut tourner
     * dans le planificateur et depuis la page au même instant.
     */
    private function sonder(string $dossier): void
    {
        if (! is_dir($dossier) && ! mkdir($dossier, 0775, true) && ! is_dir($dossier)) {
            throw new RuntimeException("impossible de créer {$dossier}");
        }

        $sonde = $dossier.'/.sonde-sante-'.bin2hex(random_bytes(6));

        if (file_put_contents($sonde, 'sonde') === false) {
            throw new RuntimeException("impossible d'écrire {$sonde}");
        }

        if (! unlink($sonde)) {
            throw new RuntimeException("impossible d'effacer {$sonde}");
        }
    }

    private function utilisateur(): string
    {
        return function_exists('posix_geteuid') ? 'uid '.posix_geteuid() : "l'utilisateur du conteneur";
    }
}
