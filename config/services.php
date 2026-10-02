<?php

declare(strict_types=1);

/*
 * L'URL de rappel d'un fournisseur social se déduit d'APP_URL quand rien ne la
 * fixe (#1908) : sans elle, Google et Apple refusent l'échange. Une variable
 * vide compte comme absente : docker-compose.prod.yml transmet une chaîne vide
 * pour une variable que la pile ne pose pas, et env() rend alors cette chaîne,
 * pas son défaut. Dérivée d'APP_URL plutôt que relative, l'URL ne dépend pas
 * des en-têtes que le proxy inverse transmet.
 */
$urlDeRappelSociale = static function (string $fournisseur): string {
    $posee = env(strtoupper($fournisseur).'_REDIRECT_URI');

    if (is_string($posee) && $posee !== '') {
        return $posee;
    }

    return rtrim((string) env('APP_URL', 'http://localhost'), '/').'/auth/'.$fournisseur.'/callback';
};

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'github' => [
        'client_id' => env('GITHUB_CLIENT_ID'),
        'client_secret' => env('GITHUB_CLIENT_SECRET'),
        'redirect' => $urlDeRappelSociale('github'),
    ],

    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => $urlDeRappelSociale('google'),
    ],

    'apple' => [
        'client_id' => env('APPLE_CLIENT_ID'),
        'client_secret' => env('APPLE_CLIENT_SECRET'),
        'redirect' => $urlDeRappelSociale('apple'),
    ],

];
