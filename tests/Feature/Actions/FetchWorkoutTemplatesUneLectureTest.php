<?php

declare(strict_types=1);

use App\Actions\CreateWorkoutFromTemplateAction;
use App\Actions\FetchWorkoutTemplatesAction;
use App\Models\Exercise;
use App\Models\User;
use App\Models\WorkoutTemplate;
use App\Models\WorkoutTemplateLine;
use App\Models\WorkoutTemplateSet;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/*
 * La page des modèles lit ses cent modèles une fois (#2351, point 3), là où
 * l'aperçu et les lancements les relisaient chacun. Ces tests rejouent les
 * DEUX anciennes lectures, recopiées telles quelles, sur les mêmes données, et
 * exigent la même réponse, au caractère près une fois sérialisée.
 */

/**
 * L'ancien aperçu : une lecture à part, trois lignes par modèle.
 *
 * @return Collection<int, WorkoutTemplate>
 */
function apercuDesModelesDAvantUneLecture(User $compte): Collection
{
    return WorkoutTemplate::withCount('workoutTemplateLines')
        ->with([
            'workoutTemplateLines' => function (Relation $requete): void {
                $requete->select('id', 'workout_template_id', 'exercise_id')
                    ->orderBy('order')
                    ->limit(3)
                    ->withCount('workoutTemplateSets')
                    ->with('exercise:id,name');
            },
        ])
        ->where('user_id', $compte->id)
        ->latest()
        ->limit(100)
        ->get();
}

/**
 * Les anciens lancements : une seconde lecture des mêmes modèles.
 *
 * @return array<int, array<string, mixed>>
 */
function lancementsDesModelesDAvantUneLecture(User $compte): array
{
    $lancements = [];

    $modeles = WorkoutTemplate::query()
        ->with(['workoutTemplateLines.exercise:id,name,type', 'workoutTemplateLines.workoutTemplateSets'])
        ->where('user_id', $compte->id)
        ->latest()
        ->limit(100)
        ->get();

    foreach ($modeles as $modele) {
        $lancements[$modele->id] = [
            'name' => $modele->name,
            'lignes' => array_map(static fn (array $ligne): array => [
                'exercise' => [
                    'id' => $ligne['exercise_id'],
                    'name' => $ligne['modele']->exercise->name,
                    'type' => $ligne['modele']->exercise->type,
                ],
                'order' => $ligne['order'],
                'cle' => $ligne['cle'],
                'series' => array_map(static fn (array $serie): array => [
                    'reps' => $serie['reps'],
                    'weight' => $serie['weight'],
                    'is_warmup' => $serie['is_warmup'],
                    'cle' => $serie['cle'],
                ], $ligne['series']),
            ], CreateWorkoutFromTemplateAction::lignesDuLancement($modele)),
        ];
    }

    return $lancements;
}

/**
 * Des modèles de formes variées : sans ligne, avec une, quatre et six lignes,
 * des séries d'échauffement, un rang répété (le départage se fait par `id`), et
 * un voisin dont rien ne doit sortir.
 */
function semerLesModelesDeLaPage(User $compte): void
{
    Carbon::setTestNow(Carbon::parse('2026-06-15 12:00:00'));

    $voisin = User::factory()->create();
    WorkoutTemplate::factory()->create(['user_id' => $voisin->id, 'name' => 'Du voisin']);

    foreach ([0, 1, 4, 6] as $rang => $nombreDeLignes) {
        $modele = WorkoutTemplate::factory()->create([
            'user_id' => $compte->id,
            'name' => "Modèle à {$nombreDeLignes} lignes",
            'created_at' => now()->subDays($rang),
        ]);

        foreach (range(0, $nombreDeLignes - 1) as $ordre) {
            if ($nombreDeLignes === 0) {
                break;
            }

            $ligne = WorkoutTemplateLine::factory()->create([
                'workout_template_id' => $modele->id,
                'exercise_id' => Exercise::factory()->create(['user_id' => $compte->id])->id,
                'order' => intdiv($ordre, 2),
            ]);
            WorkoutTemplateSet::factory()->count($ordre % 3)->create(['workout_template_line_id' => $ligne->id]);
            WorkoutTemplateSet::factory()->create(['workout_template_line_id' => $ligne->id, 'is_warmup' => true, 'weight' => null, 'reps' => null]);
        }
    }
}

it('rend le même aperçu et les mêmes lancements que les deux lectures d’avant', function (): void {
    $compte = User::factory()->create();
    semerLesModelesDeLaPage($compte);

    $apres = app(FetchWorkoutTemplatesAction::class)->execute($compte);
    $apercuAvant = apercuDesModelesDAvantUneLecture($compte);
    $lancementsAvant = lancementsDesModelesDAvantUneLecture($compte);

    expect($apres['templates'])->toHaveCount(4)
        ->and($apres['templates']->pluck('name')->all())->toBe($apercuAvant->pluck('name')->all())
        ->and(json_encode($apres['templates']))->toBe(json_encode($apercuAvant))
        ->and($apres['lancements'])->toBe($lancementsAvant)
        ->and(array_keys($apres['lancements']))->toBe($apercuAvant->pluck('id')->all());

    // L'aperçu garde les colonnes d'avant, rien de plus.
    $modele = $apres['templates']->firstWhere('name', 'Modèle à 6 lignes');
    assert($modele instanceof WorkoutTemplate);
    expect($modele->workout_template_lines_count)->toBe(6)
        ->and($modele->workoutTemplateLines)->toHaveCount(3)
        ->and(array_keys($modele->workoutTemplateLines->firstOrFail()->getAttributes()))
        ->toBe(['id', 'workout_template_id', 'exercise_id', 'workout_template_sets_count'])
        ->and(array_keys($modele->workoutTemplateLines->firstOrFail()->exercise?->getAttributes() ?? []))
        ->toBe(['id', 'name']);
});

it('lit les modèles une seule fois : quatre requêtes là où il en fallait sept', function (): void {
    $compte = User::factory()->create();
    semerLesModelesDeLaPage($compte);

    $compter = static function (callable $appel): int {
        $nombre = 0;
        DB::listen(function (QueryExecuted $requete) use (&$nombre): void {
            $nombre++;
        });
        $appel();

        return $nombre;
    };

    $avant = $compter(function () use ($compte): void {
        apercuDesModelesDAvantUneLecture($compte);
        lancementsDesModelesDAvantUneLecture($compte);
    });
    $apres = $compter(fn (): array => app(FetchWorkoutTemplatesAction::class)->execute($compte));

    expect($avant)->toBe(7)
        ->and($apres)->toBe(4);
});
