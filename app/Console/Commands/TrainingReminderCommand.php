<?php

namespace App\Console\Commands;

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
    protected $description = 'Send training reminders to users who have not trained in several days.';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Starting training reminders check...');

        $threeDaysAgo = Carbon::now()->subDays(3);

        // Find users who:
        // 1. Have notification enabled
        // 2. Last workout was more than 3 days ago OR they have NO workouts
        $usersToNotify = User::whereHas('notificationPreferences', function ($query) {
            $query->where('type', 'training_reminder')->where('is_enabled', true);
        })
            ->where(function ($query) use ($threeDaysAgo) {
                $query->whereDoesntHave('workouts')
                    ->orWhereHas('workouts', function ($subQuery) use ($threeDaysAgo) {
                        $subQuery->where('start_time', '<', $threeDaysAgo);
                    }, '<=', 0); // This logic is slightly complex, let's simplify
            })->get();

        // Simplified query logic:
        // Users where (last workout date < 3 days ago) AND (preference == true)

        $count = 0;
        User::all()->each(function (User $user) use (&$count, $threeDaysAgo) {
            if ($user->isNotificationEnabled('training_reminder')) {
                $lastWorkout = $user->workouts()->latest('started_at')->first();

                if (! $lastWorkout || $lastWorkout->started_at->lt($threeDaysAgo)) {
                    // Check if we already sent a reminder recently to avoid spamming?
                    // For now, let's just send it.
                    $user->notify(new TrainingReminder);
                    $count++;
                }
            }
        });

        $this->info("Sent {$count} training reminders.");
    }
}
