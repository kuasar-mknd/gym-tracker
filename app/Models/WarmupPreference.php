<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WarmupPreference extends Model
{
    /** @use HasFactory<\Database\Factories\WarmupPreferenceFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'bar_weight',
        'rounding_increment',
        'steps',
    ];

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo<\App\Models\User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    protected function casts(): array
    {
        return [
            'bar_weight' => 'float',
            'rounding_increment' => 'float',
            'steps' => 'array',
        ];
    }
}
