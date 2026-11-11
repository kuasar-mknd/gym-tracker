<?php

declare(strict_types=1);

namespace Tests\Support;

use Symfony\Component\Process\Process;

/**
 * Les fichiers texte du dépôt, ceux que Git suit ou suivrait : ceux de son
 * index, fichiers ajoutés de force malgré le .gitignore compris, et ceux
 * qu'il ajouterait, non suivis et non ignorés, tels que `git ls-files` les
 * liste. Les fichiers binaires et les autres copies du dépôt
 * (`.claude/worktrees`) sont laissés de côté. Les gardes qui lisent tout le
 * dépôt partent d'ici (`LInfrastructureNeSeNommePasTest`,
 * `AucunJetonDeDiagnosticNEstSuiviTest`,
 * `UneReponseDuDiagnosticNEntrePasDansLeDepotTest`).
 *
 * Git dit lui-même ce qui est suivi (#2032). Un parcours du disque qui
 * écartait les dépendances par le nom de leur dossier (`vendor`) écartait
 * aussi les dossiers de ce nom que le dépôt suit (`resources/views/vendor`,
 * `lang/vendor`), et le filtre du .gitignore, les fichiers ajoutés de
 * force : les gardes ne lisaient ni les gabarits des courriels, ni
 * `public/robots.txt`. Sans Git, la lecture lève : une garde qui ne lirait
 * rien passerait.
 */
final class FichiersSuivis
{
    /** Le dossier des autres copies du dépôt, qui ne sont pas lui. */
    private const string AUTRES_COPIES = '.claude/worktrees/';

    /**
     * @return array<string, string> contenu par chemin relatif
     */
    public static function contenus(): array
    {
        $contenus = [];

        foreach (self::chemins() as $chemin) {
            $fichier = base_path($chemin);

            if (str_starts_with($chemin, self::AUTRES_COPIES) || ! is_file($fichier)) {
                continue;
            }

            $contenu = (string) file_get_contents($fichier);

            if (str_contains(substr($contenu, 0, 8000), "\0")) {
                continue;
            }

            $contenus[$chemin] = $contenu;
        }

        return $contenus;
    }

    /**
     * Les chemins, relatifs à la racine du dépôt, que Git suit ou ajouterait.
     * Un dépôt imbriqué ne s'y liste que par son dossier, laissé de côté ; un
     * fichier suivi mais effacé de l'arbre n'est plus un fichier.
     *
     * @return list<string>
     */
    private static function chemins(): array
    {
        $git = new Process(['git', 'ls-files', '-z', '--cached', '--others', '--exclude-standard'], base_path());
        $git->mustRun();

        return array_values(array_filter(
            explode("\0", $git->getOutput()),
            static fn (string $chemin): bool => $chemin !== '' && ! str_ends_with($chemin, '/'),
        ));
    }
}
