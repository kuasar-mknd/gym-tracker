<?php
use App\Models\User;
use App\Models\Workout;
use App\Models\WorkoutLine;
use App\Models\Exercise;
use App\Models\Set;
use Illuminate\Support\Facades\DB;

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';

$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

// Create a user with some workouts
$user = User::factory()->create();

for ($i = 0; $i < 5; $i++) {
    $workout = Workout::factory()->create(['user_id' => $user->id]);
    for ($j = 0; $j < 3; $j++) {
        $exercise = Exercise::factory()->create();
        $line = WorkoutLine::factory()->create([
            'workout_id' => $workout->id,
            'exercise_id' => $exercise->id,
        ]);
        Set::factory()->count(3)->create(['workout_line_id' => $line->id]);
    }
}

DB::enableQueryLog();

echo "--- Serializing 5 workouts with 3 exercises each ---\n";
$workouts = Workout::with('workoutLines.exercise')->where('user_id', $user->id)->get();
$json = json_encode($workouts);

$queryCount = count(DB::getQueryLog());
echo "Total queries during serialization: $queryCount\n";

// Show some queries
// foreach (DB::getQueryLog() as $query) {
//    echo "QUERY: " . $query['query'] . "\n";
// }

if ($queryCount > 10) {
    echo "⚠️ Query explosion detected!\n";
} else {
    echo "✅ No query explosion.\n";
}
