<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Workout;
use App\Models\WorkoutLine;
use App\Models\WorkoutTemplate;
use App\Models\WorkoutTemplateLine;
use App\Models\WorkoutTemplateSet;
use Laravel\Sanctum\Sanctum;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

/**
 * `order` est `int NOT NULL DEFAULT 0` sur les trois tables. Une regle
 * `nullable` sur une mise a jour qui passe `validated()` a `update()` envoie
 * donc `null` dans une colonne qui le refuse : 500 la ou un 422 est du.
 */
function proprietaireConnecteOrdreNul(): User
{
    $proprietaire = User::factory()->create();
    Sanctum::actingAs($proprietaire);

    return $proprietaire;
}

test('un ordre nul est refuse sur une ligne de modele', function (): void {
    $modele = WorkoutTemplate::factory()->create(['user_id' => proprietaireConnecteOrdreNul()->id]);
    $ligne = WorkoutTemplateLine::factory()->create(['workout_template_id' => $modele->id]);

    $this->patchJson(route('api.v1.workout-template-lines.update', $ligne), ['order' => null])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('order');
});

test('un ordre nul est refuse sur une serie de modele', function (): void {
    $modele = WorkoutTemplate::factory()->create(['user_id' => proprietaireConnecteOrdreNul()->id]);
    $ligne = WorkoutTemplateLine::factory()->create(['workout_template_id' => $modele->id]);
    $serie = WorkoutTemplateSet::factory()->create(['workout_template_line_id' => $ligne->id]);

    $this->patchJson(route('api.v1.workout-template-sets.update', $serie), ['order' => null])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('order');
});

test('un ordre nul est refuse sur une ligne de seance', function (): void {
    $seance = Workout::factory()->create(['user_id' => proprietaireConnecteOrdreNul()->id]);
    $ligne = WorkoutLine::factory()->create(['workout_id' => $seance->id]);

    $this->patchJson(route('api.v1.workout-lines.update', $ligne), ['order' => null])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('order');
});

test('un ordre entier reste accepte', function (): void {
    $modele = WorkoutTemplate::factory()->create(['user_id' => proprietaireConnecteOrdreNul()->id]);
    $ligne = WorkoutTemplateLine::factory()->create(['workout_template_id' => $modele->id]);

    $this->patchJson(route('api.v1.workout-template-lines.update', $ligne), ['order' => 3])
        ->assertOk();

    expect($ligne->refresh()->order)->toBe(3);
});

/**
 * `nullable` reste juste a la CREATION : les trois actions lisent
 * `$data['order'] ?? max + 1`. Un `null` y demande d'ajouter a la fin, ce que
 * ce temoin fixe pour qu'on ne le retire pas en croyant corriger la meme chose.
 */
test('un ordre nul a la creation ajoute a la fin', function (): void {
    $modele = WorkoutTemplate::factory()->create(['user_id' => proprietaireConnecteOrdreNul()->id]);
    WorkoutTemplateLine::factory()->create(['workout_template_id' => $modele->id, 'order' => 7]);

    $this->postJson(route('api.v1.workout-template-lines.store'), [
        'workout_template_id' => $modele->id,
        'exercise_id' => \App\Models\Exercise::factory()->create()->id,
        'order' => null,
    ])->assertCreated();

    expect(WorkoutTemplateLine::where('workout_template_id', $modele->id)->max('order'))->toBe(8);
});
