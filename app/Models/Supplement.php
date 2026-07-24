<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\SupplementFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Supplement extends Model
{
    /** @use HasFactory<SupplementFactory> */
    use HasFactory;

    use LogsActivity;

    protected $fillable = [
        'user_id',
        'name',
        'brand',
        'dosage',
        'servings_remaining',
        'low_stock_threshold',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<SupplementLog, $this>
     */
    public function logs(): HasMany
    {
        return $this->hasMany(SupplementLog::class);
    }

    /**
     * @return HasOne<SupplementLog, $this>
     */
    public function latestLog(): HasOne
    {
        return $this->hasOne(SupplementLog::class)->latestOfMany('consumed_at');
    }

    /**
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeForUser(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'brand', 'dosage', 'servings_remaining', 'low_stock_threshold'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    protected function casts(): array
    {
        return [
            'servings_remaining' => 'integer',
            'low_stock_threshold' => 'integer',
        ];
    }
}
