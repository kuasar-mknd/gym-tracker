<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MacroCalculation extends Model
{
    protected $fillable = [
        'user_id',
        'gender',
        'age',
        'height',
        'weight',
        'activity_level',
        'goal',
        'tdee',
        'target_calories',
        'protein',
        'fat',
        'carbs',
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
            'age' => 'integer',
            'height' => 'decimal:2',
            'weight' => 'decimal:2',
            'activity_level' => 'decimal:2',
            'tdee' => 'integer',
            'target_calories' => 'integer',
            'protein' => 'integer',
            'fat' => 'integer',
            'carbs' => 'integer',
        ];
    }
}
