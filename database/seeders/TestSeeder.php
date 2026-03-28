<?php
namespace Database\Seeders;

use App\Models\User;
use App\Models\Fast;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

class TestSeeder extends Seeder
{
    public function run(): void
    {
        $user = User::where('email', 'test@example.com')->first();

        // Add past completed fasts to ensure chart has data
        for ($i = 5; $i >= 1; $i--) {
            $start = Carbon::now()->subDays($i)->setHour(20)->setMinute(0);
            $end = $start->copy()->addHours(16);

            Fast::create([
                'user_id' => $user->id,
                'start_time' => $start,
                'end_time' => $end,
                'target_duration_minutes' => 16 * 60,
                'status' => 'completed',
                'type' => '16:8'
            ]);
        }

        // Add a 20h fast
        Fast::create([
            'user_id' => $user->id,
            'start_time' => Carbon::now()->subDays(6)->setHour(20)->setMinute(0),
            'end_time' => Carbon::now()->subDays(5)->setHour(16)->setMinute(0),
            'target_duration_minutes' => 20 * 60,
            'status' => 'completed',
            'type' => '20:4'
        ]);
    }
}
