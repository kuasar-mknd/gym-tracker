<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Set;
use App\Models\User;
use App\Models\Workout;
use App\Models\WorkoutLine;
use App\Models\WorkoutTemplate;
use App\Models\WorkoutTemplateLine;
use App\Models\WorkoutTemplateSet;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Recopie une séance en modèle.
 *
 * Le modèle obtenu tient dans les bornes que ses requêtes de création et de
 * modification imposent (`BorneLesSeriesDuGabarit`) : au plus
 * `WorkoutTemplate::EXERCICES_MAX` exercices, `SERIES_MAX_PAR_EXERCICE` séries
 * par exercice, des répétitions et un poids sous ceux d'une série. Une séance
 * n'a pas ces plafonds, et ses séries anciennes peuvent précéder les bornes
 * d'une série : recopiée telle quelle, elle écrivait autant de lignes qu'elle
 * portait de séries, et donnait un modèle que la modification refusait
 * ensuite en entier, jusqu'à son nom.
 */
final class CreateWorkoutTemplateFromWorkoutAction
{
    /**
     * Le suffixe qui distingue le modèle de la séance dont il vient.
     */
    private const string SUFFIXE_DU_NOM = ' (Modèle)';

    /**
     * La longueur d'un nom de modèle : `workout_templates.name`
     * (varchar(255)), et la règle `max:255` de ses requêtes.
     */
    private const int LONGUEUR_MAX_DU_NOM = 255;

    public function execute(User $user, Workout $workout): WorkoutTemplate
    {
        // Chargement anticipé : la copie parcourt chaque ligne et chacune de ses
        // séries, et les relirait une par une sinon.
        $workout->load(['workoutLines.sets']);

        return DB::transaction(function () use ($user, $workout): \App\Models\WorkoutTemplate {
            $template = new WorkoutTemplate([
                'name' => $this->nomDuModele($workout),
                'description' => 'Créé à partir de la séance du '.($workout->created_at?->format('d/m/Y') ?? now()->format('d/m/Y')),
            ]);
            $template->user_id = $user->id;
            $template->save();

            $this->copyExercises($template, $workout);

            return $template;
        });
    }

    /**
     * La séance dépasse-t-elle ce qu'un modèle garde ?
     *
     * Vrai si elle porte plus d'exercices ou, sur un exercice repris, plus de
     * séries qu'un modèle n'en accepte, ou une valeur qu'une série n'accepte
     * plus. Le modèle en est alors une version ramenée aux bornes, ce que le
     * message de confirmation doit dire.
     */
    public function depasseLesBornesDUnModele(Workout $workout): bool
    {
        $workout->loadMissing(['workoutLines.sets']);

        if ($workout->workoutLines->count() > WorkoutTemplate::EXERCICES_MAX) {
            return true;
        }

        foreach ($this->lignesReprises($workout) as $ligne) {
            if ($ligne->sets->count() > WorkoutTemplate::SERIES_MAX_PAR_EXERCICE) {
                return true;
            }

            foreach ($this->seriesReprises($ligne) as $serie) {
                if (Set::ramenerALaBorne('reps', $serie->reps) !== $serie->reps
                    || Set::ramenerALaBorne('weight', $serie->weight) !== $serie->weight) {
                    return true;
                }
            }
        }

        return false;
    }

    private function copyExercises(WorkoutTemplate $template, Workout $workout): void
    {
        $now = now();
        $linesData = [];

        $workoutLines = $this->lignesReprises($workout);

        foreach ($workoutLines as $line) {
            $linesData[] = [
                'workout_template_id' => $template->id,
                'exercise_id' => $line->exercise_id,
                'order' => $line->order,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if ($linesData === []) {
            return;
        }

        WorkoutTemplateLine::insert($linesData);

        $templateLines = $template->workoutTemplateLines()->orderBy('id')->get();

        $donneesDesSeries = [];

        /*
         * `$sourceLine` et non `$line` : la boucle precedente laisse son `$line`
         * en portee, et reprendre le nom cachait laquelle des deux on lisait.
         * Les deux parcourent la meme collection pour deux raisons distinctes —
         * batir les lignes du modele, puis leurs series.
         */
        foreach ($workoutLines as $index => $sourceLine) {
            if (! isset($templateLines[$index])) {
                continue;
            }
            $templateLine = $templateLines[$index];

            foreach ($this->seriesReprises($sourceLine) as $set) {
                $donneesDesSeries[] = [
                    'workout_template_line_id' => $templateLine->id,
                    'reps' => Set::ramenerALaBorne('reps', $set->reps),
                    'weight' => Set::ramenerALaBorne('weight', $set->weight),
                    'is_warmup' => $set->is_warmup,
                    'order' => $set->id, // Un ordre grossier, faute de mieux pour l'instant.
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        if ($donneesDesSeries !== []) {
            // Par lots : SQL plafonne le nombre de paramètres d'une instruction
            // (999 sous SQLite, en général).
            foreach (array_chunk($donneesDesSeries, 100) as $chunk) {
                WorkoutTemplateSet::insert($chunk);
            }
        }
    }

    /**
     * Le nom de la séance suivi du suffixe, coupé pour tenir dans la colonne :
     * une séance accepte un nom de 255 caractères, et le suffixe le
     * dépasserait. Une séance sans nom prête au modèle celui que l'application
     * lui donne partout ailleurs, « Séance » (statistiques, liste et page des
     * séances).
     */
    private function nomDuModele(Workout $workout): string
    {
        $place = self::LONGUEUR_MAX_DU_NOM - mb_strlen(self::SUFFIXE_DU_NOM);

        return mb_substr($workout->name ?? __('Workout'), 0, $place).self::SUFFIXE_DU_NOM;
    }

    /**
     * Les exercices que le modèle reprend : les premiers de la séance, dans
     * son ordre, jusqu'au plafond d'un modèle.
     *
     * @return Collection<int, WorkoutLine>
     */
    private function lignesReprises(Workout $workout): Collection
    {
        return $workout->workoutLines->take(WorkoutTemplate::EXERCICES_MAX)->values();
    }

    /**
     * Les séries que le modèle reprend d'un exercice : les premières, dans
     * l'ordre de la séance, jusqu'au plafond d'un exercice de modèle.
     *
     * @return Collection<int, Set>
     */
    private function seriesReprises(WorkoutLine $ligne): Collection
    {
        return $ligne->sets->take(WorkoutTemplate::SERIES_MAX_PAR_EXERCICE)->values();
    }
}
