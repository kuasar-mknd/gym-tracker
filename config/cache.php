<?php

declare(strict_types=1);

use Illuminate\Support\Str;

return [

    /*
    |--------------------------------------------------------------------------
    | Default Cache Store
    |--------------------------------------------------------------------------
    |
    | This option controls the default cache store that will be used by the
    | framework. This connection is utilized if another isn't explicitly
    | specified when running a cache operation inside the application.
    |
    */

    'default' => env('CACHE_STORE', 'database'),

    /*
    |--------------------------------------------------------------------------
    | Cache Stores
    |--------------------------------------------------------------------------
    |
    | Here you may define all of the cache "stores" for your application as
    | well as their drivers. You may even define multiple stores for the
    | same cache driver to group types of items stored in your caches.
    |
    | Supported drivers: "array", "database", "file", "memcached",
    |                    "redis", "dynamodb", "octane",
    |                    "failover", "null"
    |
    */

    'stores' => [

        'array' => [
            'driver' => 'array',
            'serialize' => false,
        ],

        'database' => [
            'driver' => 'database',
            'connection' => env('DB_CACHE_CONNECTION'),
            'table' => env('DB_CACHE_TABLE', 'cache'),
            'lock_connection' => env('DB_CACHE_LOCK_CONNECTION'),
            'lock_table' => env('DB_CACHE_LOCK_TABLE'),
        ],

        'file' => [
            'driver' => 'file',
            'path' => storage_path('framework/cache/data'),
            'lock_path' => storage_path('framework/cache/data'),
        ],

        'memcached' => [
            'driver' => 'memcached',
            'persistent_id' => env('MEMCACHED_PERSISTENT_ID'),
            'sasl' => [
                env('MEMCACHED_USERNAME'),
                env('MEMCACHED_PASSWORD'),
            ],
            'options' => [
                // Memcached::OPT_CONNECT_TIMEOUT => 2000,
            ],
            'servers' => [
                [
                    'host' => env('MEMCACHED_HOST', '127.0.0.1'),
                    'port' => env('MEMCACHED_PORT', 11211),
                    'weight' => 100,
                ],
            ],
        ],

        'redis' => [
            'driver' => 'redis',
            'connection' => env('REDIS_CACHE_CONNECTION', 'cache'),
            'lock_connection' => env('REDIS_CACHE_LOCK_CONNECTION', 'default'),
        ],

        'dynamodb' => [
            'driver' => 'dynamodb',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
            'table' => env('DYNAMODB_CACHE_TABLE', 'cache'),
            'endpoint' => env('DYNAMODB_ENDPOINT'),
        ],

        'octane' => [
            'driver' => 'octane',
        ],

        'failover' => [
            'driver' => 'failover',
            'stores' => [
                'database',
                'array',
            ],
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Cache Key Prefix
    |--------------------------------------------------------------------------
    |
    | When utilizing the APC, database, memcached, Redis, and DynamoDB cache
    | stores, there might be other applications using the same cache. For
    | that reason, you may prefix every cache key to avoid collisions.
    |
    */

    'prefix' => env('CACHE_PREFIX', Str::slug((string) env('APP_NAME', 'laravel')).'-cache-'),

    /*
    |--------------------------------------------------------------------------
    | Serializable Classes
    |--------------------------------------------------------------------------
    |
    | Passed to unserialize() as "allowed_classes" when reading cached values,
    | so a leaked APP_KEY cannot be turned into remote code execution through a
    | deserialization gadget chain in some vendor package. Laravel 13 ships this
    | as `false` for new applications; this app caches Eloquent models, so it
    | needs an explicit list instead.
    |
    | Anything absent from this list comes back as __PHP_Incomplete_Class rather
    | than throwing, which fails quietly at runtime — so the list is deliberately
    | generous. Over-listing only weakens the guard slightly; under-listing
    | breaks a feature without saying so. Every app model, DTO and enum is
    | allowed regardless of whether it is cached today, and the vendor entries
    | are limited to the container types Eloquent results are made of.
    |
    | tests/Feature/Cache/SerializableClassesTest.php round-trips the real cached
    | values through this list and fails if a class is missing.
    |
    */

    'serializable_classes' => [
        ...array_map(
            fn (string $model): string => 'App\\Models\\'.$model,
            [
                'Achievement', 'Admin', 'BaseMeasurement', 'BodyMeasurement', 'BodyPartMeasurement',
                'DailyJournal', 'Exercise', 'Fast', 'Goal', 'Habit', 'HabitLog', 'IntervalTimer',
                'MacroCalculation', 'NotificationPreference', 'PersonalRecord', 'Plate', 'Set',
                'Supplement', 'SupplementLog', 'User', 'UserAchievement', 'WarmupPreference',
                'WaterLog', 'WilksScore', 'Workout', 'WorkoutLine', 'WorkoutTemplate',
                'WorkoutTemplateLine', 'WorkoutTemplateSet',
            ]
        ),

        App\DTOs\Stats\BodyFatHistoryPoint::class,
        App\DTOs\Stats\DailyVolumeTrendPoint::class,
        App\DTOs\Stats\DistributionStat::class,
        App\DTOs\Stats\DurationHistoryPoint::class,
        App\DTOs\Stats\Exercise1RMProgressPoint::class,
        App\DTOs\Stats\LatestBodyMetrics::class,
        App\DTOs\Stats\MonthlyVolumePoint::class,
        App\DTOs\Stats\MuscleDistributionStat::class,
        App\DTOs\Stats\VolumeComparison::class,
        App\DTOs\Stats\VolumeHistoryPoint::class,
        App\DTOs\Stats\VolumeTrendPoint::class,
        App\DTOs\Stats\WeeklyVolumeTrendPoint::class,
        App\DTOs\Stats\WeightHistoryPoint::class,

        App\Enums\ExerciseCategory::class,
        App\Enums\GoalType::class,
        App\Enums\PersonalRecordType::class,

        // What Eloquent results are actually built out of.
        Illuminate\Database\Eloquent\Collection::class,
        Illuminate\Support\Collection::class,
        Illuminate\Notifications\DatabaseNotification::class,
        Illuminate\Support\Carbon::class,
        Carbon\Carbon::class,
        Carbon\CarbonImmutable::class,
        stdClass::class,
    ],

];
