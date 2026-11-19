<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\User;
use App\Models\WorkoutTemplate;
use App\Models\WorkoutTemplateLine;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Arr;

class FetchWorkoutTemplatesAction
{
    /**
     * Les cent modèles les plus récents de l'utilisateur, lus une seule fois
     * (#2351) pour les deux choses que la page des modèles en tire : l'aperçu
     * de chacun (`templates`, le compte de lignes et les trois premiers
     * exercices) et de quoi le lancer sans réseau (`lancements`, #2049).
     *
     * L'aperçu se déduit de la lecture complète au lieu d'en relancer une :
     * mêmes modèles, mêmes colonnes qu'avant (`id`, `workout_template_id`,
     * `exercise_id` et le compte de séries par ligne ; `id` et `name` par
     * exercice), sans relire la base.
     *
     * `lancements` : sous l'identifiant du modèle, son nom, et chaque ligne
     * avec son exercice, son rang, sa clé, et chaque série avec ses valeurs et
     * sa clé, dans l'ordre du modèle. Sans réseau, la file met en file d'un
     * seul coup la séance, ses lignes et ses séries, sous les clés que le
     * serveur pose lui-même quand il lance le modèle : si le lancement est
     * arrivé au serveur et que la réponse s'est perdue, chaque écriture
     * rejouée rend celle qui existe. Une seule source pour les clés et les
     * valeurs, celle de l'action
     * (`CreateWorkoutFromTemplateAction::lignesDuLancement()`). Quatre
     * requêtes quel que soit le nombre de modèles : les modèles, leurs
     * lignes, leurs exercices, leurs séries.
     *
     * Des props ordinaires, et non différées : la page des modèles est gardée
     * pour la salle, et le préchauffage des copies ne demande les props
     * différées que de l'accueil.
     *
     * @return array{
     *     templates: Collection<int, WorkoutTemplate>,
     *     lancements: array<int, array{name: string, lignes: list<array{exercise: array{id: int, name: string, type: string}, order: int, cle: string, series: list<array{reps: int|float|null, weight: int|float|null, is_warmup: bool, cle: string}>}>}>
     * }
     */
    public function execute(User $user): array
    {
        $modeles = WorkoutTemplate::query()
            ->with(['workoutTemplateLines.exercise:id,name,type', 'workoutTemplateLines.workoutTemplateSets'])
            ->where('user_id', $user->id)
            ->latest()
            ->limit(100)
            ->get();

        return [
            'templates' => $modeles->map(fn (WorkoutTemplate $modele): WorkoutTemplate => $this->apercu($modele)),
            'lancements' => $this->lancements($modeles),
        ];
    }

    /**
     * Le modèle tel que l'aperçu le montre : le compte de ses lignes, et ses
     * trois premières (déjà dans l'ordre du modèle) réduites aux colonnes de
     * l'aperçu. Une copie : le modèle lu garde ses lignes entières pour les
     * lancements.
     */
    private function apercu(WorkoutTemplate $modele): WorkoutTemplate
    {
        $apercu = clone $modele;
        $apercu->setAttribute('workout_template_lines_count', $modele->workoutTemplateLines->count());
        $apercu->setRelation('workoutTemplateLines', $modele->workoutTemplateLines->take(3)->map(function (WorkoutTemplateLine $ligne): WorkoutTemplateLine {
            $exercice = $ligne->exercise;
            /** @var array<string, mixed> $colonnesDeLaLigne */
            $colonnesDeLaLigne = Arr::only($ligne->getAttributes(), ['id', 'workout_template_id', 'exercise_id']);
            $courte = $ligne->newFromBuilder([...$colonnesDeLaLigne, 'workout_template_sets_count' => $ligne->workoutTemplateSets->count()]);
            /** @var array<string, mixed> $colonnesDeLExercice */
            $colonnesDeLExercice = Arr::only($exercice->getAttributes(), ['id', 'name']);
            $courte->setRelation('exercise', $exercice->newFromBuilder($colonnesDeLExercice));

            return $courte;
        })->values());

        return $apercu;
    }

    /**
     * @param  Collection<int, WorkoutTemplate>  $modeles
     * @return array<int, array{name: string, lignes: list<array{exercise: array{id: int, name: string, type: string}, order: int, cle: string, series: list<array{reps: int|float|null, weight: int|float|null, is_warmup: bool, cle: string}>}>}>
     */
    private function lancements(Collection $modeles): array
    {
        $lancements = [];

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
}
