<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\NotificationPreference;
use App\Models\User;
use App\Notifications\TrainingReminder;
use Carbon\Carbon;
use Illuminate\Console\Command;

class TrainingReminderCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:remind-training';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Send training reminders to users based on their custom inactivity threshold.';

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        $this->info('Starting training reminders check...');

        $count = 0;
        $now = time();

        // 1. Join notification_preferences to fetch data directly, avoiding whereHas subqueries and eager load N+1 memory issues.
        // 2. Use chunkById to process users in batches (memory efficient), specifying users.id due to the join.
        // 3. Manually hydrate the relation in the loop to prevent isPushEnabled from triggering an N+1 query.
        User::query()
            ->select([
                'users.*',
                'notification_preferences.value as pref_value',
                'notification_preferences.is_push_enabled as pref_push',
            ])
            ->join('notification_preferences', function ($join): void {
                $join->on('users.id', '=', 'notification_preferences.user_id')
                    ->where('notification_preferences.type', '=', 'training_reminder')
                    ->where('notification_preferences.is_enabled', '=', true);
            })
            ->addSelect(['last_workout_started_at' => \App\Models\Workout::select('started_at')
                ->whereColumn('user_id', 'users.id')
                ->orderByDesc('started_at')
                ->limit(1),
            ])
            ->chunkById(100, function ($users) use (&$count, $now): void {
                foreach ($users as $user) {
                    // Manually hydrate the relation to prevent N+1 in notify() -> isPushEnabled()
                    $preference = new NotificationPreference([
                        'type' => 'training_reminder',
                        'is_enabled' => true,
                        'is_push_enabled' => (bool) $user->getAttribute('pref_push'),
                        'value' => $user->getAttribute('pref_value') !== null ? (int) $user->getAttribute('pref_value') : null,
                    ]);

                    $user->setRelation('notificationPreferences', collect([$preference]));

                    // Use user-defined value or fallback to 3 days
                    $days = $preference->value ?? 3;
                    $threshold = $now - ($days * 86400);

                    $lastWorkoutStartedAtStr = $user->getAttribute('last_workout_started_at');
                    $lastWorkoutTimestamp = is_string($lastWorkoutStartedAtStr) ? strtotime($lastWorkoutStartedAtStr) : null;

                    if (! $lastWorkoutTimestamp || $lastWorkoutTimestamp < $threshold) {
                        $user->notify(new TrainingReminder());
                        $count++;
                    }
                }
            }, 'users.id', 'id');

        $this->info("Sent {$count} training reminders.");
    }
}
