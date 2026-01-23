<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $user_id
 * @property string $name
 * @property string|null $description
 * @property string $color
 * @property string $icon
 * @property int $goal_times_per_week
 * @property bool $archived
 * @property-read \App\Models\User $user
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\HabitLog> $logs
 */
class Habit extends Model
{
    /** @use HasFactory<\Database\Factories\HabitFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'name',
        'description',
        'color',
        'icon',
        'goal_times_per_week',
        'archived',
    ];

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo<\App\Models\User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\HasMany<\App\Models\HabitLog, $this>
     */
    public function logs(): HasMany
    {
        return $this->hasMany(HabitLog::class);
    }

    protected function casts(): array
    {
        return [
            'archived' => 'boolean',
            'goal_times_per_week' => 'integer',
        ];
    }
}
