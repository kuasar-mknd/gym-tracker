<?php

declare(strict_types=1);

/*
 * `User` porte `HasApiTokens` : `Sanctum::actingAs()` appelle
 * `withAccessToken()` sur le compte, et dix-huit fichiers de test s'en
 * servent. Mais aucun émetteur de jeton personnel n'existe, et l'API ne
 * vérifie aucune capacité (`abilities`) ni ne révoque un jeton au changement
 * de mot de passe : un jeton émis survivrait à ce changement. Tant que ces
 * deux garde-fous manquent, rien dans `app/` n'émet de jeton.
 */

it('n’émet aucun jeton personnel dans app', function (): void {
    $emetteurs = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path('app'))) as $fichier) {
        if (! $fichier instanceof SplFileInfo || $fichier->getExtension() !== 'php') {
            continue;
        }

        if (preg_match('/->\s*createToken\s*\(|->\s*tokens\s*\(\s*\)\s*->\s*create|PersonalAccessToken::(?:create|query|where)/', (string) file_get_contents($fichier->getPathname())) === 1) {
            $emetteurs[] = str_replace(base_path().'/', '', $fichier->getPathname());
        }
    }

    expect($emetteurs)->toBe([]);
});
