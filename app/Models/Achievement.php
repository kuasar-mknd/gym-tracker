<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * @property int $id
 * @property string $slug
 * @property string $name
 * @property string $description
 * @property string $icon
 * @property string $type
 * @property float $threshold
 * @property string $category
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\User> $users
 */
class Achievement extends Model
{
    /** @use HasFactory<\Database\Factories\AchievementFactory> */
    use HasFactory, LogsActivity;

    protected $fillable = [
        'slug',
        'name',
        'description',
        'icon',
        'type',
        'threshold',
        'category',
    ];

    /**
     * ⚡ Bolt Optimization: Cache all achievements to prevent N+1 queries.
     * Impact: Reduces database queries from O(N) to O(1) on high-traffic pages like Dashboard and Workouts.
     *
     * @return Collection<int, Achievement>
     */
    public static function getCachedAll(): Collection
    {
        return Cache::rememberForever('achievements_all', function (): Collection {
            return self::all();
        });
    }

    protected static function booted(): void
    {
        parent::booted();

        static::saved(function (Achievement $achievement) {
            Cache::forget('achievements_all');
        });

        static::deleted(function (Achievement $achievement) {
            Cache::forget('achievements_all');
        });
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany<\App\Models\User, $this, \Illuminate\Database\Eloquent\Relations\Pivot, 'pivot'>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_achievements')
            ->withPivot('achieved_at')
            ->withTimestamps();
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['slug', 'name', 'description', 'type', 'category', 'threshold'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }
}
