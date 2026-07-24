<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property int $achievement_id
 * @property Carbon $achieved_at
 * @property-read User $user
 * @property-read Achievement $achievement
 */
class UserAchievement extends Model
{
    protected $fillable = [
        'user_id',
        'achievement_id',
        'achieved_at',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Achievement, $this>
     */
    public function achievement(): BelongsTo
    {
        return $this->belongsTo(Achievement::class);
    }

    protected function casts(): array
    {
        return [
            'achieved_at' => 'datetime',
        ];
    }
}
