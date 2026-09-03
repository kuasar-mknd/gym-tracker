<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NotificationPreference extends Model
{
    /** @use HasFactory<\Database\Factories\NotificationPreferenceFactory> */
    use HasFactory;

    #[\Override]
    protected $fillable = [
        'user_id',
        'type',
        'is_enabled',
        'is_push_enabled',
        'value',
        'days',
    ];

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo<\App\Models\User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    #[\Override]
    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'is_push_enabled' => 'boolean',
            'value' => 'integer',
            'days' => 'array',
        ];
    }
}
