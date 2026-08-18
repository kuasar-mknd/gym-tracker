<?php

declare(strict_types=1);

namespace App\Actions\Calendar;

use App\Models\DailyJournal;
use App\Models\User;
use App\Models\Workout;
use Illuminate\Support\Carbon;

final class FetchCalendarEventsAction
{
    /**
     * Fetch calendar events (workouts and journals) for a given user and month.
     *
     * @param  User  $user  The user to fetch events for.
     * @param  int  $year  The year of the events.
     * @param  int  $month  The month of the events.
     * @return array{
     *     workouts: \Illuminate\Support\Collection<int, array{id: int, name: string, date: non-falsy-string, started_at: string, exercises_count: int<0, max>, preview_exercises: array<int, string>}>,
     *     journals: \Illuminate\Support\Collection<int, array{id: int, date: non-falsy-string, mood_score: int|null, has_note: bool}>
     * }
     */
    public function execute(User $user, int $year, int $month): array
    {
        $date = Carbon::createFromDate($year, $month, 1);
        $startOfMonth = $date->copy()->startOfMonth();
        $endOfMonth = $date->copy()->endOfMonth();

        return [
            'workouts' => $this->getWorkouts($user, $startOfMonth, $endOfMonth),
            'journals' => $this->getJournals($user, $startOfMonth, $endOfMonth),
        ];
    }

    /**
     * @param  array<int, mixed>  $workoutIds
     * @return array<int, array<int, string>>
     */
    protected function getWorkoutPreviews(array $workoutIds): array
    {
        /** @var array<int, array<int, string>> */
        return \Illuminate\Support\Facades\DB::table('workout_lines')
            ->join('exercises', 'workout_lines.exercise_id', '=', 'exercises.id')
            ->whereIn('workout_lines.workout_id', $workoutIds)
            ->select('workout_lines.workout_id', 'exercises.name')
            ->orderBy('workout_lines.workout_id')
            ->orderBy('workout_lines.order')
            ->get()
            ->groupBy('workout_id')
            ->map(fn (\Illuminate\Support\Collection $lines) => $lines->take(3)->pluck('name')->toArray())
            ->toArray();
    }

    /*
     * `non-falsy-string` et `int<0, max>` ne sont pas de la coquetterie : une
     * `Collection` est invariante dans son type de valeur, donc ce que `format()`
     * et `withCount()` produisent n'est PAS accepte la ou un `string` et un `int`
     * sont declares. Accorder la signature au reel evite d'elargir le contrat
     * pour faire taire l'analyse — et dit au passage ce qui est vrai : la date
     * n'est jamais vide, le compte jamais negatif.
     */
    /** @return \Illuminate\Support\Collection<int, array{id: int, name: string, date: non-falsy-string, started_at: string, exercises_count: int<0, max>, preview_exercises: array<int, string>}> */
    private function getWorkouts(User $user, Carbon $start, Carbon $end): \Illuminate\Support\Collection
    {
        /*
         * Sans `toBase()`, et le meme raisonnement qu'en #1474 : la requete est
         * bornee par le mois affiche, donc l'hydratation porte sur quelques
         * dizaines de lignes. Ce qu'elle economisait etait invisible ; ce qu'elle
         * coutait ne l'etait pas — dix entrees de baseline PHPStan, parce que
         * chaque colonne revenait non typee et devait etre castee a la main.
         *
         * `withCount()` remplace la sous-requete, et Eloquent caste `started_at`
         * en Carbon : il ne reste ni cast, ni analyse de date a la main.
         */
        $workouts = Workout::query()
            ->select(['id', 'name', 'started_at'])
            ->withCount('workoutLines')
            ->where('user_id', $user->id)
            ->whereBetween('started_at', [$start, $end])
            ->get();

        /*
         * Pas de sortie anticipee sur une collection vide : `getWorkoutPreviews([])`
         * ne trouve rien et la projection ci-dessous rend une collection vide de
         * toute facon. La branche n'economisait rien et coutait un type — un
         * `collect()` nu ne porte pas la forme que cette methode declare rendre.
         */
        $previews = $this->getWorkoutPreviews($workouts->modelKeys());

        /*
         * La forme est declaree ici, la ou elle est produite : `Collection` est
         * invariante dans son type de valeur, donc un `non-falsy-string` rendu
         * par `format()` n'est PAS accepte la ou la methode declare un `string`.
         * L'annoter au point de production accorde les deux sans elargir le
         * contrat.
         */
        return $workouts->map(
            fn (Workout $workout): array => [
                'id' => $workout->id,
                'name' => $workout->name ?? 'Séance',
                'date' => $workout->started_at->format('Y-m-d'),
                'started_at' => $workout->started_at->toIso8601String(),
                'exercises_count' => $workout->workout_lines_count ?? 0,
                'preview_exercises' => $previews[$workout->id] ?? [],
            ]
        );
    }

    /** @return \Illuminate\Support\Collection<int, array{id: int, date: non-falsy-string, mood_score: int|null, has_note: bool}> */
    private function getJournals(User $user, Carbon $start, Carbon $end): \Illuminate\Support\Collection
    {
        // ⚡ Bolt: PERFORMANCE OPTIMIZATION
        // Use toBase() to avoid hydrating Eloquent models and Carbon objects.
        // This significantly reduces memory usage and execution time for large datasets.
        // Meme raison qu'au-dessus : la requete est bornee par le mois affiche,
        // et `toBase()` obligeait a caster chaque colonne a la main — dont une
        // date, avec un decoupage de chaine conditionnel selon ce que le pilote
        // avait bien voulu rendre.
        return DailyJournal::query()
            ->select(['id', 'date', 'mood_score', 'content'])
            ->where('user_id', $user->id)
            ->whereBetween('date', [$start, $end])
            ->get()
            ->map(
                fn (DailyJournal $journal): array => [
                    'id' => $journal->id,
                    'date' => $journal->date->format('Y-m-d'),
                    'mood_score' => $journal->mood_score,
                    'has_note' => $journal->content !== null && $journal->content !== '',
                ]
            );
    }
}
