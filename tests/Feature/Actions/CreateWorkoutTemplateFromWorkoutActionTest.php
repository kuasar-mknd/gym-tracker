<?php

declare(strict_types=1);

use App\Actions\CreateWorkoutTemplateFromWorkoutAction;
use App\Models\Set;
use App\Models\User;
use App\Models\Workout;
use App\Models\WorkoutLine;
use App\Models\WorkoutTemplate;
use Illuminate\Support\Carbon;

it('creates a template correctly from a workout', function (): void {
    $user = User::factory()->create();

    // Create a workout with a specific created_at date to check the description formatting
    $workoutDate = Carbon::create(2023, 10, 15, 14, 30, 0);
    $workout = Workout::factory()->create([
        'user_id' => $user->id,
        'name' => 'My Awesome Workout',
        'created_at' => $workoutDate,
    ]);

    // Create 2 workout lines
    $line1 = WorkoutLine::factory()->create([
        'workout_id' => $workout->id,
        'order' => 1,
    ]);

    $line2 = WorkoutLine::factory()->create([
        'workout_id' => $workout->id,
        'order' => 2,
    ]);

    // Create sets for line 1
    $set1_1 = Set::factory()->create([
        'workout_line_id' => $line1->id,
        'reps' => 10,
        'weight' => 50.5,
        'is_warmup' => true,
    ]);

    $set1_2 = Set::factory()->create([
        'workout_line_id' => $line1->id,
        'reps' => 8,
        'weight' => 60,
        'is_warmup' => false,
    ]);

    // Create set for line 2
    $set2_1 = Set::factory()->create([
        'workout_line_id' => $line2->id,
        'reps' => 12,
        'weight' => 30,
        'is_warmup' => false,
    ]);

    $action = app(CreateWorkoutTemplateFromWorkoutAction::class);
    $template = $action->execute($user, $workout);

    // Assert Template is created correctly
    expect($template)
        ->toBeInstanceOf(WorkoutTemplate::class)
        ->user_id->toBe($user->id)
        ->name->toBe('My Awesome Workout (Modèle)')
        ->description->toBe('Créé à partir de la séance du 15/10/2023');

    // Load relationships for assertions
    $template->load('workoutTemplateLines.workoutTemplateSets');

    // Assert Template Lines are created correctly
    expect($template->workoutTemplateLines)->toHaveCount(2);

    // Line 1 assertions
    $templateLine1 = $template->workoutTemplateLines->where('order', 1)->firstOrFail();
    expect($templateLine1)
        ->not->toBeNull()
        ->exercise_id->toBe($line1->exercise_id);

    expect($templateLine1->workoutTemplateSets)->toHaveCount(2);

    // Line 1, Set 1 assertions (Warmup)
    $templateSet1_1 = $templateLine1->workoutTemplateSets->sortBy('id')->first();
    expect($templateSet1_1)
        ->not->toBeNull()
        ->reps->toBe(10)
        ->weight->toEqual(50.5)
        ->is_warmup->toBeTrue();

    // Line 1, Set 2 assertions
    $templateSet1_2 = $templateLine1->workoutTemplateSets->sortBy('id')->skip(1)->first();
    expect($templateSet1_2)
        ->not->toBeNull()
        ->reps->toBe(8)
        ->weight->toEqual(60.0)
        ->is_warmup->toBeFalse();

    // Line 2 assertions
    $templateLine2 = $template->workoutTemplateLines->where('order', 2)->firstOrFail();
    expect($templateLine2)
        ->not->toBeNull()
        ->exercise_id->toBe($line2->exercise_id);

    expect($templateLine2->workoutTemplateSets)->toHaveCount(1);

    // Line 2, Set 1 assertions
    $templateSet2_1 = $templateLine2->workoutTemplateSets->first();
    expect($templateSet2_1)
        ->not->toBeNull()
        ->reps->toBe(12)
        ->weight->toEqual(30.0)
        ->is_warmup->toBeFalse();
});

it('handles missing created_at when formatting description', function (): void {
    $user = User::factory()->create();

    // Create a workout with a null created_at date
    $workout = Workout::factory()->create([
        'user_id' => $user->id,
        'name' => 'Legacy Workout',
    ]);
    // Force created_at to be null, as factories usually auto-populate it
    $workout->timestamps = false;
    $workout->created_at = null;
    $workout->save();

    $action = app(CreateWorkoutTemplateFromWorkoutAction::class);
    $template = $action->execute($user, $workout);

    // The logic falls back to now()->format('d/m/Y')
    $expectedDate = now()->format('d/m/Y');

    // Assert Template is created correctly with fallback date
    expect($template)
        ->toBeInstanceOf(WorkoutTemplate::class)
        ->user_id->toBe($user->id)
        ->name->toBe('Legacy Workout (Modèle)')
        ->description->toBe('Créé à partir de la séance du '.$expectedDate);
});

/*
 * Huit mutants sur quarante-et-un survivaient au test ci-dessus. Il verifie ce
 * que le modele recopie — exercices, repetitions, charges — et rien de ce qui
 * l'accompagne :
 *
 *  - lignes 50-51 et 84-85, les horodatages. `insert()` en masse court-circuite
 *    les timestamps d'Eloquent : ces quatre cles sont la seule chose qui pose
 *    `created_at` et `updated_at`, et les colonnes sont nullables, donc les
 *    retirer produit des lignes et des series sans date, en silence ;
 *  - ligne 83, le rang de chaque serie. La colonne vaut 0 par defaut : sans
 *    cette cle, toutes les series d'une ligne partagent le rang 0 et leur ordre
 *    d'affichage devient indefini ;
 *  - ligne 92, le decoupage en paquets de cent. Le retirer, ou deplacer la
 *    borne d'un cran, donne exactement les memes lignes en base — seul le
 *    nombre de requetes change. C'est pourtant la raison d'etre de la ligne :
 *    une seance de plus de 999 parametres depasse la limite d'un `INSERT`.
 */

use Illuminate\Support\Facades\DB;

/**
 * @return array{0: User, 1: Workout, 2: WorkoutLine}
 */
function seanceAHorlogeArretee(): array
{
    Carbon::setTestNow(Carbon::parse('2026-06-15 12:00:00'));

    $user = User::factory()->create();
    $workout = Workout::factory()->create([
        'user_id' => $user->id,
        'name' => 'Seance mesuree',
    ]);
    $line = WorkoutLine::factory()->create(['workout_id' => $workout->id, 'order' => 1]);

    return [$user, $workout, $line];
}

/**
 * Ecrit `$combien` series d'un coup.
 *
 * En masse et non par la fabrique : chaque `Set::save()` declenche la
 * synchronisation des records et des objectifs, ce qui rendrait un test a cent
 * series interminable pour une raison etrangere a ce qu'il verifie.
 */
function seriesEnMasse(WorkoutLine $line, int $combien): void
{
    $lignes = [];

    foreach (range(1, $combien) as $ignored) {
        $lignes[] = [
            'workout_line_id' => $line->id,
            'weight' => 40.0,
            'reps' => 10,
            'is_warmup' => false,
            'is_completed' => true,
            'created_at' => '2026-06-15 12:00:00',
            'updated_at' => '2026-06-15 12:00:00',
        ];
    }

    Set::insert($lignes);
}

/**
 * Compte les `INSERT` vers `workout_template_sets` a partir de maintenant.
 *
 * Le compteur est porte par un objet et non par un entier : une variable
 * capturee par reference resterait a zero une fois la fonction rendue.
 */
function insertionsDeSeriesDeModele(): \stdClass
{
    $compteur = new \stdClass();
    $compteur->insertions = 0;

    DB::listen(function (\Illuminate\Database\Events\QueryExecuted $queryExecuted) use ($compteur): void {
        if (str_contains($queryExecuted->sql, 'insert into `workout_template_sets`')) {
            $compteur->insertions++;
        }
    });

    return $compteur;
}

it('horodate les lignes et les series du modele a l instant de la creation', function (): void {
    [$user, $workout, $line] = seanceAHorlogeArretee();
    Set::factory()->count(2)->create(['workout_line_id' => $line->id, 'reps' => 10, 'weight' => 40]);

    $template = app(CreateWorkoutTemplateFromWorkoutAction::class)->execute($user, $workout);
    $template->load('workoutTemplateLines.workoutTemplateSets');

    expect($template->workoutTemplateLines)->toHaveCount(1);

    // Les dates sont ecrites en toutes lettres : une attente calculee par la
    // meme expression que le code testé ne testerait rien.
    foreach ($template->workoutTemplateLines as $ligneModele) {
        expect($ligneModele->created_at)->not->toBeNull();
        expect($ligneModele->created_at->toDateTimeString())->toBe('2026-06-15 12:00:00');
        expect($ligneModele->updated_at)->not->toBeNull();
        expect($ligneModele->updated_at->toDateTimeString())->toBe('2026-06-15 12:00:00');

        expect($ligneModele->workoutTemplateSets)->toHaveCount(2);

        foreach ($ligneModele->workoutTemplateSets as $serieModele) {
            expect($serieModele->created_at)->not->toBeNull();
            expect($serieModele->created_at->toDateTimeString())->toBe('2026-06-15 12:00:00');
            expect($serieModele->updated_at)->not->toBeNull();
            expect($serieModele->updated_at->toDateTimeString())->toBe('2026-06-15 12:00:00');
        }
    }
});

it('numerote chaque serie du modele par l identifiant de la serie source', function (): void {
    [$user, $workout, $line] = seanceAHorlogeArretee();

    $premiere = Set::factory()->create(['workout_line_id' => $line->id, 'reps' => 10, 'weight' => 40]);
    $seconde = Set::factory()->create(['workout_line_id' => $line->id, 'reps' => 8, 'weight' => 50]);

    $template = app(CreateWorkoutTemplateFromWorkoutAction::class)->execute($user, $workout);
    $template->load('workoutTemplateLines.workoutTemplateSets');

    $rangs = $template->workoutTemplateLines
        ->firstOrFail()
        ->workoutTemplateSets
        ->sortBy('id')
        ->pluck('order')
        ->all();

    // Le rang repris de la serie source, et non 0 : la colonne vaut 0 par
    // defaut, donc retirer cette cle donne deux series de rang identique dont
    // l'ordre d'affichage ne veut plus rien dire.
    expect($rangs)->toBe([$premiere->id, $seconde->id]);
});

it('ecrit cent series de modele en une seule requete', function (): void {
    [$user, $workout, $line] = seanceAHorlogeArretee();
    seriesEnMasse($line, 100);

    $compteur = insertionsDeSeriesDeModele();

    $template = app(CreateWorkoutTemplateFromWorkoutAction::class)->execute($user, $workout);

    expect($template->workoutTemplateLines()->firstOrFail()->workoutTemplateSets()->count())->toBe(100);

    // Une seule : cent series tiennent exactement dans un paquet. Sans le
    // decoupage, c'est une requete PAR serie ; avec une borne a 99, c'en est
    // deux.
    expect($compteur->insertions)->toBe(1);
});

it('coupe en deux requetes des la cent-et-unieme serie', function (): void {
    [$user, $workout, $line] = seanceAHorlogeArretee();
    seriesEnMasse($line, 101);

    $compteur = insertionsDeSeriesDeModele();

    $template = app(CreateWorkoutTemplateFromWorkoutAction::class)->execute($user, $workout);

    expect($template->workoutTemplateLines()->firstOrFail()->workoutTemplateSets()->count())->toBe(101);

    // Deux : cent puis une. Une borne a 101 les ferait tenir en une seule
    // requete — c'est le sens de cette assertion, et la raison pour laquelle
    // elle porte sur 101 series et non sur 100.
    expect($compteur->insertions)->toBe(2);
});
