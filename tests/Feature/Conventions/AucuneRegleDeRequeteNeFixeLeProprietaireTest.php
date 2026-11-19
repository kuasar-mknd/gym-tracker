<?php

declare(strict_types=1);

/*
 * Plusieurs modèles (Fast, Habit, WaterLog…) portent `user_id` dans
 * `$fillable` : la création se fait par la relation du compte, et une requête
 * validée ne doit jamais apporter de propriétaire. Une règle de requête qui
 * le déclarerait (`'user_id' => …`) le ferait changer de main par
 * `$request->validated()`.
 */

it('ne laisse aucune requête déclarer user_id parmi ses règles', function (): void {
    $fautives = [];

    $fichiers = glob(base_path('app/Http/Requests/{,*/,*/*/}*.php'), GLOB_BRACE);
    $fichiers = $fichiers === false ? [] : $fichiers;
    expect(count($fichiers))->toBeGreaterThan(0);

    foreach ($fichiers as $fichier) {
        if (preg_match('/[\'"]user_id[\'"]\s*=>/', (string) file_get_contents($fichier)) === 1) {
            $fautives[] = str_replace(base_path().'/', '', $fichier);
        }
    }

    expect($fautives)->toBe([]);
});
