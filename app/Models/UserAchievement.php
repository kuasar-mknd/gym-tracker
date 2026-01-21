<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $user_id
 * @property int $achievement_id
 * @property \Illuminate\Support\Carbon $achieved_at
 * @property-read \App\Models\User $user
 * @property-read \App\Models\Achievement $achievement
 */
class UserAchievement extends Model
{
    protected $fillable = [
        'user_id',
        'achievement_id',
        'achieved_at',
    ];

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo<\App\Models\User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo<\App\Models\Achievement, $this>
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
