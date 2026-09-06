<?php

declare(strict_types=1);

/**
 * `filament:upgrade` enchaîne `config:clear`, `route:clear` et `view:clear` :
 * lancé après les caches dans l'entrypoint, il les effaçait à chaque
 * démarrage et l'application tournait sans. Ce qui ne dépend pas de
 * l'environnement se calcule dans l'image ; au démarrage ne restent que la
 * configuration, les routes, les migrations et le moniteur des tâches.
 */
it('ne lance au démarrage aucune commande qui efface un cache', function (): void {
    $entrypoint = (string) file_get_contents(base_path('entrypoint.sh'));

    foreach (['filament:upgrade', 'config:clear', 'route:clear', 'view:clear', 'optimize:clear', 'cache:clear'] as $commande) {
        expect($entrypoint)->not->toContain($commande);
    }

    expect($entrypoint)->toContain('php artisan config:cache')
        ->toContain('php artisan route:cache');
});

it('fige dans l’image ce qui ne dépend pas de l’environnement', function (): void {
    $dockerfile = (string) file_get_contents(base_path('Dockerfile'));
    $entrypoint = (string) file_get_contents(base_path('entrypoint.sh'));

    foreach (['package:discover', 'storage:link', 'filament:assets', 'log-viewer:publish', 'view:cache', 'event:cache'] as $commande) {
        expect($dockerfile)->toContain('php artisan '.$commande);
        expect($entrypoint)->not->toContain($commande);
    }
});
