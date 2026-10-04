<?php

declare(strict_types=1);

namespace App\Support\Sante;

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Facades\Process;
use Spatie\Health\Checks\Check;
use Spatie\Health\Checks\Result;

/**
 * Le dossier des sauvegardes doit être inscriptible par l'utilisateur du
 * conteneur (#1812). En production, c'est un dossier de l'hôte que le dépôt ne tient
 * pas : quand il ne l'était pas, toute sauvegarde échouait, et la page ne le
 * disait qu'à travers « Backups », vingt-six heures plus tard et sans dire
 * pourquoi. Le contrôle écrit puis efface vraiment une sonde à la racine du
 * disque, parce que `is_writable()` répond côté client sur un partage réseau :
 * une écriture toutes les cinq minutes.
 *
 * Chaque accès passe par un sous-processus borné : un partage réseau qui ne
 * répond plus ne rend pas d'erreur, il fait attendre sans fin, et une
 * écriture faite par PHP lui-même aurait figé `health:check` dans le
 * planificateur, ou un travailleur d'Octane quand la page se rafraîchit.
 */
final class DossierDesSauvegardesCheck extends Check
{
    /**
     * Au-delà, le partage est tenu pour endormi.
     */
    public const int DELAI_EN_SECONDES = 10;

    /**
     * Le libellé de la page « Santé », qui découperait sinon le nom de classe.
     */
    #[\Override]
    protected ?string $label = 'Dossier des sauvegardes';

    private int $delai = self::DELAI_EN_SECONDES;

    /**
     * Le dossier où la sauvegarde range ses archives : celui que « Backups »
     * parcourt et que la page « Sauvegardes » du panneau liste. Lu à chaque
     * appel, comme la racine du disque, qu'un test peut déplacer.
     */
    public static function dossierDesArchives(): string
    {
        return config()->string('filesystems.disks.sauvegardes.root').'/'.config()->string('backup.backup.name');
    }

    /**
     * Le dossier répond-il dans le délai, qu'il existe ou non ? Le contrôle
     * « Backups » parcourt les archives par `glob()`, sans délai : sur un
     * partage endormi, il figerait `health:check` juste après la sonde, et le
     * rouge de celle-ci ne serait ni rangé ni envoyé.
     */
    public static function repond(string $dossier, int $delai = self::DELAI_EN_SECONDES): bool
    {
        try {
            // Un dossier absent répond aussi : « Backups » dira qu'il n'y a pas d'archive.
            Process::timeout($delai)->run(['ls', '-A', $dossier]);
        } catch (ProcessTimedOutException) {
            return false;
        }

        return true;
    }

    #[\Override]
    public function getName(): string
    {
        return 'DossierDesSauvegardes';
    }

    /**
     * Le délai de chaque accès au partage, en secondes.
     */
    public function delai(int $secondes): static
    {
        $this->delai = $secondes;

        return $this;
    }

    public function run(): Result
    {
        // Lue à chaque passage : un disque résolu au démarrage figerait sa racine.
        $dossier = config()->string('filesystems.disks.sauvegardes.root');
        $result = Result::make()->meta(['dossier' => $dossier]);

        // Au nom unique : le contrôle peut tourner dans le planificateur et
        // depuis la page au même instant.
        $sonde = $dossier.'/.sonde-sante-'.bin2hex(random_bytes(6));

        // La racine se crée si elle manque, comme la sauvegarde le ferait. Une
        // commande par processus, sans shell : à l'échéance, le processus tué
        // est celui qui attend le partage, et aucun enfant ne lui survit.
        foreach ([['mkdir', '-p', $dossier], ['touch', $sonde], ['rm', '-f', $sonde]] as $commande) {
            try {
                $sondage = Process::timeout($this->delai)->run($commande);
            } catch (ProcessTimedOutException) {
                return $result->shortSummary('Sans réponse')->failed(sprintf(
                    "Le partage des sauvegardes %s n'a pas répondu en %d s : aucune archive ne peut s'écrire, et chaque accès au dossier attend. "
                    .'Sur le serveur, vérifier que le partage BACKUP_HOST_PATH est monté et que la machine qui le sert répond.',
                    $dossier,
                    $this->delai,
                ));
            }

            if ($sondage->failed()) {
                return $result->shortSummary('Non inscriptible')->failed(sprintf(
                    "Le dossier des sauvegardes %s n'est pas inscriptible par %s (%s) : aucune archive ne peut s'écrire. "
                    ."Sur le serveur, donner le dossier BACKUP_HOST_PATH à l'uid 33 (chown -R 33:33), puis lancer une sauvegarde depuis le panneau.",
                    $dossier,
                    $this->utilisateur(),
                    $this->cause($sondage),
                ));
            }
        }

        return $result->shortSummary('Inscriptible')->ok();
    }

    private function cause(ProcessResult $sondage): string
    {
        $erreur = trim($sondage->errorOutput());

        return $erreur === '' ? 'code '.$sondage->exitCode() : $erreur;
    }

    private function utilisateur(): string
    {
        return function_exists('posix_geteuid') ? 'uid '.posix_geteuid() : "l'utilisateur du conteneur";
    }
}
