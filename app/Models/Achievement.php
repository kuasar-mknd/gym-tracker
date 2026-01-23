<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
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
     * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany<\App\Models\User, $this>
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
