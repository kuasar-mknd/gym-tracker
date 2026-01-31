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
        User::all()->each(function (User $user) use (&$count): void {
            /** @var NotificationPreference|null $preference */
            $preference = $user->notificationPreferences()
                ->where('type', 'training_reminder')
                ->first();

            if ($preference && $preference->is_enabled) {
                // Use user-defined value or fallback to 3 days
                $days = $preference->value ?? 3;
                $threshold = Carbon::now()->subDays($days);

                $lastWorkout = $user->workouts()->latest('started_at')->first();

                if (! $lastWorkout || $lastWorkout->started_at->lt($threshold)) {
                    // Only notify if we haven't notified them recently to avoid daily spam?
                    // For now, simplicity: if they are beyond threshold, notify.
                    $user->notify(new TrainingReminder());
                    $count++;
                }
            }
        });

        $this->info("Sent {$count} training reminders.");
    }
}
