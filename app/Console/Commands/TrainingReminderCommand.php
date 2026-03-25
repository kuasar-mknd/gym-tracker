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

        // 1. Only fetch users who have the 'training_reminder' preference enabled
        // 2. Use chunkById to process users in batches (memory efficient)
        // 3. Eager load only the necessary preference to avoid N+1
        User::query()
            ->whereHas('notificationPreferences', function ($query): void {
                $query->where('type', 'training_reminder')
                    ->where('is_enabled', true);
            })
            ->with([
                'notificationPreferences' => function ($query): void {
                    $query->where('type', 'training_reminder');
                },
            ])
            ->addSelect(['last_workout_started_at' => \App\Models\Workout::select('started_at')
                ->whereColumn('user_id', 'users.id')
                ->orderByDesc('started_at')
                ->limit(1),
            ])
            ->chunkById(100, function ($users) use (&$count): void {
                foreach ($users as $user) {
                    /** @var NotificationPreference|null $preference */
                    $preference = $user->notificationPreferences->first();

                    if (! $preference) {
                        continue;
                    }

                    // Use user-defined value or fallback to 3 days
                    $days = $preference->value ?? 3;
                    $threshold = Carbon::now()->subDays($days);

                    $lastWorkoutStartedAtStr = $user->getAttribute('last_workout_started_at');
                    $lastWorkoutStartedAt = is_string($lastWorkoutStartedAtStr) ? Carbon::parse($lastWorkoutStartedAtStr) : null;

                    if (! $lastWorkoutStartedAt || $lastWorkoutStartedAt->lt($threshold)) {
                        $user->notify(new TrainingReminder());
                        $count++;
                    }
                }
            });

        $this->info("Sent {$count} training reminders.");
    }
}
