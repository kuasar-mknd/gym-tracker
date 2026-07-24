<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\WorkoutTemplateFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $user_id
 * @property string $name
 * @property string|null $description
 * @property-read User $user
 * @property-read Collection<int, WorkoutTemplateLine> $workoutTemplateLines
 */
class WorkoutTemplate extends Model
{
    /** @use HasFactory<WorkoutTemplateFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'description',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<WorkoutTemplateLine, $this>
     */
    public function workoutTemplateLines(): HasMany
    {
        return $this->hasMany(WorkoutTemplateLine::class);
    }
}
