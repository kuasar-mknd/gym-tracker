<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use App\Models\Workout;
use Illuminate\Support\Carbon;

/**
 * Service responsible for calculating and updating user workout streaks.
 *
 * This service determines the user's current consecutive workout streak,
 * tracks their longest historical streak, and updates the `last_workout_at`
 * timestamp. It handles both manual workout entries and historical data updates.
 */
final class StreakService
{
    /**
     * Update user streak based on the latest workout.
     *
     * Resolves the effective workout date, compares it to the last recorded
     * workout date, and calculates the new streak. If the streak is broken,
     * it resets to 1. If consecutive, it increments. It also updates the
     * user's `last_workout_at` timestamp.
     *
     * @param  User  $user  The user whose streak is being updated.
     * @param  Workout|null  $workout  The newly completed or updated workout (optional).
     */
    public function updateStreak(User $user, ?Workout $workout = null): void
    {
        $workoutDate = $this->resolveWorkoutDate($user, $workout);

        if ($workoutDate === null) {
            return;
        }

        $lastRecordedDate = $this->getLastRecordedDate($user);

        if ($lastRecordedDate !== null && $lastRecordedDate->equalTo($workoutDate)) {
            if ($workout !== null) {
                $this->rememberIfMoreRecent($user, $workout->started_at);

                // `save()` sur un modele inchange n'emet aucune requete : Laravel
                // s'arrete des que `getDirty()` est vide. Un garde ici ne ferait
                // que dupliquer ce test, sans rien ajouter d'observable. Verifie
                // par le test qui compte les ecritures.
                $user->save();
            }

            return;
        }

        $this->calculateNewStreak($user, $workoutDate, $lastRecordedDate);

        if ($workout !== null) {
            // La seance fournie porte sa date : inutile de la rechercher.
            $this->rememberIfMoreRecent($user, $workout->started_at);
        } else {
            /*
             * Sans seance fournie, la verite est en base, et on la reprend telle
             * quelle — c'est un recalcul, pas un enregistrement, donc la regle du
             * « ne jamais reculer » ne s'applique pas ici.
             *
             * `value()` sans `toBase()` laisse Eloquent caster la colonne, donc
             * Carbon, ou null quand l'utilisateur n'a aucune seance. Le repli
             * `is_scalar()` + `Carbon::parse()` qui etait ici ne pouvait pas
             * s'executer, et ses trois mutants survivaient pour cette raison :
             * forcer l'`instanceof` a faux menait a `is_scalar(Carbon)`, donc a
             * null, mais aucun test ne relisait `last_workout_at` ensuite.
             */

            /** @var Carbon|null $latestStartedAt */
            $latestStartedAt = $user->workouts()
                ->latest('started_at')
                ->value('started_at');

            $user->last_workout_at = $latestStartedAt;
        }

        $user->save();
    }

    /**
     * Retient la date de la seance si elle est plus recente que celle deja connue.
     *
     * `last_workout_at` ne recule pas. La branche « meme jour » appliquait deja
     * cette regle, mais la branche « autre jour » ecrasait sans condition : une
     * seance vieille de trois jours, saisie apres coup, remplacait la date de la
     * plus recente. Et cette date est la seule memoire du service — une fois
     * reculee, l'ecart calcule a la seance suivante part de la mauvaise date, et
     * une serie pourtant continue se retrouve cassee.
     */
    private function rememberIfMoreRecent(User $user, Carbon $startedAt): void
    {
        if ($user->last_workout_at !== null && ! $startedAt->greaterThan($user->last_workout_at)) {
            return;
        }

        $user->last_workout_at = $startedAt;
    }

    /**
     * Le jour de la derniere seance enregistree, ou null si l'utilisateur n'en a
     * jamais eu.
     *
     * `last_workout_at` est cast en datetime dans le modele : Carbon ou null,
     * jamais une chaine. Le repli `Carbon::parse((string) $lastWorkoutAt)` qui
     * etait la ne pouvait donc pas s'executer — et c'est ce qui faisait survivre
     * son mutant : retirer le retour anticipe donnait exactement le meme
     * resultat, puisque reparser la representation textuelle d'un Carbon rend le
     * meme jour.
     */
    protected function getLastRecordedDate(User $user): ?Carbon
    {
        return $user->last_workout_at?->copy()->startOfDay();
    }

    /**
     * Calculate and update the user's current and longest streak values.
     *
     * Compares the new workout date against the last recorded date to determine
     * if the streak should increment (consecutive days), reset to 1 (broken streak),
     * or remain the same (same day). It also updates the `longest_streak` if
     * the new current streak exceeds it.
     *
     * @param  User  $user  The user whose streak is being calculated.
     * @param  Carbon  $workoutDate  The resolved start-of-day date of the current workout.
     * @param  Carbon|null  $lastRecordedDate  The start-of-day date of the previously recorded workout.
     */
    protected function calculateNewStreak(User $user, Carbon $workoutDate, ?Carbon $lastRecordedDate): void
    {
        if ($lastRecordedDate === null) {
            // Toute premiere seance.
            $user->current_streak = 1;
        } else {
            /*
             * L'ecart est signe, et c'est ce qui compte : une seance anterieure a
             * la derniere enregistree donne un ecart negatif et ne touche a rien.
             * C'est le cas d'une seance ajoutee apres coup, qui ne doit ni
             * prolonger ni casser la serie en cours. Passer `true` ici rendrait
             * l'ecart absolu et une seance vieille de trois jours remettrait la
             * serie a 1.
             */
            $diffInDays = (int) $lastRecordedDate->diffInDays($workoutDate, false);

            $isNextDay = $diffInDays === 1;

            /*
             * Un ecart de 0 n'arrive jamais ici : les deux dates sont ramenees au
             * debut du jour, et l'egalite est traitee plus haut dans
             * `updateStreak`. `> 0` et `>= 1` decrivent donc exactement la meme
             * chose que `> 1`, ce qui rend leurs mutants equivalents plutot que
             * non couverts — un test qui pretendrait les tuer verifierait autre
             * chose.
             *
             * @pest-mutate-ignore
             */
            $isBrokenStreak = $diffInDays > 1;

            if ($isNextDay) {
                $user->current_streak++;
            } elseif ($isBrokenStreak) {
                $user->current_streak = 1;
            }
        }

        // Update longest streak if necessary
        if ($user->current_streak > $user->longest_streak) {
            $user->longest_streak = $user->current_streak;
        }
    }

    /**
     * Le jour de la seance a traiter, ramene au debut du jour pour que la
     * comparaison porte sur des dates et non sur des heures.
     *
     * La seance fournie porte sa date, ce qui evite une requete ; sans elle, on
     * cherche la plus recente. `workouts.started_at` est NOT NULL et cast en
     * datetime, donc toujours un Carbon : le repli `is_scalar()` qui etait ici ne
     * pouvait pas s'executer. Le null vient d'ailleurs — d'un utilisateur sans
     * aucune seance, ou `value()` ne rend rien.
     */
    private function resolveWorkoutDate(User $user, ?Workout $workout): ?Carbon
    {
        /*
         * `??` suffit a couvrir la seance absente : la lecture d'une propriete a
         * gauche de `??` suit la semantique d'`isset`, donc un `$workout` a null
         * rend null au lieu de lever une erreur. Un `?->` serait redondant, et
         * PHPStan le signale comme tel.
         */

        /** @var Carbon|null $startedAt */
        $startedAt = $workout->started_at ?? $user->workouts()->latest('started_at')->value('started_at');

        return $startedAt?->copy()->startOfDay();
    }
}
