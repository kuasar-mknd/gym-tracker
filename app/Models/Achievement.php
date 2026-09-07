<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * @property int $id
 * @property string $slug
 * @property string $name
 * @property string $description
 * @property string $icon
 * @property string $type
 * @property float $threshold
 * @property string $category
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\User> $users
 */
class Achievement extends Model
{
    /** @use HasFactory<\Database\Factories\AchievementFactory> */
    use HasFactory;

    #[\Override]
    protected $fillable = [
        'slug',
        'name',
        'description',
        'icon',
        'type',
        'threshold',
        'category',
    ];

    /**
     * Tous les succès, mis en cache.
     *
     * Les pages les plus fréquentées — tableau de bord, séances — relisent la
     * liste à chaque affichage : le cache ramène le nombre de requêtes de O(N) à
     * O(1).
     *
     * @return Collection<int, Achievement>
     */
    public static function getCachedAll(): Collection
    {
        return Cache::rememberForever('achievements_all', fn (): Collection => self::all());
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany<\App\Models\User, $this, \Illuminate\Database\Eloquent\Relations\Pivot, 'pivot'>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_achievements')
            ->withPivot('achieved_at')
            ->withTimestamps();
    }

    #[\Override]
    protected static function booted(): void
    {
        parent::booted();

        static::saved(function (Achievement $achievement): void {
            Cache::forget('achievements_all');
        });

        static::deleted(function (Achievement $achievement): void {
            Cache::forget('achievements_all');
        });
    }
}
