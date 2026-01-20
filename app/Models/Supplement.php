<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Supplement extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'name',
        'brand',
        'dosage',
        'servings_remaining',
        'low_stock_threshold',
    ];

    protected function casts(): array
    {
        return [
            'servings_remaining' => 'integer',
            'low_stock_threshold' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function logs(): HasMany
    {
        return $this->hasMany(SupplementLog::class);
    }

    public function latestLog(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(SupplementLog::class)->latestOfMany('consumed_at');
    }

    public function scopeForUser($query, $userId)
    {
        return $query->where('user_id', $userId);
    }
}
