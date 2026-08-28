<?php

declare(strict_types=1);

use App\Models\Achievement;
use App\Models\BodyMeasurement;
use App\Models\BodyPartMeasurement;
use App\Models\DailyJournal;
use App\Models\Exercise;
use App\Models\Fast;
use App\Models\Goal;
use App\Models\Habit;
use App\Models\HabitLog;
use App\Models\IntervalTimer;
use App\Models\MacroCalculation;
use App\Models\PersonalRecord;
use App\Models\Plate;
use App\Models\Set;
use App\Models\Supplement;
use App\Models\SupplementLog;
use App\Models\User;
use App\Models\UserAchievement;
use App\Models\WaterLog;
use App\Models\WilksScore;
use App\Models\Workout;
use App\Models\WorkoutLine;
use App\Models\WorkoutTemplate;
use App\Models\WorkoutTemplateLine;
use App\Models\WorkoutTemplateSet;
use Illuminate\Support\Facades\DB;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

/**
 * Une page sans `ORDER BY` n'est pas une page.
 *
 * Rien n'oblige MySQL a rendre deux fois le meme ordre : le plan peut changer
 * avec le `OFFSET`, et une ligne se retrouve alors sur les deux pages pendant
 * qu'une autre n'apparait sur aucune. Onze points d'entree paginaient ainsi.
 *
 * Le controle porte sur la FORME. Compter sur MySQL pour desordonner
 * effectivement donnerait un test qui passe presque toujours, donc instable.
 *
 * Chaque cas seme une ligne : sur une table vide, le paginateur s'arrete au
 * decompte et n'emet jamais la lecture qu'on veut inspecter.
 */
it('ordonne toute page rendue par l’API', function (string $route, Closure $semer): void {
    $user = User::factory()->create();
    $semer($user);

    DB::flushQueryLog();
    DB::enableQueryLog();
    $this->actingAs($user, 'sanctum')->getJson(route($route))->assertOk();
    $requetes = array_map(fn (array $entree): string => (string) $entree['query'], DB::getQueryLog());
    DB::disableQueryLog();

    // La requete paginee est celle qui porte un `limit` : la lecture de session
    // et les chargements anticipes n'en ont pas, le decompte n'est pas un tri.
    $lectures = array_values(array_filter(
        $requetes,
        fn (string $sql): bool => str_starts_with($sql, 'select ')
            && str_contains($sql, ' limit ')
            && ! str_contains($sql, 'count(*)')
    ));

    expect($lectures)->not->toBeEmpty();

    foreach ($lectures as $sql) {
        expect($sql)->toContain('order by');
    }
})->with([
    ['api.v1.achievements.index', fn () => Achievement::factory()->create()],
    ['api.v1.body-measurements.index', fn (User $u) => BodyMeasurement::factory()->create(['user_id' => $u->id])],
    ['api.v1.body-part-measurements.index', fn (User $u) => BodyPartMeasurement::factory()->create(['user_id' => $u->id])],
    ['api.v1.daily-journals.index', fn (User $u) => DailyJournal::factory()->create(['user_id' => $u->id])],
    ['api.v1.exercises.index', fn (User $u) => Exercise::factory()->create(['user_id' => $u->id])],
    ['api.v1.fasts.index', fn (User $u) => Fast::factory()->create(['user_id' => $u->id])],
    ['api.v1.goals.index', fn (User $u) => Goal::factory()->create(['user_id' => $u->id])],
    ['api.v1.habit-logs.index', fn (User $u) => HabitLog::factory()->create(['habit_id' => Habit::factory()->create(['user_id' => $u->id])->id])],
    ['api.v1.habits.index', fn (User $u) => Habit::factory()->create(['user_id' => $u->id])],
    ['api.v1.interval-timers.index', fn (User $u) => IntervalTimer::factory()->create(['user_id' => $u->id])],
    ['api.v1.macro-calculations.index', fn (User $u) => MacroCalculation::factory()->create(['user_id' => $u->id])],
    ['api.v1.personal-records.index', fn (User $u) => PersonalRecord::factory()->create(['user_id' => $u->id])],
    ['api.v1.plates.index', fn (User $u) => Plate::factory()->create(['user_id' => $u->id])],
    ['api.v1.sets.index', fn (User $u) => Set::factory()->create(['workout_line_id' => WorkoutLine::factory()->create(['workout_id' => Workout::factory()->create(['user_id' => $u->id])->id])->id])],
    ['api.v1.supplement-logs.index', fn (User $u) => SupplementLog::factory()->create(['user_id' => $u->id, 'supplement_id' => Supplement::factory()->create(['user_id' => $u->id])->id])],
    ['api.v1.supplements.index', fn (User $u) => Supplement::factory()->create(['user_id' => $u->id])],
    ['api.v1.user-achievements.index', fn (User $u) => UserAchievement::factory()->create(['user_id' => $u->id])],
    ['api.v1.water-logs.index', fn (User $u) => WaterLog::factory()->create(['user_id' => $u->id])],
    ['api.v1.wilks-scores.index', fn (User $u) => WilksScore::factory()->create(['user_id' => $u->id])],
    ['api.v1.workout-lines.index', fn (User $u) => WorkoutLine::factory()->create(['workout_id' => Workout::factory()->create(['user_id' => $u->id])->id])],
    ['api.v1.workout-template-lines.index', fn (User $u) => WorkoutTemplateLine::factory()->create(['workout_template_id' => WorkoutTemplate::factory()->create(['user_id' => $u->id])->id])],
    ['api.v1.workout-template-sets.index', fn (User $u) => WorkoutTemplateSet::factory()->create(['workout_template_line_id' => WorkoutTemplateLine::factory()->create(['workout_template_id' => WorkoutTemplate::factory()->create(['user_id' => $u->id])->id])->id])],
    ['api.v1.workout-templates.index', fn (User $u) => WorkoutTemplate::factory()->create(['user_id' => $u->id])],
    ['api.v1.workouts.index', fn (User $u) => Workout::factory()->create(['user_id' => $u->id])],
]);
