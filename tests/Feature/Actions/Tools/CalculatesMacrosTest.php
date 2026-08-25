<?php

declare(strict_types=1);

/*
 * Le calcul des macros etait large ment couvert (86 % de mutants tues) mais
 * treize survivants restaient, tous du meme genre : des bornes et des arrondis
 * qu'aucun des six jeux de donnees existants ne mettait en tension.
 *
 * Les jeux existants tombent tous sur des produits ronds ou sur des demis :
 * `round`, `floor` et `ceil` y rendent la meme chose. Il ne manquait donc pas
 * de la couverture, il manquait des entrees ou les trois divergent.
 *
 * Ce que chaque survivant laissait passer :
 *
 * - ligne 26, `round` du TDEE devenu `ceil` : toute depense journaliere non
 *   entiere arrondie vers le haut, donc un objectif calorique systematiquement
 *   surestime.
 * - ligne 60, le plancher masculin de 1500 kcal a 1499 ou 1501 : jamais
 *   atteint par les jeux existants, donc jamais verifie. Seul le plancher
 *   feminin de 1200 l'etait.
 * - lignes 72 et 73, `round` des proteines et des lipides devenu `floor` ou
 *   `ceil` : un gramme de biais sur chaque calcul, dans un sens fixe.
 * - ligne 77, la borne `< 0` devenue `<= 0`, `< 1` ou `< -1` : c'est le
 *   declencheur du rattrapage quand les proteines et les lipides depassent deja
 *   la cible. Le decaler fait rattraper un cas qui n'en a pas besoin, ou
 *   l'inverse.
 * - ligne 78, `round` des lipides rattrapes devenu `ceil`.
 * - ligne 79, les coefficients 4 (kcal/g de proteine) et 9 (kcal/g de lipide)
 *   devenus 5 et 10 : le reste calorique s'effondre et les glucides tombent a
 *   zero.
 * - ligne 85, `round` des glucides devenu `ceil`.
 *
 * Trois de ces mutants (ligne 77) ne sont atteignables que sous les 1200 kcal,
 * c'est-a-dire sous le plancher que `calculateTargetCalories` impose : ils sont
 * donc exerces par un appel direct a `calculateMacros`, comme le font deja les
 * tests unitaires voisins. Les autres passent par l'action reelle.
 *
 * Toutes les entrees sont posees. Aucune ne vient d'une fabrique.
 */

use App\Actions\Tools\Concerns\CalculatesMacros;
use App\Actions\Tools\CreateMacroCalculationAction;
use App\Models\User;

$calculateur = new class()
{
    use CalculatesMacros;

    /**
     * @return array<string, float|int>
     */
    public function macros(float $caloriesCibles, float $poids): array
    {
        return $this->calculateMacros($caloriesCibles, $poids);
    }
};

/*
 * -- Par l'action reelle : le TDEE et le plancher masculin ---------------------
 */

it('arrondit le TDEE au plus proche, pas au plafond', function (): void {
    $utilisateur = User::factory()->create();

    // 1790 kcal de metabolisme de base (10x81 + 6,25x180 - 5x30 + 5) fois 1,375
    // pour l'activite « light » : 2461,25 exactement. C'est la seule entree ou
    // `round` (2461) et `ceil` (2462) se separent — tous les jeux existants
    // tombaient sur un entier ou sur un demi, ou les deux coincident.
    $calcul = app(CreateMacroCalculationAction::class)->execute($utilisateur, [
        'gender' => 'male',
        'age' => 30,
        'height' => 180,
        'weight' => 81,
        'activity_level' => 'light',
        'goal' => 'maintain',
    ]);

    expect($calcul->tdee)->toBe(2461);
    expect($calcul->target_calories)->toBe(2461);
});

it('ne descend pas un homme sous 1500 kcal', function (): void {
    $utilisateur = User::factory()->create();

    // 1142,5 x 1,2 = 1371 de TDEE, moins 500 pour une seche : 871. Le plancher
    // masculin le releve a 1500 — la valeur exacte, que rien ne verifiait :
    // aucun jeu existant ne descendait un homme sous son plancher, seul celui
    // des femmes (1200) etait touche.
    $calcul = app(CreateMacroCalculationAction::class)->execute($utilisateur, [
        'gender' => 'male',
        'age' => 60,
        'height' => 150,
        'weight' => 50,
        'activity_level' => 'sedentary',
        'goal' => 'cut',
    ]);

    expect($calcul->tdee)->toBe(1371);
    expect($calcul->target_calories)->toBe(1500);
});

/*
 * -- Par appel direct : les arrondis et la borne du rattrapage -----------------
 */

it('arrondit proteines et lipides au plus proche, dans les deux sens', function () use ($calculateur): void {
    // 82,3 kg : proteines 164,6 -> 165 (et non 164 par le bas), lipides 74,07
    // -> 74 (et non 75 par le haut).
    $basse = $calculateur->macros(2200.0, 82.3);

    expect($basse['protein'])->toBe(165.0);
    expect($basse['fat'])->toBe(74.0);

    // 82,6 kg : proteines 165,2 -> 165 aussi, mais par le bas cette fois (et
    // non 166 par le haut). Les deux poids donnent la meme proteine, l'un en
    // arrondissant vers le haut, l'autre vers le bas : c'est ce qui distingue
    // `round` de `floor` et de `ceil` a la fois.
    $haute = $calculateur->macros(2200.0, 82.6);

    expect($haute['protein'])->toBe(165.0);
    expect($haute['fat'])->toBe(74.0);
});

it('arrondit les glucides au plus proche, pas au plafond', function () use ($calculateur): void {
    // 1757 - (160x4 + 72x9) = 469 kcal restantes, soit 117,25 g de glucides.
    // 117 au plus proche, 118 au plafond.
    $resultat = $calculateur->macros(1757.0, 80.0);

    expect($resultat['carbs'])->toBe(117.0);
});

it('ne declenche pas le rattrapage quand il ne reste exactement rien', function () use ($calculateur): void {
    // 32 kg : 64 g de proteines (256 kcal) et 29 g de lipides (261 kcal), soit
    // 517 kcal — exactement la cible. Le reste vaut zero, donc la condition
    // `< 0` est fausse et les lipides restent a 29.
    //
    // Sous `<= 0` ou `< 1`, le rattrapage s'execute et le plancher de 30 g les
    // releve a 30. C'est la seule facon de separer ces trois bornes : il faut
    // un reste nul ET des lipides sous le plancher, ce qui n'arrive qu'en
    // dessous de 33 kg — donc hors d'atteinte de l'action, dont la cible ne
    // descend jamais sous 1200 kcal.
    $resultat = $calculateur->macros(517.0, 32.0);

    expect($resultat['fat'])->toBe(29.0);
    expect($resultat['protein'])->toBe(64.0);
});

it('declenche le rattrapage des qu il manque une seule calorie', function () use ($calculateur): void {
    // Meme poids, une calorie de moins : le reste vaut -1, la condition est
    // vraie, le rattrapage s'execute et le plancher de 30 g releve les lipides.
    // Sous `< -1`, il ne s'executerait pas et ils resteraient a 29.
    $resultat = $calculateur->macros(516.0, 32.0);

    expect($resultat['fat'])->toBe(30);
});

it('recalcule les lipides au plus proche et en tire les glucides restants', function () use ($calculateur): void {
    // 90 kg pour 1210 kcal : 180 g de proteines (720 kcal) et 81 g de lipides
    // (729 kcal) depassent la cible de 239 kcal, donc le rattrapage s'execute.
    // (1210 - 720) / 9 = 54,44 -> 54 g au plus proche, 55 au plafond.
    $resultat = $calculateur->macros(1210.0, 90.0);

    expect($resultat['fat'])->toBe(54.0);

    // Et il reste 1210 - (720 + 486) = 4 kcal, soit 1 g de glucides. Ce gramme
    // tient les deux coefficients de la ligne 79 : avec 5 kcal/g de proteine ou
    // 10 kcal/g de lipide, le reste devient tres negatif et les glucides
    // tombent a 0.
    expect($resultat['carbs'])->toBe(1.0);
});
