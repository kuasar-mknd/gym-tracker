<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\NotificationPreference;
use App\Models\User;
use App\Notifications\TrainingReminder;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
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

        // 1. Join notification_preferences to fetch data directly, avoiding whereHas subqueries and eager load N+1 memory issues.
        // 2. Use chunkById to process users in batches (memory efficient), specifying users.id due to the join.
        // 3. Manually hydrate the relation in the loop to prevent isPushEnabled from triggering an N+1 query.
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
            ->addSelect(['last_workout_started_at' => \App\Models\Workout::select('started_at')
                ->whereColumn('user_id', 'users.id')
                ->orderByDesc('started_at')
                ->limit(1),
            ])
            ->chunkById(100, function ($users) use (&$count, $jour, $debutDeJournee): void {
                foreach ($users as $user) {
                    $joursChoisis = $this->joursChoisis($user->getAttribute('pref_days'));

                    // Manually hydrate the relation to prevent N+1 in notify() -> isPushEnabled()
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

                    // Le garde de nullite reste vivant, lui : un compte sans
                    // aucune seance n'a pas de date. C'est `strtotime()` qui part,
                    // avec le faux qu'il ne pouvait pas rendre sur une date valide.
                    $lastWorkoutStartedAtStr = $user->getAttribute('last_workout_started_at');
                    $derniereSeance = is_string($lastWorkoutStartedAtStr)
                        ? CarbonImmutable::parse($lastWorkoutStartedAtStr)
                        : null;

                    if ($derniereSeance !== null && $derniereSeance->greaterThanOrEqualTo($debutDeJournee)) {
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
