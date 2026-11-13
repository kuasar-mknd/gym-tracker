<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\GoalPeriod;
use App\Enums\GoalType;
use App\Jobs\SyncUserGoals;
use App\Models\Goal;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Relance, chaque jour, le recalcul des comptes dont un objectif « par
 * semaine » garde l'avancement ou la date d'atteinte de la semaine passée.
 *
 * Le recalcul des objectifs ne part que d'une activité (séance, série,
 * mesure). Sans cette passe, un objectif hebdomadaire atteint le dimanche
 * restait « complété », barre pleine, jusqu'à la prochaine séance, et le
 * contrôle nocturne de cohérence signalait chacun d'eux. Un objectif sans
 * avancement ni date d'atteinte, ou déjà recalculé depuis le lundi, n'a rien
 * à rouvrir : son compte n'est pas relancé. Passée chaque jour, la commande
 * rattrape un lundi manqué.
 *
 * La commande ne recalcule rien elle-même : elle confie le compte au travail
 * qui en a la charge, `SyncUserGoals`, un seul à la fois par compte.
 */
class RenouvelerLesObjectifsHebdomadaires extends Command
{
    /**
     * @var string
     */
    #[\Override]
    protected $signature = 'objectifs:renouveler-la-semaine';

    /**
     * @var string
     */
    #[\Override]
    protected $description = 'Relance le recalcul des comptes dont un objectif par semaine garde la semaine passée';

    public function handle(): int
    {
        // Une seule lecture de l'horloge : le lundi de cette semaine.
        $lundi = now()->startOfWeek(Carbon::MONDAY);

        /*
         * Les objectifs par semaine qui gardent une valeur ou une date
         * d'atteinte et dont la ligne n'a pas été écrite depuis le lundi : un
         * recalcul qui change une valeur avance `updated_at`, un recalcul qui
         * n'en change aucune ne l'écrit pas, et l'objectif est alors relancé
         * chaque jour, sans effet, jusqu'à la semaine suivante. La période se compare
         * octet par octet, comme le cast qui la relit (`.ai/rules/database.md`).
         */
        $comptes = Goal::query()
            ->whereRaw('cast(type as binary) = ?', [GoalType::Frequency->value])
            ->whereRaw('cast(period as binary) = ?', [GoalPeriod::Week->value])
            ->where('updated_at', '<', $lundi)
            ->where(static function (\Illuminate\Database\Eloquent\Builder $gardeLaSemaine): void {
                $gardeLaSemaine->where('current_value', '>', 0)->orWhereNotNull('completed_at');
            })
            ->distinct()
            ->pluck('user_id');

        User::query()->whereIn('id', $comptes)->each(static function (User $compte): void {
            SyncUserGoals::dispatch($compte);
        });

        $this->info("{$comptes->count()} compte(s) relancé(s).");

        return self::SUCCESS;
    }
}
