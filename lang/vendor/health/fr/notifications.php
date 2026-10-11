<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Textes du courriel d'alerte de santé
|--------------------------------------------------------------------------
|
| Surcharge la traduction française de laravel-health, qui parlait de
| « tests » et collait le deux-points (#2034). Laravel fusionne ce fichier
| clé par clé avec celui du paquet. `laravel_health` est le titre du
| courriel : la vue publiée sous resources/views/vendor/health le lit ici,
| quand celle du paquet l'écrivait en dur.
|
*/

return [
    'laravel_health' => 'Contrôles de santé',

    'check_failed_mail_subject' => 'Certains contrôles de santé de :application_name ont échoué',

    'check_failed_mail_body' => "Les contrôles suivants signalent des avertissements ou des erreurs\u{00A0}:",

    'check_failed_slack_message' => 'Certains contrôles de santé de :application_name ont échoué.',

    'health_results' => 'Bilan de santé',

    'check_results_from' => 'Contrôles passés',
];
