<?php

declare(strict_types=1);

use App\Actions\FetchWorkoutTemplatesAction;
use App\Models\Exercise;
use App\Models\Set;
use App\Models\User;
use App\Models\Workout;
use App\Models\WorkoutLine;
use App\Models\WorkoutTemplate;
use App\Models\WorkoutTemplateLine;
use App\Models\WorkoutTemplateSet;
use App\Services\Stats\ClesDeStats;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\actingAs;

/*
 * Lancer un modèle sous une clé, puis le rejouer (#2049).
 *
 * Sans réseau, la file rejoue un lancement écriture par écriture : la séance,
 * puis chaque ligne, puis chaque série, sous les clés que le serveur dérive du
 * rang dans le modèle (`WorkoutTemplate::cleDUneLigneLancee()`,
 * `cleDUneSerieLancee()`). Le serveur a pu exécuter le lancement déjà, la
 * réponse perdue : chaque écriture rejouée doit alors rendre ce qui existe,
 * jamais le doubler, et jamais rattacher une série à un autre exercice.
 */

/**
 * Un modèle de deux exercices, le second de trois séries, rangs à rebours
 * des identifiants pour que l'ordre du modèle ne se confonde pas avec eux.
 *
 * @return array{compte: User, modele: WorkoutTemplate, exercices: list<Exercise>}
 */
function lancementIdempotentScene(): array
{
    $compte = User::factory()->create();
    $exercices = [
        Exercise::factory()->create(['user_id' => $compte->id, 'name' => 'Squat']),
        Exercise::factory()->create(['user_id' => $compte->id, 'name' => 'Rowing']),
    ];
    $modele = WorkoutTemplate::factory()->create(['user_id' => $compte->id, 'name' => 'Jambes et dos']);
    $second = WorkoutTemplateLine::factory()->create(['workout_template_id' => $modele->id, 'exercise_id' => $exercices[1]->id, 'order' => 1]);
    $premier = WorkoutTemplateLine::factory()->create(['workout_template_id' => $modele->id, 'exercise_id' => $exercices[0]->id, 'order' => 0]);

    WorkoutTemplateSet::factory()->create(['workout_template_line_id' => $premier->id, 'reps' => 5, 'weight' => 100, 'order' => 0]);

    foreach ([2 => 8, 1 => 10, 0 => 12] as $rang => $repetitions) {
        WorkoutTemplateSet::factory()->create(['workout_template_line_id' => $second->id, 'reps' => $repetitions, 'weight' => 40, 'order' => $rang]);
    }

    return ['compte' => $compte, 'modele' => $modele, 'exercices' => $exercices];
}

/**
 * Les lignes de la séance dans l'ordre où elles ont été créées, chacune avec
 * son exercice, sa clé et les clés de ses séries dans leur ordre.
 *
 * @return list<array{exercice: int, cle: string|null, series: list<string|null>}>
 */
function lancementIdempotentLignes(Workout $seance): array
{
    return array_values($seance->workoutLines()->reorder()->orderBy('id')->with('sets')->get()
        ->map(fn (WorkoutLine $ligne): array => [
            'exercice' => $ligne->exercise_id,
            'cle' => $ligne->idempotency_key,
            'series' => array_values($ligne->sets->sortBy([['order', 'asc'], ['id', 'asc']])->map(fn (Set $serie): ?string => $serie->idempotency_key)->all()),
        ])
        ->all());
}

it('pose sous sa clé de lancement les clés dérivées du rang, dans l’ordre du modèle', function (): void {
    ['compte' => $compte, 'modele' => $modele, 'exercices' => $exercices] = lancementIdempotentScene();
    $cle = '7c1d2e3f-2049-4a5b-9c6d-0000000000b1';

    actingAs($compte)
        ->post(route('templates.execute', $modele), [], ['Idempotency-Key' => $cle])
        ->assertRedirect();

    $seance = Workout::query()->where('user_id', $compte->id)->sole();

    expect($seance->idempotency_key)->toBe($cle)
        ->and(lancementIdempotentLignes($seance))->toBe([
            ['exercice' => $exercices[0]->id, 'cle' => "modele-ligne:0:{$exercices[0]->id}", 'series' => ['modele-serie:0:0']],
            ['exercice' => $exercices[1]->id, 'cle' => "modele-ligne:1:{$exercices[1]->id}", 'series' => ['modele-serie:1:0', 'modele-serie:1:1', 'modele-serie:1:2']],
        ]);
});

/*
 * Un lancement rejoué sous sa clé mène à la séance qu'il a ouverte : pas de
 * message « une séance est déjà en cours », qui viserait sa propre séance, et
 * rien de recopié deux fois.
 */
it('mène le lancement rejoué à sa séance, sans message ni ligne en double', function (): void {
    ['compte' => $compte, 'modele' => $modele] = lancementIdempotentScene();
    $cle = '7c1d2e3f-2049-4a5b-9c6d-0000000000b2';

    actingAs($compte)->post(route('templates.execute', $modele), [], ['Idempotency-Key' => $cle]);
    $seance = Workout::query()->where('user_id', $compte->id)->sole();

    actingAs($compte)
        ->post(route('templates.execute', $modele), [], ['Idempotency-Key' => $cle])
        ->assertRedirect(route('workouts.show', $seance))
        ->assertSessionMissing('error');

    expect(Workout::query()->where('user_id', $compte->id)->count())->toBe(1)
        ->and(WorkoutLine::query()->where('workout_id', $seance->id)->count())->toBe(2)
        ->and(Set::query()->where('user_id', $compte->id)->count())->toBe(4);
});

dataset('lancements arrivés au serveur', [
    'par la page' => [fn (User $compte, WorkoutTemplate $modele, string $cle) => actingAs($compte)->post(route('templates.execute', $modele), [], ['Idempotency-Key' => $cle])->assertRedirect()],
    'par l’API' => [fn (User $compte, WorkoutTemplate $modele, string $cle) => actingAs($compte)->postJson(route('api.v1.workouts.store'), ['template_id' => $modele->id], ['Idempotency-Key' => $cle])->assertCreated()],
]);

/*
 * Le lancement est arrivé au serveur, la réponse perdue : la file le rejoue
 * décomposé, comme elle l'a mis en file sans réseau. La séance sous sa clé,
 * sans modèle, puis chaque ligne et chaque série sous les clés de leur rang.
 * Chaque écriture rend ce qui existe : rien ne double.
 */
it('reprend par la file un lancement déjà arrivé au serveur, sans rien doubler', function (Closure $lancer): void {
    ['compte' => $compte, 'modele' => $modele] = lancementIdempotentScene();
    $cle = '7c1d2e3f-2049-4a5b-9c6d-0000000000b4';
    $lancer($compte, $modele, $cle);
    $seance = Workout::query()->where('user_id', $compte->id)->sole();
    $avant = lancementIdempotentLignes($seance);

    $rejeu = actingAs($compte)->postJson(route('api.v1.workouts.store'), ['name' => 'Jambes et dos'], ['Idempotency-Key' => $cle])->assertOk();
    expect($rejeu->json('data.id'))->toBe($seance->id);

    $modele->load('workoutTemplateLines.workoutTemplateSets');

    foreach ($modele->workoutTemplateLines->values() as $rangLigne => $ligneDuModele) {
        $ligne = actingAs($compte)->postJson(
            route('api.v1.workout-lines.store'),
            ['workout_id' => $seance->id, 'exercise_id' => $ligneDuModele->exercise_id, 'order' => $ligneDuModele->order],
            ['Idempotency-Key' => WorkoutTemplate::cleDUneLigneLancee($rangLigne, $ligneDuModele->exercise_id)],
        )->assertOk();

        foreach ($ligneDuModele->workoutTemplateSets->values() as $rangSerie => $serieDuModele) {
            actingAs($compte)->postJson(
                route('api.v1.sets.store'),
                ['workout_line_id' => $ligne->json('data.id'), 'reps' => $serieDuModele->reps, 'weight' => $serieDuModele->weight, 'is_completed' => false],
                ['Idempotency-Key' => WorkoutTemplate::cleDUneSerieLancee($rangLigne, $rangSerie)],
            )->assertOk();
        }
    }

    expect(lancementIdempotentLignes($seance))->toBe($avant)
        ->and(Workout::query()->where('user_id', $compte->id)->count())->toBe(1)
        ->and(Set::query()->where('user_id', $compte->id)->count())->toBe(4);
})->with('lancements arrivés au serveur');

/*
 * Le modèle a changé entre le lancement et le rejeu : au rang 0, un autre
 * exercice. L'écriture rejouée, sous la clé du nouvel exercice, crée une
 * ligne de plus ; ses séries, rejouées sous la nouvelle ligne, ne se
 * rattachent jamais à l'exercice déjà recopié.
 */
it('crée une ligne de plus pour un exercice changé dans le modèle, sans toucher à l’autre', function (): void {
    ['compte' => $compte, 'modele' => $modele, 'exercices' => $exercices] = lancementIdempotentScene();
    actingAs($compte)->post(route('templates.execute', $modele), [], ['Idempotency-Key' => '7c1d2e3f-2049-4a5b-9c6d-0000000000b3']);
    $seance = Workout::query()->where('user_id', $compte->id)->sole();
    $autre = Exercise::factory()->create(['user_id' => $compte->id, 'name' => 'Fente']);
    $avant = lancementIdempotentLignes($seance);

    $ligne = actingAs($compte)->postJson(
        route('api.v1.workout-lines.store'),
        ['workout_id' => $seance->id, 'exercise_id' => $autre->id, 'order' => 0],
        ['Idempotency-Key' => WorkoutTemplate::cleDUneLigneLancee(0, $autre->id)],
    )->assertCreated()->json('data.id');
    actingAs($compte)->postJson(
        route('api.v1.sets.store'),
        ['workout_line_id' => $ligne, 'reps' => 5, 'weight' => 100],
        ['Idempotency-Key' => WorkoutTemplate::cleDUneSerieLancee(0, 0)],
    )->assertCreated();

    $apres = lancementIdempotentLignes($seance);

    expect(array_slice($apres, 0, 2))->toBe($avant)
        ->and($apres[2])->toBe(['exercice' => $autre->id, 'cle' => "modele-ligne:0:{$autre->id}", 'series' => ['modele-serie:0:0']])
        ->and(WorkoutLine::query()->where('workout_id', $seance->id)->where('exercise_id', $exercices[0]->id)->sole()->sets()->count())->toBe(1);
});

/*
 * Lancer un modèle ouvre une séance : la fréquence et l'historique en cache
 * la comptent. Le lancement laissait ce cache tel quel, jusqu'à l'expiration.
 */
it('vide le cache des statistiques des séances au lancement', function (): void {
    ['compte' => $compte, 'modele' => $modele] = lancementIdempotentScene();
    Cache::put(ClesDeStats::seances($compte, 'duration_history.20'), ['périmé'], 600);

    actingAs($compte)->post(route('templates.execute', $modele))->assertRedirect();

    expect(Cache::has(ClesDeStats::seances($compte, 'duration_history.20')))->toBeFalse();
});

/**
 * Les lancements que la page des modèles donne à ce compte.
 *
 * @return array<int, array{name: string, lignes: list<array{exercise: array{id: int, name: string|null, type: string|null}, order: int, cle: string, series: list<array{reps: int|float|null, weight: int|float|null, is_warmup: bool, cle: string}>}>}>
 */
function lancementsDeLaPageDesModeles(User $compte): array
{
    /** @var array{props: array{lancements: array<int, array{name: string, lignes: list<array{exercise: array{id: int, name: string|null, type: string|null}, order: int, cle: string, series: list<array{reps: int|float|null, weight: int|float|null, is_warmup: bool, cle: string}>}>}>}} $page */
    $page = actingAs($compte)->get(route('templates.index'))->assertOk()->viewData('page');

    return $page['props']['lancements'];
}

/*
 * La page des modèles donne à la file de quoi lancer chaque modèle sans
 * réseau : les clés et les valeurs que le serveur pose lui-même au lancement
 * (une seule source, `CreateWorkoutFromTemplateAction::lignesDuLancement()`).
 * Rejouées sous ces clés, les écritures retrouvent ce qu'un lancement arrivé
 * au serveur a créé.
 */
it('donne dans la page des modèles les clés et les valeurs que le lancement pose', function (): void {
    ['compte' => $compte, 'modele' => $modele, 'exercices' => $exercices] = lancementIdempotentScene();
    // Une série d'avant les plafonds : la page la donne ramenée, comme le lancement la recopie.
    WorkoutTemplateSet::query()
        ->where('workout_template_line_id', $modele->workoutTemplateLines()->where('order', 0)->value('id'))
        ->sole()
        ->forceFill(['weight' => Set::POIDS_MAX_KG + 50])
        ->saveQuietly();

    $lancements = lancementsDeLaPageDesModeles($compte);
    actingAs($compte)->post(route('templates.execute', $modele), [], ['Idempotency-Key' => '7c1d2e3f-2049-4a5b-9c6d-0000000000b5']);
    $seance = Workout::query()->where('user_id', $compte->id)->sole();

    $creees = array_map(static fn (WorkoutLine $ligne): array => [
        'exercise' => $ligne->exercise_id,
        'order' => $ligne->order,
        'cle' => $ligne->idempotency_key,
        'series' => array_values($ligne->sets->sortBy('order')->map(static fn (Set $serie): array => [
            'reps' => $serie->reps,
            'weight' => $serie->weight,
            'is_warmup' => $serie->is_warmup,
            'cle' => $serie->idempotency_key,
        ])->all()),
    ], $seance->workoutLines()->with('sets')->get()->all());
    $donnees = array_map(static fn (array $ligne): array => [
        'exercise' => $ligne['exercise']['id'],
        'order' => $ligne['order'],
        'cle' => $ligne['cle'],
        'series' => array_map(static fn (array $serie): array => [
            'reps' => $serie['reps'],
            'weight' => (float) $serie['weight'],
            'is_warmup' => $serie['is_warmup'],
            'cle' => $serie['cle'],
        ], $ligne['series']),
    ], $lancements[$modele->id]['lignes']);

    expect($donnees)->toBe($creees)
        ->and($lancements[$modele->id]['name'])->toBe('Jambes et dos')
        ->and($lancements[$modele->id]['lignes'][0]['exercise'])->toBe(['id' => $exercices[0]->id, 'name' => 'Squat', 'type' => $exercices[0]->type])
        ->and($lancements[$modele->id]['lignes'][0]['series'][0]['weight'])->toEqual(Set::POIDS_MAX_KG);
});

it('ne donne que les modèles du compte', function (): void {
    ['compte' => $compte, 'modele' => $modele] = lancementIdempotentScene();
    $autre = lancementIdempotentScene();

    $lancements = lancementsDeLaPageDesModeles($compte);

    expect(array_keys($lancements))->toBe([$modele->id])
        ->and($lancements)->not->toHaveKey($autre['modele']->id);
});

/*
 * Quatre requêtes quel que soit le nombre de modèles : les modèles, leurs
 * lignes, leurs exercices, leurs séries. Une par modèle ou par ligne ferait
 * attendre la page autant de fois.
 */
it('lit les lancements en quatre requêtes, quel que soit le nombre de modèles', function (): void {
    ['compte' => $compte] = lancementIdempotentScene();
    $action = app(FetchWorkoutTemplatesAction::class);
    $compter = static function () use ($action, $compte): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $action->lancements($compte);
        $requetes = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $requetes;
    };

    $avecUn = $compter();

    foreach (range(1, 4) as $rang) {
        $modele = WorkoutTemplate::factory()->create(['user_id' => $compte->id, 'name' => "Modèle {$rang}"]);

        foreach (range(0, 2) as $ordre) {
            $ligne = WorkoutTemplateLine::factory()->create(['workout_template_id' => $modele->id, 'exercise_id' => Exercise::factory()->create(['user_id' => $compte->id])->id, 'order' => $ordre]);
            WorkoutTemplateSet::factory()->count(2)->create(['workout_template_line_id' => $ligne->id]);
        }
    }

    expect($avecUn)->toBe(4)
        ->and($compter())->toBe(4);
});
