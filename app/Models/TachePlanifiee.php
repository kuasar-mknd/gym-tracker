<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonInterface;
use Cron\CronExpression;
use Illuminate\Support\Facades\Date;
use Lorisleiva\CronTranslator\CronTranslator;
use Spatie\ScheduleMonitor\Models\MonitoredScheduledTask;
use Throwable;

/**
 * Une tâche planifiée telle que le moniteur la connaît, avec ce que le
 * panneau lui demande en plus : son expression en français et son état.
 *
 * L'état reprend la règle du paquet (`Task::lastRunFinishedTooLate()`) sur
 * la ligne seule : la prochaine exécution attendue après la dernière fin,
 * plus la marge, doit être à venir. Le paquet la calcule en relisant le
 * planning et la base pour chaque tâche ; ici, huit lignes ne coûtent
 * aucune requête.
 */
final class TachePlanifiee extends MonitoredScheduledTask
{
    #[\Override]
    protected $table = 'monitored_scheduled_tasks';

    public const string A_L_HEURE = "à l'heure";

    public const string EN_RETARD = 'en retard';

    public const string ECHOUEE = 'échouée';

    public function expressionLisible(): string
    {
        try {
            return CronTranslator::translate($this->cron_expression, 'fr', timeFormat24hours: true);
        } catch (Throwable) {
            return $this->cron_expression;
        }
    }

    public function etat(): string
    {
        if ($this->derniereExecutionEchouee()) {
            return self::ECHOUEE;
        }

        return $this->derniereExecutionEnRetard() ? self::EN_RETARD : self::A_L_HEURE;
    }

    /** Comme `Task::lastRunFailed()` : un échec postérieur au dernier départ. */
    private function derniereExecutionEchouee(): bool
    {
        if (! $this->last_failed_at instanceof CarbonInterface) {
            return false;
        }

        return ! $this->last_started_at instanceof CarbonInterface
            || $this->last_failed_at->isAfter($this->last_started_at->copy()->subSecond());
    }

    private function derniereExecutionEnRetard(): bool
    {
        $derniereFin = $this->last_finished_at instanceof CarbonInterface
            ? $this->last_finished_at
            : $this->created_at?->subSecond() ?? Date::now();

        $expression = new CronExpression($this->cron_expression);
        $prochaineAttendue = Date::instance(
            $expression->getNextRunDate($derniereFin, 0, false, $this->timezone ?? config()->string('app.timezone')),
        );

        return $prochaineAttendue->addMinutes($this->grace_time_in_minutes)->isPast();
    }
}
