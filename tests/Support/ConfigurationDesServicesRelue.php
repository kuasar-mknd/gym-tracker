<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * Relit config/services.php sous des variables d'environnement données, comme
 * `config:cache` le fait au démarrage d'un conteneur.
 *
 * La suite charge la configuration une fois, avant le test : poser une
 * variable ensuite ne change rien à `config()`. Les règles que
 * config/services.php applique aux variables (URL de rappel déduite
 * d'`APP_URL`, clé .p8 sur une ligne, trio d'Apple complet ou rien) ne se
 * vérifient donc qu'en relisant le fichier. Les variables connues sont vidées
 * avant la lecture, puis toutes rendues telles qu'elles étaient, que la
 * lecture réussisse ou non.
 */
final class ConfigurationDesServicesRelue
{
    /**
     * Les variables que la lecture vide d'abord, posées ou non par la machine.
     */
    private const array VARIABLES_CONNUES = [
        'APP_URL',
        'GOOGLE_REDIRECT_URI',
        'GITHUB_REDIRECT_URI',
        'APPLE_REDIRECT_URI',
        'APPLE_CLIENT_ID',
        'APPLE_CLIENT_SECRET',
        'APPLE_TEAM_ID',
        'APPLE_KEY_ID',
        'APPLE_PRIVATE_KEY',
    ];

    /**
     * @param  array<array-key, mixed>  $variables
     * @return array<string, mixed>
     */
    public static function avec(array $variables): array
    {
        $avant = [];

        foreach (self::VARIABLES_CONNUES as $nom) {
            $avant[$nom] = [$_SERVER[$nom] ?? null, $_ENV[$nom] ?? null, getenv($nom)];
            unset($_SERVER[$nom], $_ENV[$nom]);
            putenv($nom);
        }

        foreach ($variables as $variable => $valeur) {
            if (! is_string($variable) || ! is_string($valeur)) {
                continue;
            }

            $_SERVER[$variable] = $_ENV[$variable] = $valeur;
            putenv("{$variable}={$valeur}");
        }

        try {
            /** @var array<string, mixed> $configuration */
            $configuration = require config_path('services.php');

            return $configuration;
        } finally {
            foreach ($avant as $nom => [$serveur, $environnement, $processus]) {
                unset($_SERVER[$nom], $_ENV[$nom]);
                putenv($processus === false ? $nom : "{$nom}={$processus}");

                if ($serveur !== null) {
                    $_SERVER[$nom] = $serveur;
                }

                if ($environnement !== null) {
                    $_ENV[$nom] = $environnement;
                }
            }
        }
    }
}
