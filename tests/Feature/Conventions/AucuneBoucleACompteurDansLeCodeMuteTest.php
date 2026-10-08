<?php

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;

/*
 * La passe de mutation nocturne remplace `$i++` par `$i--`, ou l'inverse : une
 * boucle à compteur ne s'arrête plus, et son mutant tient un processus jusqu'au
 * délai que Pest accorde à chaque mutant (la passe de référence + 20 %), ou
 * jusqu'à épuiser la mémoire. Aucun test ne peut le tuer vite, puisque la
 * boucle ne rend jamais la main. La tendance hebdomadaire du volume en a coûté
 * 336 s en local (#2004) ; quatre parcours de jours d'App\Actions, de 60 à
 * 177 s chacun en local, près de 580 s cumulées (#2017).
 *
 * Cette garde refuse donc `for`, `while` et `do` dans les espaces de noms que
 * la nuit mute, lus dans .github/workflows/mutation.yml : on y parcourt ce qui
 * est borné par nature, un `range()`, une collection, les jours d'une période
 * (`daysUntil()`). La règle est dans .ai/rules/mutation.md.
 */

/**
 * Les dossiers de app/ que la nuit mute (app/Actions, app/Services…), d'après
 * les parts de sa matrice.
 *
 * @return list<string>
 */
function dossiersQueLaNuitMute(): array
{
    $parts = data_get(Yaml::parseFile(base_path('.github/workflows/mutation.yml')), 'jobs.mutation.strategy.matrix.include');

    if (! is_array($parts) || $parts === []) {
        throw new RuntimeException('mutation.yml ne déclare plus ses parts sous jobs.mutation.strategy.matrix.include : la garde ne sait plus quoi lire.');
    }

    $dossiers = array_map(static function (mixed $part): string {
        $scope = is_array($part) ? ($part['scope'] ?? null) : null;

        if (! is_string($scope) || ! str_starts_with($scope, 'App\\')) {
            throw new RuntimeException('Une part de mutation.yml n’a pas de scope lisible.');
        }

        return 'app/'.explode('\\', $scope)[1];
    }, $parts);

    return array_values(array_unique($dossiers));
}

/**
 * Les boucles à compteur d'un code PHP : `for`, `while` et `do`, avec leur
 * ligne.
 *
 * `TOKEN_PARSE` fait lire le code comme le moteur le lit : une méthode nommée
 * `for` (`ActiveWorkoutService::for()`) ou son appel (`->for($user)`) sont des
 * noms, pas des boucles.
 *
 * @return list<string>
 */
function bouclesACompteurDuCode(string $code): array
{
    $boucles = [];

    foreach (PhpToken::tokenize($code, TOKEN_PARSE) as $jeton) {
        if ($jeton->is([T_FOR, T_WHILE, T_DO])) {
            $boucles[] = sprintf('ligne %d : %s', $jeton->line, $jeton->text);
        }
    }

    return $boucles;
}

it('ne laisse aucune boucle à compteur dans le code que la nuit mute', function (): void {
    $trouvees = [];

    foreach (dossiersQueLaNuitMute() as $dossier) {
        $fichiers = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path($dossier), FilesystemIterator::SKIP_DOTS));

        foreach ($fichiers as $fichier) {
            if (! $fichier instanceof SplFileInfo || $fichier->getExtension() !== 'php') {
                continue;
            }

            $chemin = mb_substr($fichier->getPathname(), mb_strlen(base_path()) + 1);

            foreach (bouclesACompteurDuCode((string) file_get_contents($fichier->getPathname())) as $boucle) {
                $trouvees[] = $chemin.', '.$boucle;
            }
        }
    }

    sort($trouvees);

    expect($trouvees)->toBe([], "Une boucle à compteur donne un mutant qui ne finit jamais : parcourir les jours d'une période (daysUntil()), une collection ou un range() (.ai/rules/mutation.md).");
});

it('lit les dossiers mutés dans la matrice de la passe nocturne', function (): void {
    expect(dossiersQueLaNuitMute())->toBe(['app/Services', 'app/Actions', 'app/Policies']);
});

it('voit les trois formes de boucle, et pas une méthode nommée for', function (): void {
    $code = <<<'PHP'
        <?php
        for ($i = 0; $i < 7; $i++) {}
        while ($i > 0) { $i--; }
        do { $i++; } while ($i < 7);
        $actif = $service->for($user);
        foreach (range(1, 7) as $jour) {}
        final class Service { public function for(int $compte): int { return $compte; } }
        PHP;

    expect(bouclesACompteurDuCode($code))->toBe([
        'ligne 2 : for',
        'ligne 3 : while',
        'ligne 4 : do',
        'ligne 4 : while',
    ]);
});
