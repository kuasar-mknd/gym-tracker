<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MacroCalculation extends Model
{
    use HasFactory;

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

    protected $casts = [
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

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
