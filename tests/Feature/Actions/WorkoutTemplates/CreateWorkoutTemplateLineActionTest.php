<?php

declare(strict_types=1);

/*
 * `CreateWorkoutTemplateLineAction` n'avait aucun test a elle : elle n'etait
 * traversee que par les tests du controleur d'API, qui verifient le code de
 * statut et la ressource rendue. Treize de ses quatorze mutants survivaient
 * (score 7,1 %) — autrement dit, tout dans cette action pouvait etre reecrit
 * sans qu'une assertion bouge.
 *
 * Ce que chaque famille laissait passer :
 *
 * - ligne 24, l'autorisation : la retirer entierement, ou lui retirer le modele
 *   qu'elle porte (`WorkoutTemplateLine::create($user, null)` rend `true` par
 *   defaut), ou lui retirer la classe (le garde tombe alors sur
 *   `WorkoutTemplatePolicy::create()`, qui rend `true` sans regarder personne).
 *   Dans les trois cas, n'importe qui ajoutait une ligne au modele d'un autre.
 *
 * - ligne 28, la numerotation : `+ 1` devenu `- 1`, `0` devenu `1` ou `-1`,
 *   `1` devenu `0` ou `2`, le ternaire inverse, le `===` devenu `!==`. Toutes
 *   ces reecritures rendent un ordre faux — la premiere ligne numerotee 1, ou
 *   la suivante numerotee comme la precedente — c'est-a-dire un modele dont les
 *   exercices s'affichent dans le desordre ou se chevauchent.
 *
 * - ligne 46, la fusion : sans `array_merge`, ou sans l'entree `order`, l'ordre
 *   calcule n'est jamais passe a la creation et la colonne retombe sur son
 *   DEFAULT 0. Toutes les lignes d'un modele porteraient alors l'ordre 0.
 *
 * Les ordres sont poses (4 puis 1) et jamais tires : la fabrique tire `order`
 * entre 0 et 100, donc un test qui comparerait a ce qu'elle a produit
 * dependrait du hasard — et ne distinguerait ni `max` de `count`, ni `max` du
 * dernier insere.
 */

use App\Actions\WorkoutTemplates\CreateWorkoutTemplateLineAction;
use App\Models\Exercise;
use App\Models\User;
use App\Models\WorkoutTemplate;
use App\Models\WorkoutTemplateLine;
use Illuminate\Auth\Access\AuthorizationException;

it('refuse d ajouter une ligne au modele d un autre utilisateur', function (): void {
    $proprietaire = User::factory()->create();
    $intrus = User::factory()->create();
    $modele = WorkoutTemplate::factory()->create(['user_id' => $proprietaire->id]);
    $exercice = Exercise::factory()->create();

    $this->actingAs($intrus);

    expect(fn () => app(CreateWorkoutTemplateLineAction::class)->execute([
        'workout_template_id' => $modele->id,
        'exercise_id' => $exercice->id,
    ]))->toThrow(AuthorizationException::class);

    // Et rien n'a ete ecrit : une exception levee apres l'insertion protegerait
    // le code de statut sans proteger la table.
    $this->assertDatabaseCount('workout_template_lines', 0);
});

it('laisse le proprietaire ajouter une ligne a son modele', function (): void {
    $proprietaire = User::factory()->create();
    $modele = WorkoutTemplate::factory()->create(['user_id' => $proprietaire->id]);
    $exercice = Exercise::factory()->create();

    $this->actingAs($proprietaire);

    $ligne = app(CreateWorkoutTemplateLineAction::class)->execute([
        'workout_template_id' => $modele->id,
        'exercise_id' => $exercice->id,
    ]);

    expect($ligne)->toBeInstanceOf(WorkoutTemplateLine::class)
        ->and($ligne->workout_template_id)->toBe($modele->id)
        ->and($ligne->exercise_id)->toBe($exercice->id);
});

it('numerote a zero la premiere ligne d un modele vide', function (): void {
    $proprietaire = User::factory()->create();
    $modele = WorkoutTemplate::factory()->create(['user_id' => $proprietaire->id]);
    $exercice = Exercise::factory()->create();

    $this->actingAs($proprietaire);

    $ligne = app(CreateWorkoutTemplateLineAction::class)->execute([
        'workout_template_id' => $modele->id,
        'exercise_id' => $exercice->id,
    ]);

    // Zero exactement : ni 1 (le ternaire inverse, ou `===` devenu `!==`, rend
    // `null + 1`), ni -1.
    expect($ligne->order)->toBe(0);

    $this->assertDatabaseHas('workout_template_lines', [
        'id' => $ligne->id,
        'order' => 0,
    ]);
});

it('numerote la ligne suivante juste apres le plus grand ordre deja pose', function (): void {
    $proprietaire = User::factory()->create();
    $modele = WorkoutTemplate::factory()->create(['user_id' => $proprietaire->id]);

    // Le plus grand d'abord, le plus petit ensuite : `max` rend 4 la ou `count`
    // rendrait 2 et « le dernier insere » rendrait 1. Les trois donneraient un
    // resultat different, donc l'assertion ci-dessous nomme bien `max`.
    WorkoutTemplateLine::factory()->create([
        'workout_template_id' => $modele->id,
        'exercise_id' => Exercise::factory()->create()->id,
        'order' => 4,
    ]);
    WorkoutTemplateLine::factory()->create([
        'workout_template_id' => $modele->id,
        'exercise_id' => Exercise::factory()->create()->id,
        'order' => 1,
    ]);

    $exercice = Exercise::factory()->create();

    $this->actingAs($proprietaire);

    $ligne = app(CreateWorkoutTemplateLineAction::class)->execute([
        'workout_template_id' => $modele->id,
        'exercise_id' => $exercice->id,
    ]);

    // 5 exactement : 3 pour `- 1`, 4 pour `+ 0`, 6 pour `+ 2`, et 0 des que
    // l'ordre calcule n'atteint plus la creation (la colonne retombe sur son
    // DEFAULT 0).
    expect($ligne->order)->toBe(5);

    $this->assertDatabaseHas('workout_template_lines', [
        'id' => $ligne->id,
        'order' => 5,
    ]);
});

it('respecte l ordre fourni plutot que de le calculer', function (): void {
    $proprietaire = User::factory()->create();
    $modele = WorkoutTemplate::factory()->create(['user_id' => $proprietaire->id]);

    WorkoutTemplateLine::factory()->create([
        'workout_template_id' => $modele->id,
        'exercise_id' => Exercise::factory()->create()->id,
        'order' => 9,
    ]);

    $exercice = Exercise::factory()->create();

    $this->actingAs($proprietaire);

    $ligne = app(CreateWorkoutTemplateLineAction::class)->execute([
        'workout_template_id' => $modele->id,
        'exercise_id' => $exercice->id,
        'order' => 2,
    ]);

    // 2, pas 10 : l'ordre donne court-circuite le calcul, et il peut valoir
    // moins que le maximum existant — c'est ainsi qu'on insere au milieu.
    expect($ligne->order)->toBe(2);
});
