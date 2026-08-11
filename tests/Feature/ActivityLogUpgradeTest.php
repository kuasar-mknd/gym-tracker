<?php

declare(strict_types=1);

use App\Models\Exercise;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;

/**
 * Nothing in the suite exercised activity logging end to end: the models
 * configure it, and no test ever looked for the row. That was survivable while
 * the column layout held still, and stopped being so at v5 — which moves the
 * tracked changes out of `properties` and into `attribute_changes`, so logging
 * could have gone on writing nothing at all and every existing test would have
 * agreed.
 */
it('records a model change in the column v5 reads', function (): void {
    $exercise = Exercise::factory()->create(['name' => 'Squat']);

    $exercise->update(['name' => 'Front squat']);

    $activity = Activity::query()->latest('id')->first();

    expect($activity)->not->toBeNull()
        ->and($activity->event)->toBe('updated')
        ->and($activity->attribute_changes['attributes']['name'])->toBe('Front squat')
        ->and($activity->attribute_changes['old']['name'])->toBe('Squat');
});

/**
 * logOnlyDirty + dontLogEmptyChanges — the v5 spelling of dontSubmitEmptyLogs.
 * Saving a model without touching a logged attribute must leave no trace, or
 * the log fills with entries describing nothing.
 */
it('writes nothing when no logged attribute moved', function (): void {
    $exercise = Exercise::factory()->create(['name' => 'Squat']);

    $before = Activity::query()->count();

    $exercise->update(['name' => 'Squat']);

    expect(Activity::query()->count())->toBe($before);
});

/**
 * The upgrade migration's backfill, run against rows in the v4 layout.
 *
 * The method is invoked directly rather than by re-running the migration: by
 * the time a test executes, the schema is already at v5, and re-running up()
 * would try to add a column that exists. What is under test is the data move,
 * not the DDL around it.
 */
it('moves v4 rows into the split columns', function (): void {
    $id = DB::table('activity_log')->insertGetId([
        'log_name' => 'default',
        'description' => 'updated',
        'properties' => json_encode([
            'attributes' => ['name' => 'Front squat'],
            'old' => ['name' => 'Squat'],
            'ip' => '203.0.113.4',
        ]),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    migrationUnderTest()->splitProperties();

    $row = DB::table('activity_log')->where('id', $id)->first();

    // Compared key by key rather than whole: MySQL reorders the keys of a json
    // column on write, so asserting the array as a unit tests the storage
    // engine's ordering rules instead of what the migration moved.
    expect(json_decode((string) $row->attribute_changes, true))
        ->toHaveKeys(['attributes', 'old'])
        ->and(json_decode((string) $row->attribute_changes, true)['attributes'])->toBe(['name' => 'Front squat'])
        ->and(json_decode((string) $row->attribute_changes, true)['old'])->toBe(['name' => 'Squat'])
        // Custom data set through withProperties() stays where it was; only the
        // package's own two keys move.
        ->and(json_decode((string) $row->properties, true))->toBe(['ip' => '203.0.113.4']);
});

it('leaves a row that only ever held custom properties alone', function (): void {
    $id = DB::table('activity_log')->insertGetId([
        'log_name' => 'default',
        'description' => 'exported',
        'properties' => json_encode(['ip' => '203.0.113.4']),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    migrationUnderTest()->splitProperties();

    $row = DB::table('activity_log')->where('id', $id)->first();

    expect($row->attribute_changes)->toBeNull()
        ->and(json_decode((string) $row->properties, true))->toBe(['ip' => '203.0.113.4']);
});

/**
 * Nulls out `properties` rather than leaving `{}` behind, so a row that carried
 * nothing but tracked changes does not read as having custom data attached.
 */
it('empties properties when the changes were all it held', function (): void {
    $id = DB::table('activity_log')->insertGetId([
        'log_name' => 'default',
        'description' => 'updated',
        'properties' => json_encode(['attributes' => ['name' => 'Front squat']]),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    migrationUnderTest()->splitProperties();

    $row = DB::table('activity_log')->where('id', $id)->first();

    expect($row->properties)->toBeNull()
        ->and(json_decode((string) $row->attribute_changes, true))
        ->toBe(['attributes' => ['name' => 'Front squat']]);
});

/**
 * Reaches the migration's backfill without re-running its DDL. The methods are
 * private because nothing but the migration should call them in production;
 * leaving them untested because of that would mean shipping a data move over
 * every historical row on nothing but inspection.
 */
function migrationUnderTest(): object
{
    $migration = require dirname(__DIR__, 2)
        .'/database/migrations/2026_08_11_092854_move_activity_log_changes_out_of_properties.php';

    return new readonly class($migration)
    {
        public function __construct(private object $migration)
        {
        }

        public function splitProperties(): void
        {
            $method = new ReflectionMethod($this->migration, 'splitProperties');
            $method->invoke($this->migration);
        }
    };
}
