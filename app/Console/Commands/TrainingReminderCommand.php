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

                    // Still one query per user for the workout, but filtered users is much smaller
                    $lastWorkout = $user->workouts()->latest('started_at')->first();

                    if (! $lastWorkout || $lastWorkout->started_at->lt($threshold)) {
                        $user->notify(new TrainingReminder());
                        $count++;
                    }
                }
            });

        $this->info("Sent {$count} training reminders.");
    }
}
