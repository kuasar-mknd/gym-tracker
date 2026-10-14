<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Support\Facades\File;

/**
 * Un build en place pour les tests du diagnostic (#2032) : un dossier public
 * à part, propre au processus, où `build/assets` ne contient que les
 * fichiers donnés. Le diagnostic ne rend le fichier d'une erreur navigateur
 * que s'il est du build en place ; le vrai `public/build` dépend d'un
 * `npm run build` et change à chaque construction, un test n'en dépend pas.
 */
final class BuildDuDiagnostic
{
    /**
     * Fait servir à l'application un build qui ne contient que ces fichiers.
     *
     * @param  list<string>  $fichiers  des noms sous `build/assets`
     */
    public static function avec(array $fichiers): void
    {
        $public = self::dossier();
        File::ensureDirectoryExists($public.'/build/assets');

        foreach ($fichiers as $fichier) {
            File::put($public.'/build/assets/'.$fichier, '');
        }

        app()->usePublicPath($public);
    }

    public static function retirer(): void
    {
        File::deleteDirectory(self::dossier());
    }

    private static function dossier(): string
    {
        return sys_get_temp_dir().'/build-du-diagnostic-'.getmypid();
    }
}
