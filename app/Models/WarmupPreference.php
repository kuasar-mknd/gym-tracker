<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WarmupPreference extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'sets_config',
        'user_id',
    ];

    protected $casts = [
        'sets_config' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
