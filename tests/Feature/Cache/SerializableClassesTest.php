<?php

declare(strict_types=1);

namespace Tests\Feature\Cache;

use App\Models\Achievement;
use App\Models\BodyMeasurement;
use App\Models\Exercise;
use App\Models\User;
use App\Models\Workout;
use App\Notifications\AchievementUnlocked;
use App\Services\NotificationService;
use App\Services\Stats\BodyStatsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * cache.serializable_classes is handed to unserialize() as "allowed_classes".
 * Anything missing from it comes back as __PHP_Incomplete_Class instead of
 * throwing, so an incomplete list does not fail the request — it hands the
 * application a hollow object and lets it fall over somewhere unrelated.
 *
 * Nothing else in the suite can catch that: phpunit.xml pins CACHE_STORE=array,
 * and the array store keeps live PHP objects without ever serializing them. So
 * these run the real cached code paths and then put the results through the
 * allow-list by hand.
 */
final class SerializableClassesTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<int, string>
     */
    private function allowedClasses(): array
    {
        /** @var array<int, string> $allowed */
        $allowed = config('cache.serializable_classes');

        return $allowed;
    }

    /**
     * Walks the restored graph looking for the hollow objects PHP substitutes
     * for disallowed classes, and reports the original class name so a failure
     * names what to add rather than just saying something broke.
     *
     * @return array<int, string>
     */
    private function incompleteClassesIn(mixed $restored, mixed $original): array
    {
        if ($restored instanceof \__PHP_Incomplete_Class) {
            return [is_object($original) ? $original::class : 'unknown'];
        }

        $found = [];

        if (is_iterable($restored)) {
            $originalItems = is_iterable($original)
                ? collect($original)->values()->all()
                : [];

            foreach (collect($restored)->values()->all() as $index => $item) {
                $found = [...$found, ...$this->incompleteClassesIn($item, $originalItems[$index] ?? null)];
            }
        }

        if (is_object($restored) && ! $restored instanceof \Traversable) {
            foreach (get_object_vars($restored) as $property => $value) {
                $originalValue = is_object($original) ? (get_object_vars($original)[$property] ?? null) : null;
                $found = [...$found, ...$this->incompleteClassesIn($value, $originalValue)];
            }
        }

        return $found;
    }

    private function assertSurvivesTheAllowList(mixed $value, string $label): void
    {
        $restored = unserialize(serialize($value), ['allowed_classes' => $this->allowedClasses()]);

        $missing = array_unique($this->incompleteClassesIn($restored, $value));

        $this->assertSame(
            [],
            $missing,
            "{$label} does not survive cache.serializable_classes. Add: ".implode(', ', $missing)
        );
    }

    public function test_the_cached_exercise_list_survives_the_allow_list(): void
    {
        $user = User::factory()->create();
        Exercise::factory()->count(3)->create(['user_id' => $user->id]);

        $this->assertSurvivesTheAllowList(
            Exercise::getCachedForUser($user->id),
            'The cached exercise list'
        );
    }

    public function test_the_cached_achievement_list_survives_the_allow_list(): void
    {
        Achievement::factory()->count(3)->create();

        $this->assertSurvivesTheAllowList(
            Achievement::getCachedAll(),
            'The cached achievement list'
        );
    }

    public function test_the_cached_latest_achievement_notification_survives_the_allow_list(): void
    {
        $user = User::factory()->create();
        $user->notify(new AchievementUnlocked(Achievement::factory()->create()));

        $this->assertSurvivesTheAllowList(
            app(NotificationService::class)->getLatestAchievement($user->fresh()),
            'The cached latest achievement notification'
        );
    }

    public function test_the_cached_body_metrics_dto_survives_the_allow_list(): void
    {
        $user = User::factory()->create();
        BodyMeasurement::factory()->count(2)->create(['user_id' => $user->id]);

        $this->assertSurvivesTheAllowList(
            app(BodyStatsService::class)->getLatestBodyMetrics($user),
            'The cached body metrics DTO'
        );
    }

    public function test_the_cached_active_workout_survives_the_allow_list(): void
    {
        $user = User::factory()->create();
        Workout::factory()->create([
            'user_id' => $user->id,
            'started_at' => now(),
            'ended_at' => null,
        ]);

        $activeWorkout = $user->workouts()
            ->select('id', 'name', 'started_at')
            ->whereNull('ended_at')
            ->withCount('workoutLines')
            ->latest('started_at')
            ->first();

        $this->assertSurvivesTheAllowList($activeWorkout, 'The cached active workout');
    }

    /**
     * The allow-list is only as good as its coverage, and a model added later
     * that lands in a cached query would fail silently. Listing every model is
     * cheap and costs nothing in hardening — the gadget chains this defends
     * against live in vendor packages, not in app/Models.
     */
    public function test_every_application_model_is_allowed(): void
    {
        $this->assertNothingMissing($this->classesUnder('Models'), 'Models');
    }

    /**
     * Two DTOs were missed on the first pass at the list precisely because they
     * are only ever reached through an array returned by a cached method, where
     * nothing in the signature says an object is in there.
     */
    public function test_every_stats_dto_is_allowed(): void
    {
        $this->assertNothingMissing($this->classesUnder('DTOs/Stats', 'DTOs\\Stats'), 'DTOs');
    }

    public function test_every_enum_is_allowed(): void
    {
        $this->assertNothingMissing($this->classesUnder('Enums'), 'Enums');
    }

    /**
     * @return array<int, string>
     */
    private function classesUnder(string $directory, ?string $namespace = null): array
    {
        return collect(glob(app_path($directory.'/*.php')) ?: [])
            ->map(fn (string $path): string => 'App\\'.($namespace ?? $directory).'\\'.basename($path, '.php'))
            ->filter(fn (string $class): bool => class_exists($class) || enum_exists($class))
            ->values()
            ->all();
    }

    /**
     * @param  array<int, string>  $classes
     */
    private function assertNothingMissing(array $classes, string $label): void
    {
        $this->assertNotEmpty($classes, "Found no {$label} to check — the glob is wrong.");

        $missing = array_values(array_diff($classes, $this->allowedClasses()));

        $this->assertSame(
            [],
            $missing,
            "{$label} missing from cache.serializable_classes: ".implode(', ', $missing)
        );
    }
}
