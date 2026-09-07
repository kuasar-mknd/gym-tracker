<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Traits\ResolvesOwnerAtRouteBinding;
use App\Services\RecommendedValuesService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $workout_id
 * @property int $exercise_id
 * @property int $user_id la copie denormalisee du proprietaire, pour qu'un index serve
 *                        filtre et ordre sans jointure ; `workout_id` reste la verite
 * @property \Illuminate\Support\Carbon|null $workout_started_at
 * @property int $order
 * @property string|null $notes
 * @property string|null $idempotency_key nomme la tentative du client qui a créé cette ligne, pour
 *                                        qu'une création rejouée la renvoie au lieu d'en fabriquer une seconde.
 *                                        Volontairement absent de $fillable : il identifie la tentative, jamais
 *                                        quelque chose qu'un payload peut poser.
 * @property-read \App\Models\Workout $workout
 * @property-read \App\Models\Exercise $exercise
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\Set> $sets
 */
class WorkoutLine extends Model
{
    /** @use HasFactory<\Database\Factories\WorkoutLineFactory> */
    use HasFactory;

    use ResolvesOwnerAtRouteBinding;

    #[\Override]
    protected $fillable = [
        'exercise_id',
        'order',
        'notes',
    ];

    /**
     * @var list<string>
     */
    #[\Override]
    protected $appends = [];

    /**
     * La seance porteuse n'est pas terminee.
     *
     * Repose sur `ownerUserId()` ayant deja etabli un proprietaire : une chaine
     * rompue rend `ended_at` nul, ce qui se lirait ici comme « en cours ». Les
     * policies posent les deux questions ensemble, dans cet ordre.
     */
    public function ownerWorkoutIsOngoing(): bool
    {
        return $this->ownershipValue('owner_ended_at') === null;
    }

    #[\Override]
    protected function ownershipPath(): string
    {
        return 'workout';
    }

    /**
     * @return array<string, string>
     */
    protected function ownershipColumns(): array
    {
        return ['owner_user_id' => 'user_id', 'owner_ended_at' => 'ended_at'];
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo<\App\Models\Workout, $this>
     */
    public function workout(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Workout::class);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo<\App\Models\Exercise, $this>
     */
    public function exercise(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Exercise::class);
    }

    /**
     * Les séries de cette ligne, dans l'ordre où elles ont été ajoutées.
     *
     * L'ordre est posé explicitement : sans ORDER BY, la base rend ce que
     * l'index qu'elle a choisi lui donne. Cette table porte
     * `sets_workout_line_id_weight_reps_index` (workout_line_id, weight, reps) à
     * côté de l'index simple sur `workout_line_id`, et quand l'optimiseur retient
     * le premier, les séries reviennent triées PAR POIDS — corriger le poids
     * d'une série la faisait donc monter ou descendre dans la liste au
     * chargement suivant. L'ordre de création est le seul ordre qu'une série
     * possède, et tous les appelants de cette relation l'affichent ainsi :
     * l'écran de séance, l'API, l'historique d'exercice et le modèle copié
     * depuis une séance.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany<\App\Models\Set, $this>
     */
    public function sets(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Set::class)->orderBy('order')->orderBy('id');
    }

    /**
     * @return array{weight: float, reps: int, distance_km: float, duration_seconds: int}
     */
    public function getRecommendedValuesAttribute(): array
    {
        if (! isset($this->attributes['recommended_values'])) {
            return app(RecommendedValuesService::class)->getRecommendedValues($this);
        }

        /** @var string|null $val */
        $val = $this->attributes['recommended_values'];
        /** @var array{weight: float, reps: int, distance_km: float, duration_seconds: int} $decoded */
        $decoded = json_decode((string) $val, true);

        return is_array($decoded) ? $decoded : ['weight' => 0.0, 'reps' => 0, 'distance_km' => 0.0, 'duration_seconds' => 0];
    }

    /**
     * @param  array{weight: float, reps: int, distance_km: float, duration_seconds: int}  $values
     */
    public function setRecommendedValuesAttribute(array $values): void
    {
        $this->attributes['recommended_values'] = json_encode($values);
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Collection<int, WorkoutLine>  $lines
     * @return array<int, array{weight: float, reps: int, distance_km: float, duration_seconds: int}>
     */
    public static function batchRecommendedValues(\Illuminate\Database\Eloquent\Collection $lines, int $userId): array
    {
        return app(RecommendedValuesService::class)->batchRecommendedValues($lines, $userId);
    }

    #[\Override]
    protected static function booted(): void
    {
        $clearCache = function (self $line): void {
            /*
             * On teste la RELATION, pas la cle etrangere.
             *
             * `workout_lines.workout_id` est NOT NULL et les identifiants
             * commencent a 1 : `if ($line->workout_id)` etait donc toujours
             * vrai. Le garde avait l'air de proteger la ligne suivante, qui
             * dereference `$line->workout` — et c'est ce dereferencement, lui,
             * qui peut echouer si la seance a disparu entre-temps.
             */
            $workout = $line->workout;

            if ($workout === null) {
                return;
            }

            app(\App\Services\ActiveWorkoutService::class)->forget($workout->user_id);
        };

        /*
         * La copie denormalisee, posee avant l'ecriture.
         *
         * `user_id` et `workout_started_at` ne sont pas la verite — `workout_id`
         * l'est. Ils existent pour qu'un index puisse servir a la fois le filtre
         * et l'ordre de « la derniere fois que cet utilisateur a fait cet
         * exercice », question qui sinon materialise toute la jointure.
         */
        static::saving(function (self $line): void {
            $seance = $line->workout;

            if ($seance === null) {
                return;
            }

            $line->user_id = $seance->user_id;
            $line->workout_started_at = $seance->started_at;
        });

        static::saved($clearCache);
        static::deleted($clearCache);

        /**
         * Rend le volume des séries que cette ligne va emporter avec elle.
         *
         * `sets.workout_line_id` est en ON DELETE CASCADE : la base efface ces
         * lignes elle-même et Eloquent n'en entend jamais parler — `Set::deleted`
         * ne part pas, et le volume qu'elles apportaient reste pour de bon dans
         * `users.total_volume` et `workouts.workout_volume`. Chaque exercice
         * retiré d'une séance gonflait ces deux compteurs depuis toujours.
         *
         * Sommé en une requête et rendu une seule fois, plutôt que de supprimer
         * chaque série par le modèle : les lignes partent de toute façon, et les
         * compteurs ne s'intéressent qu'au total.
         */
        static::deleted(function (self $line): void {
            $line->workout?->recomputeVolume();
        });
    }

    #[\Override]
    protected function casts(): array
    {
        return [
            'order' => 'integer',
            'workout_started_at' => 'datetime',
        ];
    }
}
