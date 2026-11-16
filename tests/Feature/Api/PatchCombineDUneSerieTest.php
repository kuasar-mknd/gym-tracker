<?php

declare(strict_types=1);

use App\Models\Achievement;
use App\Models\Exercise;
use App\Models\Goal;
use App\Models\PersonalRecord;
use App\Models\Set;
use App\Models\User;
use App\Models\UserAchievement;
use App\Models\Workout;
use App\Models\WorkoutLine;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/*
 * #2349 : la page envoie « poids, répétitions, coche » en trois PATCH. Un seul
 * PATCH porteur des trois champs doit laisser EXACTEMENT le même état.
 *
 * Deux mondes identiques (deux comptes, mêmes séances, mêmes séries), l'un
 * reçoit les trois PATCH dans l'ordre où le client les envoie (les champs en
 * attente, puis la coche), l'autre le PATCH combiné. Les photographies des deux
 * sont comparées : séries, volume, records, série de jours, succès, objectifs.
 * Les identifiants diffèrent d'un monde à l'autre : tout est ramené à des rangs.
 */

beforeEach(function (): void {
    Carbon::setTestNow(Carbon::parse('2026-10-07 18:00:00', 'Europe/Paris'));
});

afterEach(function (): void {
    Carbon::setTestNow();
});

/**
 * Les succès communs aux deux mondes : un par métrique que le service évalue.
 *
 * @return array<string, Achievement>
 */
function succesPatchCombine(): array
{
    return [
        'count' => Achievement::factory()->create(['slug' => 'equiv-count', 'type' => 'count', 'threshold' => 1]),
        'streak' => Achievement::factory()->create(['slug' => 'equiv-streak', 'type' => 'streak', 'threshold' => 1]),
        'volume' => Achievement::factory()->create(['slug' => 'equiv-volume', 'type' => 'volume_total', 'threshold' => 1000]),
        'record' => Achievement::factory()->create(['slug' => 'equiv-record', 'type' => 'weight_record', 'threshold' => 100]),
    ];
}

/**
 * Un monde : le compte, sa séance (ouverte ou terminée), une première série
 * cochée qui tient déjà des records, et la série cible.
 *
 * @param  array{termine: bool, cible: array<string, mixed>}  $plan
 * @return array{user: User, workout: Workout, exercice: Exercise, cible: Set}
 */
function mondePatchCombine(array $plan, Exercise $exercice): array
{
    $user = User::factory()->create();
    $debut = Carbon::parse('2026-10-07 10:00:00');

    $workout = Workout::factory()->create([
        'user_id' => $user->id,
        'started_at' => $debut,
        'ended_at' => $plan['termine'] ? $debut->copy()->addHour() : null,
    ]);
    $ligne = WorkoutLine::factory()->create(['workout_id' => $workout->id, 'exercise_id' => $exercice->id]);

    Set::factory()->create([
        'workout_line_id' => $ligne->id, 'order' => 1,
        'weight' => 60, 'reps' => 5, 'is_completed' => true,
    ]);
    $cible = Set::factory()->create(['workout_line_id' => $ligne->id, 'order' => 2] + $plan['cible']);

    Goal::factory()->create([
        'user_id' => $user->id, 'type' => 'volume', 'exercise_id' => $exercice->id,
        'target_value' => 1500, 'current_value' => 0, 'start_value' => 0, 'deadline' => '2027-01-01',
    ]);
    Goal::factory()->create([
        'user_id' => $user->id, 'type' => 'weight', 'exercise_id' => $exercice->id,
        'target_value' => 100, 'current_value' => 0, 'start_value' => 0, 'deadline' => '2027-01-01',
    ]);
    Goal::factory()->create([
        'user_id' => $user->id, 'type' => 'frequency', 'exercise_id' => null,
        'target_value' => 1, 'current_value' => 0, 'start_value' => 0, 'deadline' => '2027-01-01',
    ]);

    return ['user' => $user, 'workout' => $workout, 'exercice' => $exercice, 'cible' => $cible];
}

/**
 * Tout ce qu'un PATCH de série peut laisser derrière lui, sans identifiant.
 *
 * @param  array{user: User, workout: Workout, exercice: Exercise, cible: Set}  $monde
 * @return array<string, mixed>
 */
function photographiePatchCombine(array $monde): array
{
    $user = $monde['user']->fresh();
    $rangs = Set::query()
        ->whereIn('workout_line_id', WorkoutLine::query()->where('workout_id', $monde['workout']->id)->pluck('id'))
        ->orderBy('id')->pluck('id')->values()->flip();

    return [
        'series' => Set::query()->whereIn('id', $rangs->keys())->orderBy('id')
            ->get(['weight', 'reps', 'duration_seconds', 'distance_km', 'is_warmup', 'is_completed', 'order'])->toArray(),
        'volume_de_la_seance' => (float) $monde['workout']->fresh()->workout_volume,
        'records' => PersonalRecord::query()->where('user_id', $user->id)->orderBy('type')->orderBy('id')->get()
            ->map(fn (PersonalRecord $r): array => [
                'type' => $r->type,
                'valeur' => (float) $r->value,
                'secondaire' => $r->secondary_value === null ? null : (float) $r->secondary_value,
                'rang_de_la_serie' => $r->set_id === null ? null : ($rangs[$r->set_id] ?? 'hors-seance'),
            ])->all(),
        'serie_de_jours' => [$user->current_streak, $user->longest_streak, $user->last_workout_at?->toIso8601String()],
        'succes' => UserAchievement::query()->where('user_id', $user->id)->with('achievement')->get()
            ->map(fn (UserAchievement $u): string => (string) $u->achievement->slug)->sort()->values()->all(),
        'objectifs' => Goal::query()->where('user_id', $user->id)->orderBy('type')->get()
            ->map(fn (Goal $g): array => [
                'type' => $g->type->value,
                'courant' => round((float) $g->current_value, 2),
                'atteint' => $g->completed_at !== null,
            ])->all(),
    ];
}

/**
 * Les PATCH dans l'ordre du client : les champs en attente d'abord (un PATCH
 * chacun), la coche ensuite.
 *
 * @param  array<string, mixed>  $champs
 * @return array{ecritures: int, requetes: int}
 */
function envoyerSepareParPatchCombine(User $user, Set $set, array $champs): array
{
    $total = ['ecritures' => 0, 'requetes' => 0];

    foreach ($champs as $nom => $valeur) {
        $mesure = mesurerPatchCombine(fn () => test()->actingAs($user, 'sanctum')
            ->patchJson(route('api.v1.sets.update', $set), [$nom => $valeur])
            ->assertOk());
        $total['ecritures'] += $mesure['ecritures'];
        $total['requetes'] += $mesure['requetes'];
    }

    return $total;
}

/**
 * Le PATCH combiné, et ce qu'il a coûté.
 *
 * @param  array<string, mixed>  $champs
 * @return array{ecritures: int, requetes: int}
 */
function envoyerCombinePatchCombine(User $user, Set $set, array $champs): array
{
    return mesurerPatchCombine(fn () => test()->actingAs($user, 'sanctum')
        ->patchJson(route('api.v1.sets.update', $set), $champs)
        ->assertOk());
}

/**
 * @return array{ecritures: int, requetes: int}
 */
function mesurerPatchCombine(Closure $travail): array
{
    $ecritures = 0;
    $requetes = 0;
    DB::listen(function ($requete) use (&$ecritures, &$requetes): void {
        $requetes++;
        if (preg_match('/^\s*(insert|update|delete)\b/i', $requete->sql) === 1) {
            $ecritures++;
        }
    });
    $travail();

    return ['ecritures' => $ecritures, 'requetes' => $requetes];
}

dataset('parcours', [
    'série neuve, séance ouverte : poids, reps, coche' => [
        ['termine' => false, 'cible' => ['weight' => null, 'reps' => null, 'is_completed' => false]],
        ['weight' => 100, 'reps' => 10, 'is_completed' => true],
    ],
    'série préremplie corrigée puis cochée (record battu, seuils franchis)' => [
        ['termine' => false, 'cible' => ['weight' => 50, 'reps' => 8, 'is_completed' => false]],
        ['weight' => 120, 'reps' => 9, 'is_completed' => true],
    ],
    'série cochée corrigée puis décochée (records retirés, volume défait)' => [
        ['termine' => false, 'cible' => ['weight' => 100, 'reps' => 10, 'is_completed' => true]],
        ['weight' => 40, 'reps' => 3, 'is_completed' => false],
    ],
    'série cochée dont on ne corrige que le poids' => [
        ['termine' => false, 'cible' => ['weight' => 100, 'reps' => 10, 'is_completed' => true]],
        ['weight' => 110],
    ],
    'champ vidé puis coche : poids effacé, série cochée sans poids' => [
        ['termine' => false, 'cible' => ['weight' => 80, 'reps' => 10, 'is_completed' => false]],
        ['weight' => null, 'is_completed' => true],
    ],
    'durée et distance, puis coche' => [
        ['termine' => false, 'cible' => ['weight' => null, 'reps' => null, 'is_completed' => false]],
        ['duration_seconds' => 1800, 'distance_km' => 5.5, 'is_completed' => true],
    ],
    'poids à zéro puis coche (aucun record)' => [
        ['termine' => false, 'cible' => ['weight' => 20, 'reps' => 10, 'is_completed' => false]],
        ['weight' => 0, 'reps' => 10, 'is_completed' => true],
    ],
]);

test('un PATCH combiné laisse le même état que les PATCH séquentiels', function (array $plan, array $champs): void {
    succesPatchCombine();
    $exercice = Exercise::factory()->create();

    $separe = mondePatchCombine($plan, $exercice);
    $combine = mondePatchCombine($plan, $exercice);

    envoyerSepareParPatchCombine($separe['user'], $separe['cible'], $champs);
    envoyerCombinePatchCombine($combine['user'], $combine['cible'], $champs);

    expect(photographiePatchCombine($combine))->toEqual(photographiePatchCombine($separe));

    // La série de jours ne bouge qu'à la fin de la séance (l'API refuse toute
    // série d'une séance terminée) : la fin, faite par la page, la reconstruit
    // depuis les séries que les deux chemins ont laissées.
    foreach ([$separe, $combine] as $monde) {
        $this->actingAs($monde['user'])
            ->patch(route('workouts.update', $monde['workout']), ['is_finished' => true])
            ->assertRedirect(route('dashboard'));
    }

    expect(photographiePatchCombine($combine))->toEqual(photographiePatchCombine($separe));
})->with('parcours');

test('une séance terminée refuse le PATCH combiné comme chacun des PATCH séparés', function (): void {
    succesPatchCombine();
    $exercice = Exercise::factory()->create();
    $plan = ['termine' => true, 'cible' => ['weight' => 50, 'reps' => 8, 'is_completed' => false]];
    $separe = mondePatchCombine($plan, $exercice);
    $combine = mondePatchCombine($plan, $exercice);
    $avant = photographiePatchCombine($combine);

    foreach (['weight' => 100, 'reps' => 10, 'is_completed' => true] as $nom => $valeur) {
        $this->actingAs($separe['user'], 'sanctum')
            ->patchJson(route('api.v1.sets.update', $separe['cible']), [$nom => $valeur])->assertForbidden();
    }
    $this->actingAs($combine['user'], 'sanctum')
        ->patchJson(route('api.v1.sets.update', $combine['cible']), ['weight' => 100, 'reps' => 10, 'is_completed' => true])
        ->assertForbidden();

    expect(photographiePatchCombine($combine))->toEqual($avant)->toEqual(photographiePatchCombine($separe));
});

test('le monde de départ est déjà identique, et le PATCH le fait bouger', function (): void {
    succesPatchCombine();
    $exercice = Exercise::factory()->create();
    $plan = ['termine' => false, 'cible' => ['weight' => null, 'reps' => null, 'is_completed' => false]];

    $a = mondePatchCombine($plan, $exercice);
    $b = mondePatchCombine($plan, $exercice);
    expect(photographiePatchCombine($a))->toEqual(photographiePatchCombine($b));

    envoyerCombinePatchCombine($a['user'], $a['cible'], ['weight' => 100, 'reps' => 10, 'is_completed' => true]);
    $this->actingAs($a['user'])->patch(route('workouts.update', $a['workout']), ['is_finished' => true]);
    $apres = photographiePatchCombine($a);

    // Le test d'équivalence n'est pas vide : des records, un volume, des succès
    // et des objectifs ont bougé, et la série de jours aussi.
    expect($apres)->not->toEqual(photographiePatchCombine($b))
        ->and($apres['records'])->not->toBeEmpty()
        ->and($apres['volume_de_la_seance'])->toBe(1300.0)
        ->and($apres['succes'])->not->toBeEmpty()
        ->and($apres['serie_de_jours'][0])->toBeGreaterThan(0)
        ->and(collect($apres['objectifs'])->pluck('courant')->filter(fn ($v): bool => $v > 0))->not->toBeEmpty();
});

test('un PATCH combiné dont un champ est refusé n’applique rien du tout', function (): void {
    succesPatchCombine();
    $exercice = Exercise::factory()->create();
    $monde = mondePatchCombine(['termine' => false, 'cible' => ['weight' => 50, 'reps' => 8, 'is_completed' => false]], $exercice);
    $avant = photographiePatchCombine($monde);

    $this->actingAs($monde['user'], 'sanctum')
        ->patchJson(route('api.v1.sets.update', $monde['cible']), [
            'weight' => 100,
            'reps' => Set::REPETITIONS_MAX + 1,
            'is_completed' => true,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['reps'])
        ->assertJsonMissingValidationErrors(['weight', 'is_completed']);

    expect(photographiePatchCombine($monde))->toEqual($avant);
});

test('un PATCH combiné rejoué est idempotent', function (): void {
    succesPatchCombine();
    $exercice = Exercise::factory()->create();
    $monde = mondePatchCombine(['termine' => false, 'cible' => ['weight' => null, 'reps' => null, 'is_completed' => false]], $exercice);
    $champs = ['weight' => 100, 'reps' => 10, 'is_completed' => true];

    envoyerCombinePatchCombine($monde['user'], $monde['cible'], $champs);
    $apres = photographiePatchCombine($monde);
    envoyerCombinePatchCombine($monde['user'], $monde['cible'], $champs);

    expect(photographiePatchCombine($monde))->toEqual($apres);
});

test('le gain mesuré : requêtes HTTP et écritures SQL, trois PATCH contre un', function (): void {
    succesPatchCombine();
    $exercice = Exercise::factory()->create();
    $plan = ['termine' => false, 'cible' => ['weight' => null, 'reps' => null, 'is_completed' => false]];
    $champs = ['weight' => 100, 'reps' => 10, 'is_completed' => true];

    $separe = mondePatchCombine($plan, $exercice);
    $combine = mondePatchCombine($plan, $exercice);

    $avant = envoyerSepareParPatchCombine($separe['user'], $separe['cible'], $champs);
    $apres = envoyerCombinePatchCombine($combine['user'], $combine['cible'], $champs);

    expect($apres['ecritures'])->toBeLessThan($avant['ecritures'])
        ->and($apres['requetes'])->toBeLessThan($avant['requetes']);
});

/*
 * Les états INTERMÉDIAIRES. Séparés, les PATCH font passer le serveur par des
 * états que le PATCH combiné ne traverse jamais : une série cochée dont on
 * corrige poids puis répétitions fut un instant 200 x 10. Ce que ce passage
 * a verrouillé (un succès, un objectif atteint) ne se reprend pas.
 */
dataset('ordres', [
    'poids puis répétitions : l\'état intermédiaire dépasse le final' => [['weight', 'reps'], true],
    'répétitions puis poids : aucun pic intermédiaire' => [['reps', 'weight'], false],
]);

test('un état intermédiaire franchit un seuil que l’état final ne franchit pas : où l’équivalence cesse', function (array $ordre, bool $divergence): void {
    succesPatchCombine();
    $exercice = Exercise::factory()->create();
    $plan = ['termine' => false, 'cible' => ['weight' => 20, 'reps' => 10, 'is_completed' => true]];
    $final = ['weight' => 120, 'reps' => 1];

    $separe = mondePatchCombine($plan, $exercice);
    $combine = mondePatchCombine($plan, $exercice);

    envoyerSepareParPatchCombine($separe['user'], $separe['cible'], array_replace(array_flip($ordre), $final));
    envoyerCombinePatchCombine($combine['user'], $combine['cible'], $final);

    $s = photographiePatchCombine($separe);
    $c = photographiePatchCombine($combine);

    expect($c['series'])->toEqual($s['series'])
        ->and($c['volume_de_la_seance'])->toEqual($s['volume_de_la_seance'])
        ->and($c['records'])->toEqual($s['records']);

    // Le volume cumulé (1 000) n'est franchi que par l'état intermédiaire
    // 120 x 10 ; l'état final (120 x 1) ne pèse que 420. Un succès débloqué
    // ne se reprend pas : les PATCH séparés l'ont, le combiné non.
    if ($divergence) {
        expect($s['succes'])->toContain('equiv-volume')
            ->and($c['succes'])->not->toContain('equiv-volume');
    } else {
        expect($c['succes'])->toEqual($s['succes'])
            ->and($c['objectifs'])->toEqual($s['objectifs']);
    }
})->with('ordres');

test('la réponse du PATCH combiné est celle du dernier PATCH séparé', function (): void {
    succesPatchCombine();
    $exercice = Exercise::factory()->create();
    $plan = ['termine' => false, 'cible' => ['weight' => 50, 'reps' => 8, 'is_completed' => false]];
    $champs = ['weight' => 120, 'reps' => 9, 'is_completed' => true];

    $separe = mondePatchCombine($plan, $exercice);
    $combine = mondePatchCombine($plan, $exercice);

    $derniere = null;
    foreach ($champs as $nom => $valeur) {
        $derniere = $this->actingAs($separe['user'], 'sanctum')
            ->patchJson(route('api.v1.sets.update', $separe['cible']), [$nom => $valeur])->assertOk()->json('data');
    }
    $reponse = $this->actingAs($combine['user'], 'sanctum')
        ->patchJson(route('api.v1.sets.update', $combine['cible']), $champs)->assertOk()->json('data');

    foreach (['weight', 'reps', 'is_completed', 'is_warmup', 'duration_seconds', 'distance_km'] as $cle) {
        expect($reponse[$cle])->toEqual($derniere[$cle]);
    }
    expect(Arr::only($reponse['personal_record'], ['type', 'value', 'secondary_value']))->toEqual(Arr::only($derniere['personal_record'], ['type', 'value', 'secondary_value']))
        ->and(array_keys($reponse))->toEqual(array_keys($derniere));
});
