<?php

declare(strict_types=1);

use App\Models\User;
use App\Services\GoalService;
use App\Services\Stats\ClesDeStats;
use App\Services\StreakService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;

/**
 * Refait la série de jours des comptes qui ont une séance terminée dont la
 * seule série cochée est un échauffement (#2333).
 *
 * Une séance n'est plus faite sans une série de travail cochée
 * (`User::seancesFaites()`). La série stockée d'un compte qui en porte une
 * compterait encore son jour : elle est refaite par l'écrivain
 * (`StreakService::recalculerDepuisLesFaits()`), seul, pour ces comptes seuls,
 * et une série qui ne change pas ne s'écrit pas. Leurs objectifs se recalculent
 * par leur écrivain (`GoalService::syncGoals()`), que le contrôle nocturne
 * compare à l'avancement stocké. Les succès déjà débloqués ne sont pas
 * retirés : le service ne retire jamais un succès.
 *
 * Rejouable : un second passage ne trouve rien à écrire.
 */
return new class() extends Migration
{
    public function up(): void
    {
        $series = app(StreakService::class);
        $objectifs = app(GoalService::class);

        User::query()
            ->whereIn('id', fn (Builder $seances): Builder => $seances
                ->select('workouts.user_id')
                ->from('workouts')
                ->whereNotNull('workouts.ended_at')
                ->whereExists(fn (Builder $echauffements): Builder => $echauffements
                    ->selectRaw('1')
                    ->from('workout_lines')
                    ->join('sets', 'sets.workout_line_id', '=', 'workout_lines.id')
                    ->whereColumn('workout_lines.workout_id', 'workouts.id')
                    ->where('sets.is_completed', true)
                    ->where('sets.is_warmup', true))
                ->whereNotExists(fn (Builder $travail): Builder => $travail
                    ->selectRaw('1')
                    ->from('workout_lines')
                    ->join('sets', 'sets.workout_line_id', '=', 'workout_lines.id')
                    ->whereColumn('workout_lines.workout_id', 'workouts.id')
                    ->where('sets.is_completed', true)
                    ->where('sets.is_warmup', false)))
            ->chunkById(200, function (Collection $comptes) use ($series, $objectifs): void {
                foreach ($comptes as $compte) {
                    if ($compte instanceof User) {
                        $series->recalculerDepuisLesFaits($compte);
                        $objectifs->syncGoals($compte);
                        ClesDeStats::invaliderSeancesDuCompte((int) $compte->id);
                    }
                }
            });
    }

    /**
     * Volontairement vide : la série d'avant comptait des séances que
     * l'échauffement seul ne soutient pas, et rien ne la garde.
     */
    public function down(): void
    {
    }
};
