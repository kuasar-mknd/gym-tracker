<?php

declare(strict_types=1);

use App\Enums\ExerciseCategory;

/**
 * Le graphique de répartition musculaire attribue une couleur par catégorie, en
 * indexant un tableau littéral. Une catégorie ajoutée à l'enum sans couleur
 * correspondante donne une part sans couleur définie : Chart.js retombe sur son
 * défaut, et la part cesse de se distinguer des autres.
 *
 * C'est arrivé — la palette est restée à six couleurs quand l'enum est passé à
 * sept (#1382). Rien côté front ne pouvait le signaler, le composant n'ayant
 * aucune connaissance de l'enum : le garde doit donc vivre ici, du côté qui
 * connaît la source de vérité.
 */
it('donne une couleur à chaque catégorie d’exercice', function (): void {
    $component = base_path('resources/js/Components/Stats/MuscleDistributionChart.vue');

    expect($component)->toBeReadableFile();

    preg_match('/backgroundColor:\s*\[(.*?)\]/s', (string) file_get_contents($component), $matches);

    // Un `expect()` de Pest ne restreint pas le type pour l'analyse statique :
    // le repli explicite est ce qui rend l'accès sûr, pas l'assertion.
    $block = $matches[1] ?? '';

    expect($block)->not->toBe('', 'le tableau backgroundColor est introuvable dans le composant');

    preg_match_all('/#[0-9A-Fa-f]{3,8}/', $block, $colours);

    $palette = $colours[0];
    $categories = ExerciseCategory::cases();

    expect($palette)
        ->toHaveCount(
            count($categories),
            sprintf(
                'la palette compte %d couleurs pour %d catégories : %s',
                count($palette),
                count($categories),
                implode(', ', array_column($categories, 'value'))
            )
        )
        ->and(array_unique($palette))->toHaveCount(
            count($palette),
            'deux catégories partageraient la même couleur'
        );
});
