<?php

declare(strict_types=1);

use App\Http\Requests\Api\WorkoutTemplateUpdateRequest;
use App\Http\Requests\StoreWorkoutTemplateRequest;
use App\Models\Exercise;
use App\Models\Set;
use App\Models\User;
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
