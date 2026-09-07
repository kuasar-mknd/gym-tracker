<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\GoalType;
use App\Models\Goal;
use App\Models\User;

/**
 * Tient l'avancement des objectifs d'un utilisateur d'après son activité —
 * séances et mesures — et décide s'ils sont atteints.
 *
 * Quatre types cohabitent : la charge, la fréquence, le volume et les
 * mensurations, et chacun se lit à un endroit différent.
 */
final class GoalService
{
    /**
     * Recalcule l'avancement de tous les objectifs d'un utilisateur.
     *
     * Appelé après l'enregistrement d'une séance ou d'une mesure.
     *
     * @param  User  $user  L'utilisateur concerné.
     */
    public function syncGoals(User $user): void
    {
        /*
         * TOUS les objectifs, y compris ceux deja marques atteints.
         *
         * Le filtre `whereNull('completed_at')` qui etait ici rendait
         * inatteignable la moitie de `checkCompletion()` : celle qui DE-marque
         * un objectif dont le critere n'est plus rempli. Un objectif atteint le
         * restait donc definitivement, meme apres suppression de la seance ou
         * de la mesure qui l'avait declenche. La branche existait, elle etait
         * testable, et aucun chemin n'y menait (#1501).
         *
         * Le cout est borne : un pratiquant a quelques objectifs, pas quelques
         * milliers, et les metriques sont deja pre-calculees en lot juste
         * dessous.
         */
        $goals = $user->goals()->get();

        // Les métriques sont calculées en un lot pour tous les objectifs : sans
        // cela, chacun repartait chercher les siennes, une requête par objectif.
        $metrics = $this->preCalculateMetrics($user, $goals);

        foreach ($goals as $goal) {
            $goal->setRelation('user', $user);
            $this->updateGoalProgress($goal, $metrics);
        }

        $dirtyGoals = $goals->filter->isDirty();
        if ($dirtyGoals->isNotEmpty()) {
            $now = now();
            $data = $dirtyGoals->map(function ($goal) use ($now) {
                $attrs = $goal->getAttributes();
                $attrs['updated_at'] = $now;

                return $attrs;
            })->toArray();

            /*
             * `updated_at` n'est pas dans la liste : Eloquent l'y ajoute de
             * lui-meme (`addUpdatedAtToUpsertColumns`) des lors que le modele
             * porte des horodatages. L'y citer ne changeait rien — verifie en
             * retirant la colonne et en constatant que la date monte quand meme.
             */
            Goal::upsert(
                $data,
                ['id'],
                ['current_value', 'progress_pct', 'completed_at']
            );
        }
    }

    /**
     * Recalcule un objectif : sa valeur courante, puis son état et sa barre.
     *
     * @param  Goal  $goal  L'objectif à revoir.
     * @param  array{workouts_count?: int, max_weights?: array<int, float>, max_volumes?: array<int, float>, latest_measurement?: \App\Models\BodyMeasurement|null}  $metrics  Métriques déjà calculées en lot ; sinon chaque objectif refait ses requêtes.
     */
    public function updateGoalProgress(Goal $goal, array $metrics = []): void
    {
        match ($goal->type) {
            GoalType::Weight => $this->updateWeightGoal($goal, $metrics),
            GoalType::Frequency => $this->updateFrequencyGoal($goal, $metrics),
            GoalType::Volume => $this->updateVolumeGoal($goal, $metrics),
            GoalType::Measurement => $this->updateMeasurementGoal($goal, $metrics),
        };

        $this->checkCompletion($goal);
        $this->updateProgressPercentage($goal);
    }

    protected function updateProgressPercentage(Goal $goal): void
    {
        if ($goal->target_value === $goal->start_value) {
            $goal->progress_pct = $goal->current_value >= $goal->target_value ? 100.0 : 0.0;

            return;
        }

        /*
         * Le chemin parcouru se compte DANS LE SENS de la cible.
         *
         * Il etait compte en valeur absolue des deux cotes, ce qui rendait le
         * calcul aveugle au sens : sur une perte de poids — je pese 80, je vise
         * 75 — monter a 85 donnait `|85-80| / |75-80|`, soit 100 %. La barre
         * annoncait le but atteint pendant que `isGoalCriteriaMet()` repondait,
         * a raison, « non atteint ». Mesure, puis corrige (#1501).
         *
         * Le sens se lit sur la cible et le depart, exactement comme dans
         * `isGoalCriteriaMet()` : une cible sous le depart est une cible qu'on
         * atteint en descendant.
         */
        $descendant = $goal->target_value < $goal->start_value;

        $parcouru = $descendant
            ? $goal->start_value - $goal->current_value
            : $goal->current_value - $goal->start_value;

        $aParcourir = abs($goal->target_value - $goal->start_value);

        /*
         * Le plancher a zero n'est plus decoratif, contrairement au `max()` qui
         * avait ete retire ici : s'eloigner du depart rend desormais un chemin
         * NEGATIF, et c'est precisement le cas qu'on veut afficher a 0 %.
         *
         * La division reste sans garde : la seule facon d'obtenir zero au
         * denominateur est `target === start`, et la methode a deja retourne
         * dans ce cas.
         */
        $goal->progress_pct = max(0.0, min($parcouru / $aParcourir * 100, 100.0));
    }

    /**
     * L'avancement d'un objectif de charge : le poids maximal sur l'exercice.
     *
     * @param  Goal  $goal  L'objectif à revoir.
     * @param  array{workouts_count?: int, max_weights?: array<int, float>, max_volumes?: array<int, float>, latest_measurement?: \App\Models\BodyMeasurement|null}  $metrics  Métriques déjà calculées en lot.
     */
    protected function updateWeightGoal(Goal $goal, array $metrics = []): void
    {
        if ($goal->exercise_id === null) {
            return;
        }

        if (isset($metrics['max_weights'][$goal->exercise_id])) {
            // Pas de repli : `preCalculateMaxWeights` declare un retour `float`
            // natif sur chaque valeur, donc `is_numeric()` y etait toujours vrai
            // et le `0.0` inatteignable — d'ou trois mutants qu'aucun test ne
            // pouvait tuer.
            $goal->current_value = $metrics['max_weights'][$goal->exercise_id];

            return;
        }

        // Meme source que le chemin groupe : le record, tenu par
        // `PersonalRecordService`, et non une seconde derivation.
        $maxWeight = \Illuminate\Support\Facades\DB::table('personal_records')
            ->where('user_id', $goal->user_id)
            ->where('exercise_id', $goal->exercise_id)
            ->where('type', 'max_weight')
            ->value('value');

        if ($maxWeight !== null && is_numeric($maxWeight)) {
            $goal->current_value = (float) $maxWeight;
        }
    }

    /**
     * L'avancement d'un objectif de fréquence : le nombre de séances.
     *
     * @param  Goal  $goal  L'objectif à revoir.
     * @param  array{workouts_count?: int, max_weights?: array<int, float>, max_volumes?: array<int, float>, latest_measurement?: \App\Models\BodyMeasurement|null}  $metrics  Métriques déjà calculées en lot.
     */
    protected function updateFrequencyGoal(Goal $goal, array $metrics = []): void
    {
        if (isset($metrics['workouts_count']) && is_int($metrics['workouts_count'])) {
            $goal->current_value = $metrics['workouts_count'];

            return;
        }

        // Le compte est retenu sur le modèle : plusieurs objectifs de fréquence
        // posent la même question, elle n'est posée qu'une fois.
        /** @phpstan-ignore assign.propertyReadOnly */
        $goal->user->workouts_count ??= $goal->user->workouts()->count();

        $goal->current_value = $goal->user->workouts_count;
    }

    /**
     * L'avancement d'un objectif de volume : le meilleur volume (poids × reps)
     * atteint sur l'exercice au cours d'une seule séance.
     *
     * @param  Goal  $goal  L'objectif à revoir.
     * @param  array{workouts_count?: int, max_weights?: array<int, float>, max_volumes?: array<int, float>, latest_measurement?: \App\Models\BodyMeasurement|null}  $metrics  Métriques déjà calculées en lot.
     */
    protected function updateVolumeGoal(Goal $goal, array $metrics = []): void
    {
        if ($goal->exercise_id === null) {
            return;
        }

        if (isset($metrics['max_volumes'][$goal->exercise_id])) {
            // Meme raison qu'au-dessus : `preCalculateMaxVolumes` garantit le
            // float, le repli ne pouvait pas s'executer.
            $goal->current_value = $metrics['max_volumes'][$goal->exercise_id];

            return;
        }

        // Le maximum est calculé en SQL : sur un long historique, ramener toutes
        // les séances en mémoire pour les additionner ne passe pas à l'échelle.
        // Memes regles que le chemin groupe, sinon les deux divergent.
        $maxVolume = \Illuminate\Support\Facades\DB::table('workout_lines')
            ->join('sets', 'workout_lines.id', '=', 'sets.workout_line_id')
            ->where('workout_lines.user_id', $goal->user_id)
            ->where('workout_lines.exercise_id', $goal->exercise_id)
            ->where('sets.is_completed', true)
            ->selectRaw('SUM(sets.weight * sets.reps) as total_volume')
            ->groupBy('workout_lines.workout_id')
            ->orderByDesc('total_volume')
            ->value('total_volume');

        if ($maxVolume !== null && is_numeric($maxVolume)) {
            $goal->current_value = (float) $maxVolume;
        }
    }

    /**
     * L'avancement d'un objectif de mensuration : la dernière valeur relevée.
     *
     * @param  Goal  $goal  L'objectif à revoir.
     * @param  array{workouts_count?: int, max_weights?: array<int, float>, max_volumes?: array<int, float>, latest_measurement?: \App\Models\BodyMeasurement|null}  $metrics  Métriques déjà calculées en lot.
     */
    protected function updateMeasurementGoal(Goal $goal, array $metrics = []): void
    {
        if ($goal->measurement_type === null || $goal->measurement_type === '') {
            return;
        }

        /*
         * Deux formes de mensuration, deux tables.
         *
         * `weight` et `body_fat` sont des COLONNES de `body_measurements`. Les
         * parties du corps sont des LIGNES de `body_part_measurements`,
         * designees par leur nom. Lire les secondes comme les premieres rendait
         * « Unknown column 'waist' » — une erreur 500 a chaque pesee (#1454).
         */
        if (! in_array($goal->measurement_type, ['weight', 'body_fat'], true)) {
            $this->releverLaPartieDuCorps($goal);

            return;
        }

        if (isset($metrics['latest_measurement']) && $metrics['latest_measurement'] instanceof \App\Models\BodyMeasurement) {
            $m = $metrics['latest_measurement'];
            $latestValue = $m->{$goal->measurement_type === 'weight' ? 'weight' : $goal->measurement_type};
        } else {
            $latestValue = $goal->user->bodyMeasurements()
                ->latest('measured_at')
                ->value($goal->measurement_type === 'weight' ? 'weight' : $goal->measurement_type);
        }

        if ($latestValue !== null && is_numeric($latestValue)) {
            $goal->current_value = (float) $latestValue;
        }
    }

    /**
     * La derniere mesure d'une partie du corps, ramenee en centimetres.
     *
     * `part` est du texte libre, mais sa collation `utf8mb4_unicode_ci` compare
     * sans egard a la casse : un objectif sur « waist » retrouve les mesures
     * saisies sous « Waist » SANS `lower()`, qui aurait ecarte l'index
     * `(user_id, part, measured_at)`. Mesure a 20 000 lignes et 400 jours
     * d'historique : une seule lecture d'index, parcourue a l'envers, sans tri.
     *
     * Une mensuration inconnue laisse l'objectif sans progression plutot que de
     * planter — c'est la verite, rien ne mesure cette valeur.
     */
    private function releverLaPartieDuCorps(Goal $goal): void
    {
        $mesure = $goal->user->bodyPartMeasurements()
            ->where('part', $goal->measurement_type)
            ->orderByDesc('measured_at')
            ->orderByDesc('id')
            ->first(['value', 'unit']);

        if (! $mesure instanceof \App\Models\BodyPartMeasurement) {
            return;
        }

        /*
         * La saisie accepte le pouce ; l'objectif est toujours en centimetres.
         * Sans cette conversion, « descendre a 80 » serait atteint par une
         * mesure de 80 pouces, soit deux metres de tour de taille.
         */
        $goal->current_value = $mesure->unit === 'in'
            ? (float) $mesure->value * 2.54
            : (float) $mesure->value;
    }

    /**
     * Marque l'objectif atteint, ou le dé-marque quand il ne l'est plus.
     *
     * Les deux sens comptent : supprimer la séance ou la mesure qui avait
     * déclenché un objectif doit le rouvrir.
     *
     * @param  Goal  $goal  L'objectif à revoir.
     */
    protected function checkCompletion(Goal $goal): void
    {
        $isCompleted = $this->isGoalCriteriaMet($goal);

        if ($isCompleted && $goal->completed_at === null) {
            $goal->completed_at = now();

            return;
        }

        if (! $isCompleted && $goal->completed_at !== null) {
            $goal->completed_at = null;
        }
    }

    protected function isGoalCriteriaMet(Goal $goal): bool
    {
        // Une cible sous le départ est une cible qu'on atteint en descendant :
        // c'est une perte de poids ou de tour de taille.
        if ($goal->type === GoalType::Measurement && $goal->target_value < $goal->start_value) {
            return $goal->current_value <= $goal->target_value && $goal->current_value > 0;
        }

        // Partout ailleurs, on monte vers la cible.
        return $goal->current_value >= $goal->target_value;
    }

    /**
     * Les métriques dont les objectifs auront besoin, calculées en un lot.
     *
     * Seuls les types réellement présents sont interrogés : un utilisateur sans
     * objectif de mensuration ne paie pas cette lecture.
     *
     * @param  User  $user  L'utilisateur concerné.
     * @param  \Illuminate\Support\Collection<int, Goal>  $goals  Les objectifs à servir.
     * @return array{workouts_count?: int, max_weights?: array<int, float>, max_volumes?: array<int, float>, latest_measurement?: \App\Models\BodyMeasurement|null}
     */
    private function preCalculateMetrics(User $user, \Illuminate\Support\Collection $goals): array
    {
        if ($goals->isEmpty()) {
            return [];
        }

        $metrics = [];
        $types = $goals->pluck('type')->unique();
        $exerciseIds = $goals->whereIn('type', [GoalType::Weight, GoalType::Volume])
            ->pluck('exercise_id')
            ->filter()
            ->unique()
            ->toArray();

        if ($types->contains(GoalType::Frequency)) {
            $metrics['workouts_count'] = $user->workouts()->count();
        }

        if ($types->contains(GoalType::Weight) && $exerciseIds !== []) {
            $metrics['max_weights'] = $this->preCalculateMaxWeights($user, $exerciseIds);
        }

        if ($types->contains(GoalType::Volume) && $exerciseIds !== []) {
            $metrics['max_volumes'] = $this->preCalculateMaxVolumes($user, $exerciseIds);
        }

        if ($types->contains(GoalType::Measurement)) {
            $metrics['latest_measurement'] = $user->bodyMeasurements()
                ->latest('measured_at')
                ->first();
        }

        return $metrics;
    }

    /**
     * Le poids maximal de chacun des exercices demandés.
     *
     * @param  array<array-key, mixed>  $exerciseIds
     * @return array<int, float>
     */
    private function preCalculateMaxWeights(User $user, array $exerciseIds): array
    {
        /*
         * Le record, pas une seconde derivation.
         *
         * Cette methode refaisait le `MAX(sets.weight)` que
         * `PersonalRecordService` tient deja — sans en reprendre les regles :
         * ni `is_warmup = 0`, ni `is_completed = 1`, ni `reps > 0`. Sur les
         * memes donnees, l'objectif annoncait 200 kg la ou le record en disait
         * 100, le 200 venant d'une serie saisie et jamais cochee.
         *
         * Deux definitions de « mon maximum » dans la meme application ne
         * peuvent que diverger. Il n'y en a plus qu'une, et elle est deja
         * surveillee par `app:verify-data-coherence`.
         *
         * `(user_id, exercise_id, type)` sert exactement cette lecture.
         */
        /** @var array<int, float> $maxWeights */
        $maxWeights = \Illuminate\Support\Facades\DB::table('personal_records')
            ->where('user_id', $user->id)
            ->whereIn('exercise_id', $exerciseIds)
            ->where('type', 'max_weight')
            ->pluck('value', 'exercise_id')
            // Un exercice sans record est ECARTE, pas ramene a zero.
            // Voir la note de `preCalculateMaxVolumes` : c'est le meme piege.
            ->filter(fn (mixed $val): bool => is_numeric($val))
            ->map(fn (mixed $val): float => (float) $val)
            ->toArray();

        return $maxWeights;
    }

    /**
     * Le meilleur volume sur une séance, pour chacun des exercices demandés.
     *
     * @param  array<array-key, mixed>  $exerciseIds
     * @return array<int, float>
     */
    private function preCalculateMaxVolumes(User $user, array $exerciseIds): array
    {
        // Le maximum se calcule en SQL, par sous-requête : sur un utilisateur à
        // long historique, ramener toutes les séries en mémoire déborde.
        /*
         * `is_completed`, comme `Workout::recomputeVolume()` depuis #1499 : le
         * volume compte ce qui a ete souleve, pas ce qui etait prevu. Sans ce
         * filtre, l'objectif de volume et le volume de la seance repondaient
         * differemment sur les memes series.
         *
         * La jointure a `workouts` disparait : `workout_lines` porte le
         * proprietaire depuis #1601, et son `workout_id` suffit a grouper.
         */
        $subQuery = \Illuminate\Support\Facades\DB::table('workout_lines')
            ->join('sets', 'workout_lines.id', '=', 'sets.workout_line_id')
            ->where('workout_lines.user_id', $user->id)
            ->whereIn('workout_lines.exercise_id', $exerciseIds)
            ->where('sets.is_completed', true)
            ->selectRaw('workout_lines.exercise_id, workout_lines.workout_id, SUM(sets.weight * sets.reps) as total_volume')
            ->groupBy('workout_lines.exercise_id', 'workout_lines.workout_id');

        /** @var array<int, float> $maxVolumes */
        $maxVolumes = \Illuminate\Support\Facades\DB::query()
            ->fromSub($subQuery, 'volumes')
            ->selectRaw('exercise_id, MAX(total_volume) as max_volume')
            ->groupBy('exercise_id')
            ->pluck('max_volume', 'exercise_id')
            /*
             * Ecarte plutot que ramene a zero, et ce n'est pas un detail.
             *
             * `sets.weight` est nullable. Un exercice dont toutes les series sont
             * sans poids — des repetitions au poids du corps, ou un poids oublie —
             * forme bien un groupe, mais son MAX vaut NULL. Le repli a 0.0 en
             * faisait une entree du tableau, donc un `isset()` vrai en aval, donc
             * un `current_value` ECRASE a zero.
             *
             * Le chemin individuel, lui, ne touchait a rien dans ce cas. Les deux
             * repondaient donc differemment sur les memes donnees : mesure faite,
             * `syncGoals` rendait 0 la ou `updateGoalProgress` gardait 50. Le
             * premier tourne a chaque enregistrement de seance, via le job ; le
             * second quand on modifie l'objectif depuis son ecran.
             *
             * En ecartant l'entree, `isset()` est faux et les deux chemins se
             * rejoignent sur le comportement du second : la valeur ne bouge pas
             * tant qu'aucun poids n'a ete souleve.
             */
            ->filter(fn (mixed $val): bool => is_numeric($val))
            ->map(fn (mixed $val): float => (float) $val)
            ->toArray();

        return $maxVolumes;
    }
}
