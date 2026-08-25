<?php

declare(strict_types=1);

use App\Actions\CreateWorkoutFromTemplateAction;
use App\Models\User;
use App\Models\WorkoutTemplate;
use App\Models\WorkoutTemplateLine;
use App\Models\WorkoutTemplateSet;

use function PHPUnit\Framework\assertNotNull;

it('creates a workout from a template with lines and sets', function (): void {
    // Arrange
    $user = User::factory()->create();

    $template = WorkoutTemplate::factory()->create([
        'user_id' => $user->id,
        'name' => 'My Test Template',
    ]);

    $line1 = WorkoutTemplateLine::factory()->create([
        'workout_template_id' => $template->id,
        'order' => 1,
    ]);

    WorkoutTemplateSet::factory()->create([
        'workout_template_line_id' => $line1->id,
        'reps' => 10,
        'weight' => 50,
        'is_warmup' => true,
    ]);

    WorkoutTemplateSet::factory()->create([
        'workout_template_line_id' => $line1->id,
        'reps' => 8,
        'weight' => 60,
        'is_warmup' => false,
    ]);

    $line2 = WorkoutTemplateLine::factory()->create([
        'workout_template_id' => $template->id,
        'order' => 2,
    ]);

    WorkoutTemplateSet::factory()->create([
        'workout_template_line_id' => $line2->id,
        'reps' => 12,
        'weight' => 40,
        'is_warmup' => false,
    ]);

    // Act
    $action = app(CreateWorkoutFromTemplateAction::class);
    $workout = $action->execute($user, $template);

    // Assert
    expect($workout)->toBeInstanceOf(\App\Models\Workout::class)
        ->and($workout->user_id)->toBe($user->id)
        ->and($workout->name)->toBe('My Test Template')
        ->and($workout->started_at)->not->toBeNull();

    $this->assertDatabaseHas('workouts', [
        'id' => $workout->id,
        'user_id' => $user->id,
        'name' => 'My Test Template',
    ]);

    // Assert lines were created correctly
    $this->assertDatabaseCount('workout_lines', 2);

    // Assert sets were created correctly
    $this->assertDatabaseCount('sets', 3);

    // Reload workout with lines and sets to assert details
    $workout->load('workoutLines.sets');

    expect($workout->workoutLines)->toHaveCount(2);

    $createdLine1 = $workout->workoutLines->firstWhere('order', 1);
    assertNotNull($createdLine1, 'Aucune ligne d\'ordre 1 dans la seance creee.');
    expect($createdLine1->exercise_id)->toBe($line1->exercise_id)
        ->and($createdLine1->sets)->toHaveCount(2);

    $createdLine1Set1 = $createdLine1->sets->firstWhere('is_warmup', true);
    assertNotNull($createdLine1Set1, 'La serie d\'echauffement de la ligne 1 est absente.');
    expect($createdLine1Set1->reps)->toBe(10)
        ->and($createdLine1Set1->weight)->toBe(50.0);

    $createdLine1Set2 = $createdLine1->sets->firstWhere('is_warmup', false);
    assertNotNull($createdLine1Set2, 'La serie effective de la ligne 1 est absente.');
    expect($createdLine1Set2->reps)->toBe(8)
        ->and($createdLine1Set2->weight)->toBe(60.0);

    $createdLine2 = $workout->workoutLines->firstWhere('order', 2);
    assertNotNull($createdLine2, 'Aucune ligne d\'ordre 2 dans la seance creee.');
    expect($createdLine2->exercise_id)->toBe($line2->exercise_id)
        ->and($createdLine2->sets)->toHaveCount(1);

    $createdLine2Set1 = $createdLine2->sets->first();
    assertNotNull($createdLine2Set1, 'La ligne 2 n\'a recu aucune serie.');
    expect($createdLine2Set1->reps)->toBe(12)
        ->and($createdLine2Set1->weight)->toBe(40.0)
        ->and($createdLine2Set1->is_warmup)->toBeFalse();
});

/*
 * Ce qui suit ferme quatre mutants qui survivaient au test ci-dessus.
 *
 * Il verifiait le contenu recopie — exercices, repetitions, charges — mais rien
 * de ce qui l'accompagne :
 *
 *  - retirer `created_at` ou `updated_at` du tableau insere en masse laissait
 *    les series arriver sans horodatage. `Set::insert()` court-circuite les
 *    timestamps d'Eloquent : ces deux cles sont la seule chose qui les pose, et
 *    la colonne est nullable, donc rien ne protestait ;
 *  - retirer l'amorcage de la relation `user` sur la seance rendue laissait le
 *    controleur la recharger derriere.
 */

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Un modele a deux lignes, trois series en tout, et l'horloge arretee.
 *
 * Le 15 juin est loin de tout changement d'heure : un horodatage compare a la
 * seconde pres sur un jour qui en dure 23 ou 25 serait faux deux fois par an.
 *
 * @return array{0: User, 1: WorkoutTemplate}
 */
function modeleAHorlogeArretee(): array
{
    Carbon::setTestNow(Carbon::parse('2026-06-15 12:00:00'));

    $user = User::factory()->create();
    $template = WorkoutTemplate::factory()->create(['user_id' => $user->id]);

    $ligne1 = WorkoutTemplateLine::factory()->create([
        'workout_template_id' => $template->id,
        'order' => 1,
    ]);
    WorkoutTemplateSet::factory()->count(2)->create([
        'workout_template_line_id' => $ligne1->id,
        'reps' => 10,
        'weight' => 50,
    ]);

    $ligne2 = WorkoutTemplateLine::factory()->create([
        'workout_template_id' => $template->id,
        'order' => 2,
    ]);
    WorkoutTemplateSet::factory()->create([
        'workout_template_line_id' => $ligne2->id,
        'reps' => 8,
        'weight' => 60,
    ]);

    return [$user, $template];
}

it('horodate chaque serie recopiee, a la creation et a la modification', function (): void {
    [$user, $template] = modeleAHorlogeArretee();

    $workout = app(CreateWorkoutFromTemplateAction::class)->execute($user, $template);

    $series = \App\Models\Set::query()
        ->whereIn('workout_line_id', $workout->workoutLines()->pluck('id'))
        ->get();

    expect($series)->toHaveCount(3);

    // La date est ecrite en toutes lettres et non recalculee par `now()` : une
    // attente produite par la meme expression que le code testé ne teste rien.
    foreach ($series as $serie) {
        expect($serie->created_at)->not->toBeNull();
        expect($serie->created_at->toDateTimeString())->toBe('2026-06-15 12:00:00');
        expect($serie->updated_at)->not->toBeNull();
        expect($serie->updated_at->toDateTimeString())->toBe('2026-06-15 12:00:00');
    }
});

it('rend la seance avec son utilisateur deja rattache', function (): void {
    [$user, $template] = modeleAHorlogeArretee();

    $workout = app(CreateWorkoutFromTemplateAction::class)->execute($user, $template);

    expect($workout->relationLoaded('user'))->toBeTrue();

    /*
     * L'instance MEME qui a ete passee a l'action, et pas une seconde copie
     * portant le meme identifiant.
     *
     * `relationLoaded()` seul ne dit rien ici : l'ecouteur `Workout::saved`
     * lit deja `$workout->user` au moment de l'enregistrement, donc la
     * relation est peuplee de toute facon — par une lecture en base. C'est
     * l'identite, et elle seule, qui separe « rattachee par l'action » de
     * « rechargee derriere ».
     */
    expect($workout->getRelation('user'))->toBe($user);

    // Et la lire ne redescend pas en base.
    $requetes = 0;
    DB::listen(function () use (&$requetes): void {
        $requetes++;
    });

    expect($workout->user->id)->toBe($user->id);
    expect($requetes)->toBe(0);
});
