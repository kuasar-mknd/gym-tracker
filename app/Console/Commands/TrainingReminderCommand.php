<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\NotificationPreference;
use App\Models\User;
use App\Models\Workout;
use App\Notifications\TrainingReminder;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\JoinClause;

class TrainingReminderCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    #[\Override]
    protected $signature = 'app:remind-training';

    /**
     * The console command description.
     *
     * @var string
     */
    #[\Override]
    protected $description = 'Send the training reminder to users who chose today and have not trained yet.';

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        $this->info('Starting training reminders check...');

        $count = 0;
        $maintenant = CarbonImmutable::now();
        $jour = $maintenant->isoWeekday();
        $debutDeJournee = $maintenant->startOfDay();

        // 1. La jointure sur notification_preferences lit les données d'un coup, sans sous-requête whereHas ni chargement N+1.
        // 2. chunkById traite les comptes par lots, avec users.id à cause de la jointure.
        // 3. La relation est hydratée à la main dans la boucle, pour que notificationsPoussesActivees ne lance pas de requête N+1.
        User::query()
            ->select([
                'users.*',
                'notification_preferences.days as pref_days',
                'notification_preferences.is_push_enabled as pref_push',
            ])
            ->join('notification_preferences', function (JoinClause $join): void {
                $join->on('users.id', '=', 'notification_preferences.user_id')
                    ->where('notification_preferences.type', '=', 'training_reminder')
                    ->where('notification_preferences.is_enabled', '=', true);
            })
            // Une série de travail cochée aujourd'hui, séance terminée ou non (#2333) : le rappel se tait pendant l'entraînement.
            ->withExists(['workouts as a_fait_sa_seance' => function (Builder $seances) use ($debutDeJournee): Builder {
                /** @var Builder<Workout> $seances */
                return $seances
                    ->avecUneSerieDeTravailCochee()
                    ->where('workouts.started_at', '>=', $debutDeJournee);
            }])
            ->chunkById(100, function ($users) use (&$count, $jour): void {
                foreach ($users as $user) {
                    $joursChoisis = $this->joursChoisis($user->getAttribute('pref_days'));

                    // Relation hydratée à la main : pas de requête N+1 dans notify() -> notificationsPoussesActivees().
                    $preference = new NotificationPreference([
                        'type' => 'training_reminder',
                        'is_enabled' => true,
                        'is_push_enabled' => (bool) $user->getAttribute('pref_push'),
                        'days' => $joursChoisis,
                    ]);

                    $user->setRelation('notificationPreferences', collect([$preference]));

                    if (! in_array($jour, $joursChoisis, true)) {
                        continue;
                    }

                    if ((bool) $user->getAttribute('a_fait_sa_seance')) {
                        continue;
                    }

                    $user->notify(new TrainingReminder());
                    $count++;
                }
            }, 'users.id', 'id');

        $this->info("Sent {$count} training reminders.");
    }

    /**
     * Les jours ISO retenus par la preference ; sans choix, tous les jours.
     *
     * @return array<int, int>
     */
    private function joursChoisis(mixed $brut): array
    {
        $jours = is_string($brut) ? json_decode($brut, true) : $brut;

        if (! is_array($jours) || $jours === []) {
            return range(1, 7);
        }

        return array_values(array_map(static fn (mixed $jour): int => is_numeric($jour) ? (int) $jour : 0, $jours));
    }
}
