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

/*
 * La clé privée .p8 d'Apple, en clair dans APPLE_PRIVATE_KEY (#1911). Elle
 * signe à chaque échange un secret client neuf, valable une heure, à la place
 * du jeton qu'il fallait signer à la main et refaire tous les six mois. Une
 * variable tient mal sur plusieurs lignes : les « \n » écrits en toutes lettres
 * redeviennent des retours à la ligne, sans quoi OpenSSL ne lit pas la clé.
 * Vide ou absente, elle vaut null, comme les autres identifiants.
 */
$clePriveeApple = static function (): ?string {
    $cle = env('APPLE_PRIVATE_KEY');

    if (! is_string($cle) || trim($cle) === '') {
        return null;
    }

    return str_replace('\n', "\n", trim($cle));
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

    /*
     * client_id est le Services ID. Le secret se signe avec le trio team_id,
     * key_id et private_key, que le paquet lit ici à chaque échange ;
     * client_secret, un jeton signé à la main, ne sert qu'en son absence.
     */
    'apple' => [
        'client_id' => env('APPLE_CLIENT_ID'),
        'client_secret' => env('APPLE_CLIENT_SECRET'),
        'team_id' => env('APPLE_TEAM_ID'),
        'key_id' => env('APPLE_KEY_ID'),
        'private_key' => $clePriveeApple(),
        'redirect' => $urlDeRappelSociale('apple'),
    ],

];
