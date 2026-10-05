<?php

declare(strict_types=1);

use App\Http\Requests\Api\WorkoutTemplateUpdateRequest;
use App\Http\Requests\StoreWorkoutTemplateRequest;
use App\Models\Exercise;
use App\Models\Set;
use App\Models\User;
use App\Models\Workout;
use App\Models\WorkoutLine;
use App\Models\WorkoutTemplate;
use App\Models\WorkoutTemplateLine;
use App\Models\WorkoutTemplateSet;
use Tests\Support\ReglesDesRequetes;

use function Pest\Laravel\actingAs;

/*
 * Un modèle de séance plafonne ses séries par exercice et borne leurs
 * répétitions et leur poids, à la création comme à la modification : chaque
 * série devient une ligne en base, puis une série de la séance qui démarre du
 * modèle. Au-delà, la requête est refusée sans rien écrire, jamais en 500.
 */

/**
 * `$nombre` séries identiques, de `$repetitions` répétitions à `$poids` kg.
 *
 * @return list<array{reps: int, weight: int|float, is_warmup: bool}>
 */
function modelesBornesSeries(int $nombre, int $repetitions = 10, int|float $poids = 50): array
{
    return array_fill(0, $nombre, ['reps' => $repetitions, 'weight' => $poids, 'is_warmup' => false]);
}

/**
 * Un modèle de séance d'un exercice, avec ses séries.
 *
 * @param  list<array<string, mixed>>  $series
 * @return array<string, mixed>
 */
function modelesBornesCorps(Exercise $exercice, array $series): array
{
    return [
        'name' => 'Modèle',
        'exercises' => [
            ['id' => $exercice->id, 'sets' => $series],
            ['id' => $exercice->id, 'sets' => modelesBornesSeries(1)],
        ],
    ];
}

/**
 * Un modèle du compte, d'un exercice et d'une série de 8 répétitions à 40 kg.
 */
function modelesBornesModeleDe(User $compte, Exercise $exercice): WorkoutTemplate
{
    $modele = WorkoutTemplate::factory()->create(['user_id' => $compte->id, 'name' => 'Avant']);
    $ligne = WorkoutTemplateLine::factory()->create(['workout_template_id' => $modele->id, 'exercise_id' => $exercice->id]);
    WorkoutTemplateSet::factory()->create(['workout_template_line_id' => $ligne->id, 'reps' => 8, 'weight' => 40]);

    return $modele;
}

it('refuse en 422, sans rien écrire, un exercice de modèle qui dépasse le plafond de séries', function (): void {
    $compte = User::factory()->create();
    $exercice = Exercise::factory()->create(['user_id' => $compte->id]);
    $modele = modelesBornesModeleDe($compte, $exercice);
    $trop = modelesBornesCorps($exercice, modelesBornesSeries(WorkoutTemplate::SERIES_MAX_PAR_EXERCICE + 1));

    actingAs($compte)->postJson(route('templates.store'), $trop)
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['exercises.0.sets']);

    actingAs($compte)->putJson(route('templates.update', $modele), $trop)
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['exercises.0.sets']);

    expect(WorkoutTemplate::query()->count())->toBe(1)
        ->and(WorkoutTemplateSet::query()->count())->toBe(1)
        ->and($modele->fresh()?->name)->toBe('Avant');
});

it('accepte un exercice de modèle au plafond de séries', function (): void {
    $compte = User::factory()->create();
    $exercice = Exercise::factory()->create(['user_id' => $compte->id]);
    $modele = modelesBornesModeleDe($compte, $exercice);
    $auPlafond = modelesBornesCorps($exercice, modelesBornesSeries(WorkoutTemplate::SERIES_MAX_PAR_EXERCICE));

    actingAs($compte)->post(route('templates.store'), $auPlafond)->assertRedirect(route('templates.index'));
    actingAs($compte)->put(route('templates.update', $modele), $auPlafond)->assertRedirect(route('templates.index'));

    expect(WorkoutTemplateSet::query()->count())->toBe(2 * (WorkoutTemplate::SERIES_MAX_PAR_EXERCICE + 1));
});

/*
 * Une valeur au-delà de la capacité des colonnes, une au-delà du plafond d'une
 * série de séance, et une négative.
 */
dataset('modeles bornes valeurs refusees', [
    'répétitions hors capacité' => ['reps', 2_147_483_648],
    'répétitions au-delà du plafond' => ['reps', Set::REPETITIONS_MAX + 1],
    'répétitions négatives' => ['reps', -1],
    'poids hors capacité' => ['weight', 100_000_000],
    'poids au-delà du plafond' => ['weight', Set::POIDS_MAX_KG + 0.01],
    'poids négatif' => ['weight', -0.5],
]);

it('refuse en 422 des répétitions ou un poids hors bornes, à la création comme à la modification', function (string $champ, int|float $valeur): void {
    $compte = User::factory()->create();
    $exercice = Exercise::factory()->create(['user_id' => $compte->id]);
    $modele = modelesBornesModeleDe($compte, $exercice);
    $serie = ['reps' => 10, 'weight' => 50, 'is_warmup' => false, $champ => $valeur];

    actingAs($compte)->postJson(route('templates.store'), modelesBornesCorps($exercice, [$serie]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(["exercises.0.sets.0.{$champ}"]);

    actingAs($compte)->putJson(route('templates.update', $modele), modelesBornesCorps($exercice, [$serie]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(["exercises.0.sets.0.{$champ}"]);

    expect(WorkoutTemplate::query()->count())->toBe(1)
        ->and(WorkoutTemplateSet::query()->sole()->reps)->toBe(8);
})->with('modeles bornes valeurs refusees');

it('garde des répétitions et un poids au plafond d’une série de séance', function (): void {
    $compte = User::factory()->create();
    $exercice = Exercise::factory()->create(['user_id' => $compte->id]);

    actingAs($compte)
        ->post(route('templates.store'), modelesBornesCorps($exercice, modelesBornesSeries(1, Set::REPETITIONS_MAX, Set::POIDS_MAX_KG)))
        ->assertRedirect(route('templates.index'));

    $serie = WorkoutTemplateSet::query()->where('reps', Set::REPETITIONS_MAX)->sole();

    expect((float) $serie->weight)->toBe((float) Set::POIDS_MAX_KG);
});

it('applique les mêmes bornes aux séries d’un modèle à sa création et à sa modification', function (): void {
    $creation = ReglesDesRequetes::de(StoreWorkoutTemplateRequest::class);
    $modification = ReglesDesRequetes::de(WorkoutTemplateUpdateRequest::class);

    foreach (['exercises', 'exercises.*.sets', 'exercises.*.sets.*.reps', 'exercises.*.sets.*.weight'] as $champ) {
        expect($creation[$champ] ?? null)->not->toBeNull("{$champ} n'a pas de règle")
            ->and($creation[$champ])->toEqual($modification[$champ] ?? null);
    }

    expect(ReglesDesRequetes::bornes($creation['exercises.*.sets']))->toBe(['min' => null, 'max' => (float) WorkoutTemplate::SERIES_MAX_PAR_EXERCICE]);
});

/**
 * Une séance du compte, de `$exercices` exercices de `$series` séries
 * chacun, écrites en masse comme des séries anciennes : sans passer par la
 * validation d'une série ni par ses écouteurs.
 *
 * @param  array<string, mixed>  $premiereSerie  ce qui remplace les valeurs de la toute première série
 */
function modelesBornesSeanceDe(User $compte, Exercise $exercice, int $exercices, int $series, array $premiereSerie = []): Workout
{
    $seance = Workout::factory()->create(['user_id' => $compte->id, 'name' => 'Séance longue']);
    $lignesDeSeries = [];

    foreach (range(1, $exercices) as $rang) {
        $ligne = WorkoutLine::factory()->create(['workout_id' => $seance->id, 'exercise_id' => $exercice->id, 'order' => $rang]);

        foreach (range(1, $series) as $ignoree) {
            $lignesDeSeries[] = [
                'workout_line_id' => $ligne->id,
                'reps' => 10,
                'weight' => 50.0,
                'is_warmup' => false,
                'is_completed' => true,
            ];
        }
    }

    $lignesDeSeries[0] = [...$lignesDeSeries[0], ...$premiereSerie];

    foreach (array_chunk($lignesDeSeries, 500) as $paquet) {
        Set::insert($paquet);
    }

    return $seance;
}

/**
 * Le corps qu'envoie la page de modification d'un modèle : le modèle tel
 * qu'il est en base.
 *
 * @return array<string, mixed>
 */
function modelesBornesCorpsDuModele(WorkoutTemplate $modele): array
{
    $modele->load('workoutTemplateLines.workoutTemplateSets');

    return [
        'name' => $modele->name,
        'description' => $modele->description,
        'exercises' => $modele->workoutTemplateLines->map(fn (WorkoutTemplateLine $ligne): array => [
            'id' => $ligne->exercise_id,
            'sets' => $ligne->workoutTemplateSets->map(fn (WorkoutTemplateSet $serie): array => [
                'reps' => $serie->reps,
                'weight' => $serie->weight,
                'is_warmup' => $serie->is_warmup,
            ])->all(),
        ])->all(),
    ];
}

/*
 * « Enregistrer comme modèle » recopie une séance, qui n'a ni le plafond
 * d'exercices ni celui de séries d'un modèle, et dont les séries anciennes
 * peuvent précéder les bornes d'une série. Le modèle obtenu tient dans les
 * bornes de ses requêtes, et reste donc modifiable, jusqu'à son nom.
 */
it('ramène aux bornes d’un modèle la séance plus grande qu’on y enregistre, et ce modèle reste modifiable', function (): void {
    $compte = User::factory()->create();
    $exercice = Exercise::factory()->create(['user_id' => $compte->id]);
    $seance = modelesBornesSeanceDe($compte, $exercice, WorkoutTemplate::EXERCICES_MAX + 1, 2, [
        'reps' => Set::REPETITIONS_MAX + 501,
        'weight' => Set::POIDS_MAX_KG + 50_000,
    ]);
    $ligneLongue = $seance->workoutLines()->firstOrFail();
    Set::insert(array_fill(0, WorkoutTemplate::SERIES_MAX_PAR_EXERCICE + 28, [
        'workout_line_id' => $ligneLongue->id, 'reps' => 5, 'weight' => 20.0, 'is_warmup' => false, 'is_completed' => true,
    ]));

    actingAs($compte)->post(route('templates.save-from-workout', $seance))
        ->assertRedirect(route('templates.index'))
        ->assertSessionHas('success', 'Modèle enregistré, ramené à ce qu’un modèle accepte : 50 exercices et 50 séries par exercice au plus, 999 répétitions et 100 000 kg par série au plus.');

    $modele = WorkoutTemplate::query()->sole();
    $lignes = $modele->workoutTemplateLines()->withCount('workoutTemplateSets')->get();
    $premiere = $lignes->firstOrFail();

    expect($lignes)->toHaveCount(WorkoutTemplate::EXERCICES_MAX)
        ->and($lignes->max('workout_template_sets_count'))->toBe(WorkoutTemplate::SERIES_MAX_PAR_EXERCICE)
        ->and($premiere->workout_template_sets_count)->toBe(WorkoutTemplate::SERIES_MAX_PAR_EXERCICE)
        ->and(WorkoutTemplateSet::query()->max('reps'))->toBe(Set::REPETITIONS_MAX)
        ->and(WorkoutTemplateSet::query()->max('weight'))->toEqual(Set::POIDS_MAX_KG);

    $corps = modelesBornesCorpsDuModele($modele);
    $corps['name'] = 'Renommé';

    actingAs($compte)->put(route('templates.update', $modele), $corps)
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('templates.index'));

    expect($modele->fresh()?->name)->toBe('Renommé');
});

it('garde entière, avec le message habituel, une séance qui tient dans un modèle', function (): void {
    $compte = User::factory()->create();
    $exercice = Exercise::factory()->create(['user_id' => $compte->id]);
    $seance = modelesBornesSeanceDe($compte, $exercice, WorkoutTemplate::EXERCICES_MAX, WorkoutTemplate::SERIES_MAX_PAR_EXERCICE, [
        'reps' => Set::REPETITIONS_MAX,
        'weight' => Set::POIDS_MAX_KG,
    ]);

    actingAs($compte)->post(route('templates.save-from-workout', $seance))
        ->assertRedirect(route('templates.index'))
        ->assertSessionHas('success', 'Modèle enregistré avec succès !');

    expect(WorkoutTemplateLine::query()->count())->toBe(WorkoutTemplate::EXERCICES_MAX)
        ->and(WorkoutTemplateSet::query()->count())->toBe(WorkoutTemplate::EXERCICES_MAX * WorkoutTemplate::SERIES_MAX_PAR_EXERCICE)
        ->and(WorkoutTemplateSet::query()->max('reps'))->toBe(Set::REPETITIONS_MAX);
});

it('coupe le nom du modèle tiré d’une séance pour qu’il tienne dans sa colonne', function (): void {
    $compte = User::factory()->create();
    $seance = Workout::factory()->create(['user_id' => $compte->id, 'name' => str_repeat('é', 255)]);

    actingAs($compte)->post(route('templates.save-from-workout', $seance))
        ->assertRedirect(route('templates.index'));

    $nom = WorkoutTemplate::query()->sole()->name;

    expect(mb_strlen($nom))->toBe(255)
        ->and($nom)->toEndWith(' (Modèle)')
        ->and($nom)->toStartWith(str_repeat('é', 246));
});
