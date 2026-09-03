<?php

declare(strict_types=1);

/**
 * Trois fichiers d'instructions (CLAUDE.md, AGENTS.md, GEMINI.md) portaient
 * chacun ~450 lignes de consignes Boost, copiées à des dates différentes :
 * l'un annonçait Pest 3 et Tailwind 3 quand l'autre en était à Inertia 2.
 * Depuis le 2026-09-03, CLAUDE.md est la seule source (Laravel Boost ne
 * génère plus que lui) et les deux autres ne sont que des renvois.
 */
it('garde AGENTS.md et GEMINI.md réduits à un renvoi vers CLAUDE.md', function (): void {
    foreach (['AGENTS.md', 'GEMINI.md'] as $fichier) {
        $contenu = file_get_contents(base_path($fichier));

        expect($contenu)->toBeString()
            ->and($contenu)->toContain('CLAUDE.md')
            ->and(substr_count((string) $contenu, "\n"))->toBeLessThanOrEqual(10)
            ->and($contenu)->not->toContain('laravel-boost-guidelines');
    }
});

it('ne laisse Laravel Boost générer des consignes que pour CLAUDE.md', function (): void {
    $boost = json_decode((string) file_get_contents(base_path('boost.json')), true, 512, JSON_THROW_ON_ERROR);
    $agents = is_array($boost) ? ($boost['agents'] ?? null) : null;

    expect($agents)->toBe(['claude_code']);
});
