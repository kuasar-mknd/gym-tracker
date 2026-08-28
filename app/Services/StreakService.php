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
    /**
     * La serie en cours, telle qu'elle doit etre AFFICHEE.
     *
     * `users.current_streak` est un compteur incremental : il n'est touche que
     * lorsqu'une seance est enregistree. Passe un jour sans rien faire, il
     * continue d'annoncer la valeur d'hier — la peremption n'est visible qu'a
     * la lecture.
     *
     * `HandleInertiaRequests` faisait deja cette correction, en ligne ;
     * `UserResource` rendait la valeur brute. Le meme utilisateur avait donc
     * deux series differentes selon que la page etait rendue par Inertia ou lue
     * par l'API — et c'est le meme client qui emprunte les deux. Une seule
     * definition, appelee par les deux.
     *
     * Premier pas de la trajectoire decrite dans la note de modelisation :
     * la grandeur derivee cesse d'etre lue directement, sans que la colonne
     * disparaisse encore.
     */
    public function currentStreakFor(User $user): int
    {
        if ($user->last_workout_at === null) {
            return 0;
        }

        $joursDepuis = $user->last_workout_at->startOfDay()->diffInDays(now()->startOfDay());

        return $joursDepuis > 1 ? 0 : $user->current_streak;
    }

    /**
     * Reconstruit la serie depuis les seances qui existent.
     *
     * `updateStreak()` est incremental : il ne sait qu'avancer d'un cran a
     * partir de `last_workout_at`. Il n'existait donc AUCUN chemin qui refasse
     * le calcul depuis les faits — et supprimer une seance ne recalculait rien.
     * `last_workout_at` continuait de pointer une seance disparue, ce qui est
     * la seule memoire du service : l'ecart calcule a la seance suivante
     * partait d'une date fantome, et une serie pourtant continue se retrouvait
     * cassee. `longest_streak`, lui, n'etait jamais revu a la baisse. C'est
     * #1460.
     *
     * Le calcul part des dates de calendrier distinctes, du plus recent au plus
     * ancien : la serie en cours est la suite consecutive qui se termine a la
     * derniere seance, la plus longue est la plus longue suite trouvee.
     *
     * Le tri se fait en PHP plutot qu'en SQL : `DATE(started_at)` s'ecrit
     * differemment selon le pilote — `AchievementService` porte deja cette
     * double ecriture — et une seance par jour reste un volume qu'on ordonne
     * sans y penser.
     */
    public function recalculerDepuisLesFaits(User $user): void
    {
        /*
         * Une seule lecture, sans hydratation et sans fonction sur la colonne.
         *
         * La version d'avant construisait 400 modeles Eloquent pour n'en tirer
         * que des dates. Un `DISTINCT DATE(started_at)` les aurait evites mais
         * aurait impose une table temporaire et un tri : la fonction empeche
         * l'index de rendre les lignes deja ordonnees. En lisant la colonne nue,
         * `workouts(user_id, started_at)` sert le filtre ET l'ordre, et le
         * dedoublonnage se fait sur des chaines deja triees.
         */
        /** @var list<string> $horodatages */
        $horodatages = $user->workouts()
            ->toBase()
            ->orderByDesc('started_at')
            ->pluck('started_at')
            ->all();

        $derniere = $horodatages[0] ?? null;

        if ($derniere === null) {
            $user->forceFill([
                'last_workout_at' => null,
                'current_streak' => 0,
                'longest_streak' => 0,
            ])->save();

            return;
        }

        $enCours = 1;
        $plusLongue = 1;
        $suite = 1;

        /*
         * Une rupture rencontree en remontant le temps ferme la serie EN COURS,
         * definitivement. Ce que la boucle continue de compter apres, ce sont
         * des series passees, qui n'interessent plus que `$plusLongue`.
         *
         * Le garde ecrit ici etait `$consecutives && $enCours === $suite - 1`,
         * cense dire « aucune rupture depuis la derniere seance ». Il ne le
         * disait pas : par recurrence, `$enCours` vaut TOUJOURS `$suite - 1` a
         * l'entree d'une paire consecutive, y compris apres une rupture ou le
         * compteur est reparti de 1. Une vieille serie de trois jours faisait
         * donc passer pour trois une derniere seance isolee.
         */
        $rupture = false;
        $veille = null;

        foreach ($horodatages as $horodatage) {
            $jour = mb_substr($horodatage, 0, 10);

            // Deux seances le meme jour ne comptent qu'une fois. Les
            // horodatages arrivant tries, le doublon est toujours adjacent.
            if ($jour === $veille) {
                continue;
            }

            if ($veille !== null) {
                if (Carbon::parse($jour)->addDay()->toDateString() === $veille) {
                    $suite++;
                } else {
                    $suite = 1;
                    $rupture = true;
                }

                $plusLongue = max($plusLongue, $suite);

                if (! $rupture) {
                    $enCours = $suite;
                }
            }

            $veille = $jour;
        }

        $user->forceFill([
            'last_workout_at' => $derniere,
            'current_streak' => $enCours,
            'longest_streak' => $plusLongue,
        ])->save();
    }

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

        /*
         * Une seance ANTERIEURE a la derniere connue ne se traite pas d'un cran.
         *
         * Le chemin incremental ne sait qu'avancer depuis `last_workout_at` :
         * il ne peut pas voir qu'une seance saisie apres coup vient de combler
         * un trou. Trois jours d'affilee remplis retroactivement laissaient donc
         * `longest_streak` a 1, pendant que le moteur de succes, qui repart des
         * faits, en comptait bien trois — l'application affichait « record :
         * 1 jour » a qui portait deja le trophee des trois jours.
         *
         * Reconstruire depuis les faits est la seule reponse juste : une seance
         * ajoutee au milieu peut allonger une suite comme en souder deux.
         */
        if ($lastRecordedDate !== null && $workoutDate->lessThan($lastRecordedDate)) {
            $this->recalculerDepuisLesFaits($user);

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
             * L'ecart ne peut plus etre negatif ici : l'egalite sort plus haut,
             * et une seance ANTERIEURE part desormais reconstruire depuis les
             * faits, sans passer par cette methode. `false` reste, parce qu'un
             * ecart signe dit ce qu'on veut dire, mais il ne porte plus seul la
             * distinction entre « seance oubliee » et « interruption ».
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

        /*
         * `max()` plutot qu'une comparaison : a egalite, l'ancien `if` reassignait
         * la meme valeur, si bien que le mutant `>=` ne changeait rien
         * d'observable et survivait sans qu'aucun test ne puisse le tuer. Ecrit
         * ainsi, il n'y a plus de comparaison a muter, et l'intention se lit
         * mieux — le record est le maximum des deux, pas une branche.
         */
        $user->longest_streak = max($user->longest_streak, $user->current_streak);
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
