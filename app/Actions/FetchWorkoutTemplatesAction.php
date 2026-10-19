<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\User;
use App\Models\WorkoutTemplate;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\Relation;

class FetchWorkoutTemplatesAction
{
    /**
     * Les modèles de séance de l'utilisateur, avec le strict nécessaire à
     * l'aperçu de la page d'index : le compte de lignes et les trois premiers
     * exercices.
     *
     * @return Collection<int, WorkoutTemplate>
     */
    public function execute(User $user): Collection
    {
        return WorkoutTemplate::withCount('workoutTemplateLines')
            ->with([
                'workoutTemplateLines' => function (Relation $query): void {
                    /*
                     * `with()` declare `Closure(Relation<*, *, *>)` : la fermeture
                     * doit accepter le type le plus large, un `HasMany` n'est pas
                     * contravariant avec lui. Ici c'est un WorkoutTemplateLine de WorkoutTemplate.
                     */
                    $query->select('id', 'workout_template_id', 'exercise_id')
                        ->orderBy('order')
                        ->limit(3)
                        ->withCount('workoutTemplateSets')
                        ->with('exercise:id,name');
                },
            ])
            ->where('user_id', $user->id)
            ->latest()
            ->limit(100)
            ->get();
    }

    /**
     * De quoi lancer chaque modèle sans réseau (#2049), sous son identifiant :
     * son nom, et chaque ligne avec son exercice, son rang, sa clé, et chaque
     * série avec ses valeurs et sa clé, dans l'ordre du modèle.
     *
     * Sans réseau, la file met en file d'un seul coup la séance, ses lignes et
     * ses séries, sous les clés que le serveur pose lui-même quand il lance le
     * modèle : si le lancement est arrivé au serveur et que la réponse s'est
     * perdue, chaque écriture rejouée rend celle qui existe. Une seule source
     * pour les clés et les valeurs, celle de l'action
     * (`CreateWorkoutFromTemplateAction::lignesDuLancement()`). Les mêmes
     * modèles que l'aperçu (`execute()`), en quatre requêtes quel que soit
     * leur nombre : les modèles, leurs lignes, leurs exercices, leurs séries.
     *
     * Une prop ordinaire, et non différée : la page des modèles est gardée
     * pour la salle, et le préchauffage des copies ne demande les props
     * différées que de l'accueil.
     *
     * @return array<int, array{name: string, lignes: list<array{exercise: array{id: int, name: string|null, type: string|null}, order: int, cle: string, series: list<array{reps: int|float|null, weight: int|float|null, is_warmup: bool, cle: string}>}>}>
     */
    public function lancements(User $user): array
    {
        $modeles = WorkoutTemplate::query()
            ->with(['workoutTemplateLines.exercise:id,name,type', 'workoutTemplateLines.workoutTemplateSets'])
            ->where('user_id', $user->id)
            ->latest()
            ->limit(100)
            ->get();

        $lancements = [];

        foreach ($modeles as $modele) {
            $lancements[$modele->id] = [
                'name' => $modele->name,
                'lignes' => array_map(static fn (array $ligne): array => [
                    'exercise' => [
                        'id' => $ligne['exercise_id'],
                        'name' => $ligne['modele']->exercise?->name,
                        'type' => $ligne['modele']->exercise?->type,
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
