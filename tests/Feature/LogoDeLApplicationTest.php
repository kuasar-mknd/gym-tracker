<?php

declare(strict_types=1);

use Illuminate\Notifications\Messages\MailMessage;

use function Pest\Laravel\get;

/*
 * Le logo de l'application hors de Vue : le panneau et les courriels.
 *
 * L'application le trace depuis resources/js/Utils/dessinDuLogo.js (Vitest le
 * vérifie), ses icônes se vérifient dans IconesDeLApplicationTest. Ici, les
 * deux endroits que rend Laravel, qui n'écrivaient que le nom.
 */

/**
 * Le premier groupe que capture un motif dans un texte, ou `null`.
 */
function logoCapture(string $motif, string $texte): ?string
{
    return preg_match($motif, $texte, $trouve) === 1 ? ($trouve[1] ?? null) : null;
}

/**
 * Le chemin de public/ que sert une adresse absolue de l'application.
 */
function logoFichierServi(string $adresse): string
{
    $chemin = parse_url($adresse, PHP_URL_PATH);

    expect($chemin)->toBeString("« {$adresse} » n'a pas de chemin.");

    /** @var string $chemin */
    return public_path(ltrim($chemin, '/'));
}

it('montre le logo seul dans l’en-tête du panneau, nommé par son texte de remplacement', function (): void {
    $page = (string) get('/backoffice/login')->assertOk()->getContent();
    $marque = logoCapture('/<div[^>]*class="fi-logo"[^>]*>(.*?)<\/div>/s', $page);

    expect($marque)->toBeString('Le panneau ne pose plus sa marque dans `.fi-logo`.');

    /*
     * Le logo se montre seul, à la demande du propriétaire : son texte de
     * remplacement nomme le lien qui l'entoure, et aucun nom écrit à côté ne
     * le répète. Filament, sans `brandLogo`, n'écrivait que le nom.
     */
    $logo = logoCapture('/<img src="([^"]+)" alt="GymTracker"/', (string) $marque);

    expect($logo)->toBe(asset('logo.svg'), 'Le panneau ne montre plus public/logo.svg nommé « GymTracker ».')
        ->and(trim(strip_tags((string) $marque)))->toBe('', 'Le panneau écrit un nom à côté du logo.')
        ->and(is_file(logoFichierServi((string) $logo)))->toBeTrue();
});

it('pose le logo dans l’en-tête des courriels', function (): void {
    $courriel = (string) new MailMessage()->line('Bonjour.')->render();
    $entete = logoCapture('/<td class="header"[^>]*>(.*?)<\/td>/s', $courriel);

    expect($entete)->toBeString('Le courriel n’a plus son en-tête.');

    /*
     * Un client de messagerie ne lit pas un SVG, et le courriel se lit hors de
     * l'application : une adresse absolue vers un PNG qui existe. Le logo
     * se montre seul, nommé par son texte de remplacement, qu'un client qui
     * bloque les images affiche à sa place.
     */
    $logo = logoCapture('/<img src="([^"]+)"[^>]*\balt="GymTracker"/', (string) $entete);

    expect($logo)->toBe(url('/pwa-64x64.png'), 'L’en-tête des courriels ne montre plus le logo nommé « GymTracker ».')
        ->and(is_file(logoFichierServi((string) $logo)))->toBeTrue()
        ->and(trim(strip_tags((string) $entete)))->toBe('', 'L’en-tête des courriels écrit un nom à côté du logo.');
});
