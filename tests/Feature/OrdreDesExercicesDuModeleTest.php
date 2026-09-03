<?php

declare(strict_types=1);

use App\Models\Exercise;
use App\Models\User;
use App\Models\WorkoutTemplate;
use App\Models\WorkoutTemplateLine;

/**
 * L'ordre d'un modele est porte par `workout_template_lines.order`, que
 * `UpdateWorkoutTemplateAction` reassigne depuis le rang soumis. Encore
 * faut-il que la relation le RELISE dans cet ordre.
 *
 * Le premier temoin porte sur la FORME de la requete, et non sur les lignes
 * rendues : l'index `(workout_template_id, order)` sert deja le filtre, si
 * bien que MySQL rend aujourd'hui l'ordre voulu par accident de plan. Un
 * temoin de comportement passe donc AVANT comme APRES le correctif, et ne
 * prouve rien. La garantie manquante est la clause, pas le resultat — et elle
 * manquera le jour ou un autre plan sera choisi.
 */
function modeleAvecLignesDesordonnees(User $proprietaire): WorkoutTemplate
{
    $modele = WorkoutTemplate::factory()->create(['user_id' => $proprietaire->id]);

    // Les `order` vont a rebours des `id` : les deux tris ne peuvent pas se
    // confondre.
    foreach ([2, 1, 0] as $rang) {
        WorkoutTemplateLine::factory()->create([
            'workout_template_id' => $modele->id,
            'exercise_id' => Exercise::factory()->create()->id,
            'order' => $rang,
        ]);
    }

    return $modele;
}

test('la relation demande un ordre total a la base', function (): void {
    $modele = WorkoutTemplate::factory()->create(['user_id' => User::factory()->create()->id]);

    expect($modele->workoutTemplateLines()->toSql())
        ->toContain('order by `order` asc, `id` asc');
});

test('la relation rend les lignes par ordre, pas par identifiant', function (): void {
    $modele = modeleAvecLignesDesordonnees(User::factory()->create());

    expect($modele->workoutTemplateLines->pluck('order')->all())->toBe([0, 1, 2]);
});

test('a ordre egal, les lignes sont departagees par identifiant', function (): void {
    $proprietaire = User::factory()->create();
    $modele = WorkoutTemplate::factory()->create(['user_id' => $proprietaire->id]);

    $ids = collect(range(1, 3))->map(fn (): int => WorkoutTemplateLine::factory()->create([
        'workout_template_id' => $modele->id,
        'exercise_id' => Exercise::factory()->create()->id,
        'order' => 0,
    ])->id)->all();

    expect($modele->workoutTemplateLines->pluck('id')->all())->toBe($ids);
});

test('le rang soumis devient l ordre enregistre', function (): void {
    $proprietaire = User::factory()->create();
    $modele = modeleAvecLignesDesordonnees($proprietaire);

    // Le modele est rouvert dans l'ordre affiche, puis les deux premiers sont
    // permutes — exactement ce que fait le bouton « Monter ».
    $affiche = $modele->workoutTemplateLines->pluck('exercise_id')->all();
    $permute = [$affiche[1], $affiche[0], $affiche[2]];

    $this->actingAs($proprietaire)
        ->put(route('templates.update', $modele), [
            'name' => $modele->name,
            'description' => '',
            'exercises' => array_map(static fn ($id): array => ['id' => $id, 'sets' => []], $permute),
        ])
        ->assertRedirect(route('templates.index'));

    expect($modele->refresh()->workoutTemplateLines->pluck('exercise_id')->all())->toBe($permute);
});
