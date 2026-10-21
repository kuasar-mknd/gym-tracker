<?php

declare(strict_types=1);

use Illuminate\Routing\Route as RouteLaravel;
use Illuminate\Support\Facades\Route;

/*
 * Une séance démarrée sans réseau s'ouvre à l'adresse de sa clé (#2049), que
 * la page et la coquille hors ligne écrivent sans Ziggy : la coquille la lit à
 * froid, avant que la table des routes soit là
 * (`CHEMIN_D_UNE_SEANCE_EN_ATTENTE` et le motif d'une clé,
 * `resources/js/Utils/seanceEnAttente.js`). Une route déplacée, ou une clé que
 * la route n'accepterait plus, laisserait l'appareil ouvrir une adresse que le
 * serveur ne résout pas. Le module se lit comme un texte.
 */

/**
 * Ce que le module déclare sous cette forme, ou un échec qui le dit.
 */
function seanceEnAttenteDeclaration(string $forme, string $nom): string
{
    $module = (string) file_get_contents(resource_path('js/Utils/seanceEnAttente.js'));

    expect(preg_match($forme, $module, $declaration))->toBe(1, "Le module ne déclare plus `{$nom}` sous sa forme.");

    return $declaration[1] ?? '';
}

it('écrit le chemin d’une séance en attente comme la route le donne', function (): void {
    $chemin = seanceEnAttenteDeclaration(
        "/^export const CHEMIN_D_UNE_SEANCE_EN_ATTENTE = '([^']+)'$/m",
        'CHEMIN_D_UNE_SEANCE_EN_ATTENTE',
    );

    expect($chemin.'abc')->toBe(route('workouts.en-attente', ['cle' => 'abc'], false));
});

it('n’accepte que les clés que la route accepte', function (): void {
    $motif = seanceEnAttenteDeclaration('#^const MOTIF_DE_LA_CLE = /\^(.+)\$/$#m', 'MOTIF_DE_LA_CLE');
    $route = Route::getRoutes()->getByName('workouts.en-attente');

    expect($route)->toBeInstanceOf(RouteLaravel::class);

    /** @var RouteLaravel $route */
    expect($motif)->toBe($route->wheres['cle'] ?? null)
        ->and($route->methods())->toContain('GET')
        ->and($route->gatherMiddleware())->toContain('auth');
});
