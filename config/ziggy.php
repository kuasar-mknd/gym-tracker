<?php

declare(strict_types=1);

/*
 * Ziggy injecte la table des routes nommées dans chaque page. Sans filtre,
 * les 311 routes partaient à tout visiteur, panneau d'administration et API
 * complète compris. Seules les routes que le JavaScript demande sont servies.
 */
return [
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
        'push-subscriptions.*',
        'api.v1.sets.store',
        'api.v1.sets.update',
        'api.v1.sets.destroy',
        'api.v1.workout-lines.store',
        'api.v1.workout-lines.destroy',
        'api.v1.workout-lines.set-order',
        'api.v1.workouts.line-order',
    ],
];
