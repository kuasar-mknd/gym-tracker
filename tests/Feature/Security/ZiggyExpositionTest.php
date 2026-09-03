<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Tighten\Ziggy\Ziggy;

/**
 * Ziggy injecte la table des routes nommées dans chaque page. Sans liste
 * blanche, les 310 routes partaient à tout visiteur, panneau d'administration
 * et API complète compris (33 Ko par page). Deux garde-fous : rien de
 * sensible ne sort, et tout ce que le JavaScript demande est bien servi.
 */

/**
 * @return list<string>
 */
function nomsDesRoutesServiesParZiggy(): array
{
    $routes = new Ziggy()->toArray()['routes'] ?? [];

    return is_array($routes) ? array_map(strval(...), array_keys($routes)) : [];
}

test('la table Ziggy ne contient aucune route d administration ni d API hors des sept servies', function (): void {
    $noms = nomsDesRoutesServiesParZiggy();

    $sensibles = array_values(array_filter(
        $noms,
        fn (string $nom): bool => Str::is(['filament.*', 'horizon.*', 'pulse.*', 'telescope.*', 'l5-swagger.*', 'api.*'], $nom)
            && ! in_array($nom, [
                'api.v1.sets.store', 'api.v1.sets.update', 'api.v1.sets.destroy',
                'api.v1.workout-lines.store', 'api.v1.workout-lines.destroy', 'api.v1.workout-lines.set-order',
                'api.v1.workouts.line-order',
            ], true),
    ));

    expect($sensibles)->toBe([]);
    expect(count($noms))->toBeLessThanOrEqual(120);
    expect(strlen(json_encode(new Ziggy()->toArray(), JSON_THROW_ON_ERROR)))->toBeLessThanOrEqual(12000);
});

test('chaque route nommée que le JavaScript demande est servie par Ziggy', function (): void {
    $servies = nomsDesRoutesServiesParZiggy();
    $demandees = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(resource_path('js'))) as $fichier) {
        if (! $fichier instanceof SplFileInfo || ! $fichier->isFile() || ! in_array($fichier->getExtension(), ['js', 'vue'], true)) {
            continue;
        }

        // route('nom') et les tables de navigation `route: 'nom'`.
        preg_match_all("/(?:route\\(\\s*|route:\\s*)['\"]([a-z0-9._-]+)['\"]/", (string) file_get_contents($fichier->getPathname()), $trouvees);
        $demandees = [...$demandees, ...$trouvees[1]];
    }

    $demandees = array_values(array_unique($demandees));

    expect($demandees)->not->toBe([]);
    expect(array_values(array_diff($demandees, $servies)))->toBe([]);
});

/**
 * Un appel `route('nom', { cle: … })` qui n'apporte pas un paramètre requis de
 * la route ne part jamais : Ziggy lève une erreur côté client, sans requête ni
 * trace réseau. C'est ainsi qu'une suppression d'exercice a cessé de partir
 * quand `{workout_line}` est devenu `{workoutLine}` (#1689). Les clés en trop
 * sont légitimes : Ziggy les envoie en chaîne de requête.
 */
test('chaque appel route() du JavaScript apporte les paramètres requis de la route', function (): void {
    $routes = app('router')->getRoutes();
    $manquants = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(resource_path('js'))) as $fichier) {
        if (! $fichier instanceof SplFileInfo || ! $fichier->isFile() || ! in_array($fichier->getExtension(), ['js', 'vue'], true)) {
            continue;
        }

        preg_match_all("/route\\(\\s*['\"]([a-z0-9._-]+)['\"]\\s*,\\s*\\{([^}]*)\\}/", (string) file_get_contents($fichier->getPathname()), $appels, PREG_SET_ORDER);

        foreach ($appels as [, $nom, $objet]) {
            $route = $routes->getByName($nom);

            if ($route === null) {
                continue; // couvert par le test précédent
            }

            $cles = [];

            foreach (explode(',', $objet) as $morceau) {
                $cle = trim(explode(':', $morceau, 2)[0]);

                if ($cle !== '') {
                    $cles[] = $cle;
                }
            }

            preg_match_all('/\\{(\\w+)\\?\\}/', $route->uri(), $optionnels);
            /** @var list<string> $noms */
            $noms = array_values(array_filter($route->parameterNames(), is_string(...)));
            $requis = array_diff($noms, $optionnels[1]);

            foreach ($requis as $parametre) {
                if (! in_array($parametre, $cles, true)) {
                    $manquants[] = basename($fichier->getPathname()).' → '.$nom.' sans « '.$parametre.' » (reçu : '.implode(', ', $cles).')';
                }
            }
        }
    }

    expect($manquants)->toBe([]);
});
