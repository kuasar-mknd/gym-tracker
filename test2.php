<?php
require 'vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\StreakService;
use App\Models\User;
use App\Models\Workout;
use Illuminate\Support\Carbon;

$streakService = app(StreakService::class);

$user = User::factory()->create([
    'current_streak' => 3,
    'longest_streak' => 3,
    'last_workout_at' => Carbon::now(),
]);

$workout = Workout::factory()->create([
    'user_id' => $user->id,
    'started_at' => Carbon::now()->addHours(2),
]);

$streakService->updateStreak($user, $workout);

$user->refresh();

var_dump($user->current_streak);
