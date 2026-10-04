<?php

declare(strict_types=1);

namespace Tests\Support;

use Closure;
use Illuminate\Support\Facades\File;

/**
 * Un partage réseau qui ne répond plus, tel que les sous-processus le voient.
 *
 * Il ne rend pas d'erreur : chaque appel système qui le touche attend, sans
 * fin. Un dossier de commandes qui dorment, placé en tête du PATH, remplace
 * `ls`, `mkdir` ou `touch` du même nom, et fait attendre ainsi tout ce qui
 * passe par elles (#1812, #1929).
 */
final class PartageEndormi
{
    /**
     * Un dossier jetable sous storage où chaque commande donnée dort trente
     * secondes. À effacer par le test (`File::deleteDirectory()`).
     *
     * @param  list<string>  $commandes
     */
    public static function commandes(array $commandes): string
    {
        $dossier = storage_path('framework/testing/partage-endormi-'.uniqid());
        File::ensureDirectoryExists($dossier);

        foreach ($commandes as $commande) {
            File::put($dossier.'/'.$commande, "#!/bin/sh\nexec sleep 30\n");
            chmod($dossier.'/'.$commande, 0755);
        }

        return $dossier;
    }

    /**
     * Lance le test avec le dossier donné en tête du PATH que les
     * sous-processus héritent, puis rend le PATH d'origine.
     *
     * @template T
     *
     * @param  Closure(): T  $test
     * @return T
     */
    public static function enTeteDuPath(string $dossier, Closure $test): mixed
    {
        $origine = (string) getenv('PATH');
        $dansEnv = array_key_exists('PATH', $_ENV);
        $chemin = $dossier.':'.$origine;

        putenv('PATH='.$chemin);
        $_SERVER['PATH'] = $chemin;

        if ($dansEnv) {
            $_ENV['PATH'] = $chemin;
        }

        try {
            return $test();
        } finally {
            putenv('PATH='.$origine);
            $_SERVER['PATH'] = $origine;

            if ($dansEnv) {
                $_ENV['PATH'] = $origine;
            }
        }
    }
}
