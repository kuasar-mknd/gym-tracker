<?php
namespace Database\Seeders;
use Illuminate\Database\Seeder;
use App\Models\User;
use App\Models\Exercise;
use App\Models\PersonalRecord;
class TestSeeder extends Seeder {
    public function run(): void {
        $user = User::first();
        $exercise = Exercise::first() ?? Exercise::factory()->create();
        PersonalRecord::create(['user_id' => $user->id, 'exercise_id' => $exercise->id, 'type' => 'max_weight', 'value' => 100, 'achieved_at' => now()]);
        PersonalRecord::create(['user_id' => $user->id, 'exercise_id' => $exercise->id, 'type' => 'max_1rm', 'value' => 120, 'achieved_at' => now()->subDay()]);
    }
}
