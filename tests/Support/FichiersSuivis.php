<?php

declare(strict_types=1);

namespace Tests\Support;

use Symfony\Component\Finder\Finder;

/**
 * Les fichiers texte du dépôt, ceux que Git suivrait : .gitignore respecté,
 * dépendances et fichiers produits écartés, fichiers binaires laissés de
 * côté. Les gardes qui lisent tout le dépôt partent d'ici
 * (`LInfrastructureNeSeNommePasTest`, `AucunJetonDeDiagnosticNEstSuiviTest`,
 * `UneReponseDuDiagnosticNEntrePasDansLeDepotTest`).
 */
final class FichiersSuivis
{
    /**
     * @return array<string, string> contenu par chemin relatif
     */
    public static function contenus(): array
    {
        $fichiers = Finder::create()
            ->files()
            ->in(base_path())
            ->ignoreDotFiles(false)
            ->ignoreVCS(true)
            ->ignoreVCSIgnored(true)
            ->exclude(['vendor', 'node_modules', 'storage', 'bootstrap/cache', 'public/build', '.claude/worktrees']);

        $contenus = [];

        foreach ($fichiers as $fichier) {
            $contenu = (string) file_get_contents($fichier->getPathname());

            if (str_contains(substr($contenu, 0, 8000), "\0")) {
                continue;
            }

            $contenus[$fichier->getRelativePathname()] = $contenu;
        }

        return $contenus;
    }
}
