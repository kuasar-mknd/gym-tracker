<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ExerciseCategory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * @property int $id
 * @property int|null $user_id
 * @property string $name
 * @property string $type
 * @property ExerciseCategory $category
 * @property int|null $default_rest_time
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\WorkoutLine> $workoutLines
 * @property-read \App\Models\User|null $user
 *
 * @method static \Illuminate\Database\Eloquent\Builder|Exercise forUser(int $idUtilisateur)
 */
class Exercise extends Model
{
    /** @use HasFactory<\Database\Factories\ExerciseFactory> */
    use HasFactory;

    #[\Override]
    protected $fillable = ['name', 'type', 'category', 'default_rest_time'];

    /**
     * @return \Illuminate\Database\Eloquent\Relations\HasMany<\App\Models\WorkoutLine, $this>
     */
    public function workoutLines(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(WorkoutLine::class);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo<\App\Models\User, $this>
     */
    public function user(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Les exercices du catalogue commun et ceux de cet utilisateur.
     */
    /**
     * @param  \Illuminate\Database\Eloquent\Builder<$this>  $query
     * @return \Illuminate\Database\Eloquent\Builder<$this>
     */
    public function scopeForUser(Builder $query, int $idUtilisateur): Builder
    {
        return $query->where(fn ($q) => $q->whereNull('user_id')->orWhere('user_id', $idUtilisateur));
    }

    /**
     * La liste des exercices de l'utilisateur, mise en cache une heure. Un seul
     * point d'entrée, pour qu'une seule clef soit à invalider.
     *
     * @return Collection<int, Exercise>
     */
    public static function enCachePourUtilisateur(int $idUtilisateur): Collection
    {
        return Cache::remember(
            self::cleDeListe($idUtilisateur),
            3600,
            fn () => self::forUser($idUtilisateur)
                ->orderBy('category')
                ->orderBy('name')
                ->get()
        );
    }

    /**
     * Vide la liste en cache du propriétaire de cet exercice.
     *
     * Un exercice du catalogue commun apparaît dans la liste de tout le monde :
     * plutôt que d'énumérer les utilisateurs, la révision du catalogue avance
     * d'un cran et toutes les clefs deviennent caduques d'un coup.
     */
    public function invalidateCache(): void
    {
        if ($this->user_id !== null) {
            Cache::forget(self::cleDeListe($this->user_id));

            return;
        }

        Cache::forever('exercises_catalogue_revision', self::revisionDuCatalogue() + 1);
    }

    /**
     * La revision du catalogue partage, un compteur et non une horloge.
     *
     * `time()` marquait deux modifications faites dans la meme seconde de la
     * meme valeur : la seconde n'invalidait plus rien, et les listes deja
     * reconstruites entre les deux restaient servies une heure durant.
     */
    private static function revisionDuCatalogue(): int
    {
        $revision = Cache::get('exercises_catalogue_revision', 0);

        return is_numeric($revision) ? (int) $revision : 0;
    }

    private static function cleDeListe(int $idUtilisateur): string
    {
        return 'exercices_liste_'.$idUtilisateur.'_r'.self::revisionDuCatalogue();
    }

    #[\Override]
    protected function casts(): array
    {
        return [
            'category' => ExerciseCategory::class,
            'default_rest_time' => 'integer',
        ];
    }

    #[\Override]
    protected static function booted(): void
    {
        parent::booted();

        static::saved(fn (Exercise $exercise) => $exercise->invalidateCache());
        static::deleted(fn (Exercise $exercise) => $exercise->invalidateCache());
    }
}
