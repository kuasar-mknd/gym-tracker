<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Set;
use App\Models\User;
use App\Models\Workout;
use App\Models\WorkoutTemplate;
use App\Models\WorkoutTemplateLine;
use App\Services\Stats\StatsCacheManager;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

final class CreateWorkoutFromTemplateAction
{
    public function __construct(private readonly StatsCacheManager $statsCache)
    {
    }

    /**
     * Ouvre une séance depuis un modèle : ses exercices et leurs séries,
     * dans l'ordre du modèle.
     *
     * Un seul instant sert au début de la séance et aux horodatages des
     * séries, que `Set::insert()` pose à la main : l'action lisait l'horloge
     * deux fois, et minuit pouvait passer entre les deux (`.ai/rules/horloge.md`).
     * Démarrée sur l'appareil, la séance reçoit ce début de
     * `DemarrerUneSeanceAction`, déjà borné (#2049) ; sans lui, l'action lit
     * l'horloge une fois.
     *
     * La séance porte la clé d'idempotence du démarrage, et chaque ligne et
     * chaque série la clé que leur rang dans le modèle dérive
     * (`WorkoutTemplate::cleDUneLigneLancee()`, `cleDUneSerieLancee()`) : un
     * lancement parti sans réponse, que la file rejoue écriture par écriture,
     * retrouve alors chacune au lieu de la doubler.
     *
     * Le cache des statistiques se vide après la transaction, comme à la
     * création d'une séance vide : la nouvelle séance compte dans la
     * fréquence et l'historique, que le lancement d'un modèle laissait
     * périmés jusqu'à l'expiration.
     */
    public function execute(User $user, WorkoutTemplate $template, ?CarbonInterface $debut = null, ?string $cle = null): Workout
    {
        $debut = ($debut ?? now())->copy()->setTimezone(config()->string('app.timezone'));

        // Optimize: Eager load relationships to prevent N+1 queries during iteration
        $template->load(['workoutTemplateLines.workoutTemplateSets']);

        $workout = DB::transaction(function () use ($user, $template, $debut, $cle): Workout {
            $workout = new Workout([
                'name' => $template->name,
                'started_at' => $debut,
            ]);
            $workout->user_id = $user->id;
            $workout->idempotency_key = $cle;
            $workout->save();

            $this->createLinesAndSets($workout, $template, $debut);

            return $workout;
        });

        $this->statsCache->clearWorkoutRelatedStats($user);

        return $workout;
    }

    /**
     * Ce qu'un lancement du modèle crée, ligne par ligne, dans l'ordre du
     * modèle (`order`, puis `id`) : l'exercice, le rang de la ligne, sa clé,
     * et chaque série avec son rang, ses valeurs et sa clé.
     *
     * Une seule source pour la séance que crée l'action et pour celle que la
     * file rejoue sans réseau (`FetchWorkoutTemplatesAction::lancements()`,
     * #2049) : les mêmes clés, les mêmes valeurs. Les répétitions et le poids
     * sont ramenés sous les plafonds d'une série (`Set::ramenerALaBorne()`) :
     * un modèle enregistré avant ces plafonds peut les dépasser, et la page
     * de séance recopie la dernière série d'un exercice dans la suivante, que
     * la requête refuserait alors. Le poids d'un modèle se lit en chaîne
     * (`decimal:2`) : il est converti avant.
     *
     * Les lignes du modèle et leurs séries doivent être chargées, sans quoi
     * chaque ligne les demande à la base.
     *
     * @return list<array{modele: WorkoutTemplateLine, exercise_id: int, order: int, cle: string, series: list<array{order: int, reps: int|float|null, weight: int|float|null, is_warmup: bool, cle: string}>}>
     */
    public static function lignesDuLancement(WorkoutTemplate $template): array
    {
        $lignes = [];

        foreach ($template->workoutTemplateLines->values() as $rangLigne => $templateLine) {
            $series = [];

            foreach ($templateLine->workoutTemplateSets->values() as $rangSerie => $templateSet) {
                $series[] = [
                    'order' => $rangSerie,
                    'reps' => Set::ramenerALaBorne('reps', is_numeric($templateSet->reps) ? (int) $templateSet->reps : null),
                    'weight' => Set::ramenerALaBorne('weight', is_numeric($templateSet->weight) ? (float) $templateSet->weight : null),
                    'is_warmup' => (bool) $templateSet->is_warmup,
                    'cle' => WorkoutTemplate::cleDUneSerieLancee($rangLigne, $rangSerie),
                ];
            }

            $lignes[] = [
                'modele' => $templateLine,
                'exercise_id' => $templateLine->exercise_id,
                'order' => $templateLine->order,
                'cle' => WorkoutTemplate::cleDUneLigneLancee($rangLigne, $templateLine->exercise_id),
                'series' => $series,
            ];
        }

        return $lignes;
    }

    /**
     * Recopie chaque ligne du modèle et ses séries dans la séance, sous les
     * clés et les valeurs de `lignesDuLancement()`, horodatées de l'instant
     * du lancement.
     */
    private function createLinesAndSets(Workout $workout, WorkoutTemplate $template, CarbonInterface $debut): void
    {
        $allSets = [];
        $horodatage = $debut->toDateTimeString();

        foreach (self::lignesDuLancement($template) as $ligneLancee) {
            $workoutLine = $workout->workoutLines()->make([
                'exercise_id' => $ligneLancee['exercise_id'],
                'order' => $ligneLancee['order'],
            ]);
            // Hors de `$fillable`, comme toute clé d'idempotence : elle se pose à la main.
            $workoutLine->idempotency_key = $ligneLancee['cle'];
            $workoutLine->save();

            foreach ($ligneLancee['series'] as $serie) {
                $allSets[] = [
                    'workout_line_id' => $workoutLine->id,
                    // `insert()` ne pose aucun defaut : sans ce rang, toutes
                    // les series de l'exercice partageraient un ordre nul et
                    // rien ne les departagerait.
                    'order' => $serie['order'],
                    // `insert()` ne declenche aucun evenement : la copie du
                    // proprietaire doit etre posee ici, sans quoi ces series
                    // seraient invisibles a `GET /api/v1/sets`.
                    'user_id' => $workoutLine->user_id,
                    'reps' => $serie['reps'],
                    'weight' => $serie['weight'],
                    'is_warmup' => $serie['is_warmup'],
                    'idempotency_key' => $serie['cle'],
                    'created_at' => $horodatage,
                    'updated_at' => $horodatage,
                ];
            }
        }

        if ($allSets !== []) {
            /*
             * `insert()` en masse pour eviter une requete par serie. Aucun
             * evenement `Set::saved` ne part, ce qui est desormais sans
             * consequence sur le volume : les series d'un modele arrivent non
             * validees, donc elles ne comptent pas encore.
             *
             * L'increment qui suivait cet appel creditait le volume complet du
             * modele au moment ou la seance s'ouvrait — le defaut de #1499. Il
             * est parti : `Workout::recalculerLeVolume()` s'en charge a la
             * premiere serie cochee, et sur les faits plutot que sur un total
             * calcule en PHP.
             */
            Set::insert($allSets);
        }
    }
}
