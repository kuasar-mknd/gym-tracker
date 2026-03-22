<?php
namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Goal;
use App\Models\User;
use Carbon\Carbon;

class GoalSeeder extends Seeder
{
    public function run(): void
    {
        $user = User::where('email', 'test@example.com')->first();

        if (!$user) {
            return;
        }

        Goal::create([
            'user_id' => $user->id,
            'title' => 'Deadlift 200kg',
            'type' => 'weight',
            'start_value' => 180,
            'target_value' => 200,
            'current_value' => 190,
            'deadline' => Carbon::now()->addMonths(2),
        ]);

        Goal::create([
            'user_id' => $user->id,
            'title' => 'Workout 4x week',
            'type' => 'frequency',
            'start_value' => 0,
            'target_value' => 4,
            'current_value' => 2,
            'deadline' => Carbon::now()->endOfWeek(),
        ]);

        Goal::create([
            'user_id' => $user->id,
            'title' => '1000 Pushups',
            'type' => 'volume',
            'start_value' => 0,
            'target_value' => 1000,
            'current_value' => 1000,
            'deadline' => Carbon::now()->subDays(1),
            'completed_at' => Carbon::now()->subDays(2),
        ]);
    }
}
