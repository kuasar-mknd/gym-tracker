<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\GoalPeriod;
use App\Enums\GoalType;
use App\Jobs\SyncUserGoals;
use App\Models\Goal;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * Relance, chaque lundi, le recalcul des comptes dont un objectif « par
 * semaine » garde l'avancement ou la date d'atteinte de la semaine passée.
 *
 * Le recalcul des objectifs ne part que d'une activité (séance, série,
 * mesure). Sans cette passe, un objectif hebdomadaire atteint le dimanche
 * restait « complété », barre pleine, jusqu'à la prochaine séance, et le
 * contrôle nocturne de cohérence signalait chacun d'eux. Un objectif sans
 * avancement ni date d'atteinte n'a rien à rouvrir : son compte n'est pas
 * relancé.
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
        $comptes = Goal::query()
            ->where('type', GoalType::Frequency->value)
            ->where('period', GoalPeriod::Week->value)
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
