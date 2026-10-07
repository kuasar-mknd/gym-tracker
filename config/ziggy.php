<?php

declare(strict_types=1);

/*
 * Ziggy injecte la table des routes nommées dans chaque page. Sans filtre,
 * les 311 routes partaient à tout visiteur, panneau d'administration et API
 * complète compris. Seules les routes que le JavaScript demande sont servies.
 *
 * `@routes` n'écrit que cette table, pas la fonction `route()` : celle-ci
 * ajoutait 21 Ko de script en ligne à chaque page complète (7 Ko compressés,
 * 70 % du document), alors que le bundle la porte déjà, mise en cache pour un
 * an. `resources/js/Utils/routeGlobale.js` la pose en globale (#1969).
 */
return [
    'skip-route-function' => true,

    'only' => [
        'dashboard',
        'login',
        'logout',
        'register',
        'password.*',
        'verification.send',
        'social.redirect',
        'profile.*',
        'workouts.*',
        'exercises.*',
        'templates.*',
        'stats.*',
        'tools.*',
        'plates.*',
        'habits.*',
        'goals.*',
        'supplements.*',
        'daily-journals.*',
        'body-parts.*',
        'body-measurements.*',
        'achievements.*',
        'calendar.*',
        'notifications.*',
        'shortcuts.index',
        'push-subscriptions.*',
        'erreurs-navigateur.store',
        'api.v1.sets.store',
        'api.v1.sets.update',
        'api.v1.sets.destroy',
        'api.v1.workout-lines.store',
        'api.v1.workout-lines.destroy',
        'api.v1.workout-lines.set-order',
        'api.v1.workouts.line-order',
    ],
];
