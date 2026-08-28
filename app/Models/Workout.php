<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * @property int $id
 * @property int $user_id
 * @property string|null $name
 * @property float $workout_volume
 * @property \Illuminate\Support\Carbon $started_at
 * @property \Illuminate\Support\Carbon|null $ended_at
 * @property string|null $notes
 * @property-read \App\Models\User $user
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\WorkoutLine> $workoutLines
 */
class Workout extends Model
{
    /** @use HasFactory<\Database\Factories\WorkoutFactory> */
    use HasFactory, LogsActivity;

    /**
     * Les exercices dont les records devront etre recalcules apres la cascade.
     *
     * Releves pendant `deleting`, consommes pendant `deleted` : entre les deux,
     * la base a efface les lignes et les series, et c'est seulement a ce
     * moment-la qu'un recalcul rend le bon resultat.
     *
     * Une propriete d'instance, et non un tableau capture par une fermeture de
     * service provider : sous Octane, l'application est amorcee une fois pour
     * de nombreuses requetes, et un tel tableau fuirait de l'une a l'autre.
     *
     * @var list<int>
     */
    private array $exerciseIdsToRecompute = [];

    #[\Override]
    protected $fillable = [
        'name',
        'started_at',
        'ended_at',
        'notes',
    ];

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo<\App\Models\User, $this>
     */
    public function user(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The exercises of this session, in the order they were added.
     *
     * `order` alone does not settle it: the column defaults to 0, so every line
     * written before CreateWorkoutLineAction started assigning max+1 shares the
     * same value and their relative order is whatever the database feels like.
     * `id` breaks the tie the only way that means anything here.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany<\App\Models\WorkoutLine, $this>
     */
    public function workoutLines(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(WorkoutLine::class)->orderBy('order')->orderBy('id');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['started_at', 'ended_at', 'name'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    #[\Override]
    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
            'workout_volume' => 'float',
        ];
    }

    /**
     * Recomputes this session's volume from its sets, and moves the user's
     * lifetime total by the difference.
     *
     * The counters used to be maintained by accumulating a delta per set save:
     * new volume minus the volume that set had when the model was loaded. Two
     * requests touching the same set at once — the weight and the reps of one
     * row, which the debounce sends as separate PATCHes — each computed their
     * delta from the same snapshot. Applying both left the totals adrift from
     * the sets they claim to describe, permanently and invisibly.
     *
     * Recomputing from the rows costs one aggregate instead of an increment and
     * cannot drift, whatever order the writes land in.
     */
    public function recomputeVolume(): void
    {
        /*
         * Seules les series validees comptent.
         *
         * La somme portait sur TOUTES les series de la seance, validees ou non.
         * Demarrer une seance depuis un modele inserait ses series pre-remplies
         * et creditait aussitot le volume complet : 4 000 kg avant le premier
         * kilo souleve, et ils restaient acquis si la seance etait abandonnee.
         *
         * Le volume affiche a cote de « Total seances » se lit comme du travail
         * accompli — c'est ce qu'il compte desormais. Une serie cochee est la
         * seule preuve dont on dispose : les valeurs seules ne distinguent pas
         * une serie faite d'une serie proposee par le modele.
         *
         * Voir #1499.
         */
        $total = (float) $this->workoutLines()
            ->join('sets', 'sets.workout_line_id', '=', 'workout_lines.id')
            ->where('sets.is_completed', true)
            ->sum(\Illuminate\Support\Facades\DB::raw('COALESCE(sets.weight, 0) * COALESCE(sets.reps, 0)'));

        $this->newQuery()->whereKey($this->getKey())->update(['workout_volume' => $total]);

        /*
         * `users.total_volume` etait le dernier delta, et il derivait.
         *
         * Le total de la seance etait bien recalcule en absolu, mais celui de
         * l'utilisateur restait un `increment($delta)` calcule entre un SELECT
         * et un UPDATE sans transaction. Deux requetes concurrentes lisaient le
         * meme etat, calculaient le meme ecart, et l'appliquaient toutes les
         * deux. Le client en emet par construction : `Workouts/Show.vue` tient
         * deux files d'ecriture separees.
         *
         * Derive en somme, il ne revenait jamais ; derive en valeur, il se
         * recale de lui-meme. C'est exactement ce que fait la passe nocturne
         * `VerifyDataCoherence`, qui n'existait que pour reparer ce compteur.
         */
        User::whereKey($this->user_id)->update([
            'total_volume' => DB::raw(
                '(select coalesce(sum(workout_volume), 0) from workouts where workouts.user_id = users.id)'
            ),
        ]);
    }

    #[\Override]
    protected static function booted(): void
    {
        /*
         * La date recopiee sur les lignes suit celle de la seance.
         *
         * `started_at` est modifiable — `WorkoutUpdateRequest` l'accepte — et
         * une copie qui ne suit pas fait repondre a l'index une question
         * perimee.
         */
        static::updated(function (self $workout): void {
            if (! $workout->wasChanged('started_at')) {
                return;
            }

            $workout->workoutLines()->update(['workout_started_at' => $workout->started_at]);
        });

        $clearCache = function (self $workout): void {
            app(\App\Services\ActiveWorkoutService::class)->forget($workout->user_id);
        };

        static::saved($clearCache);
        static::deleted($clearCache);

        /**
         * Same cascade, one level up. Deleting a workout removes its lines and,
         * through them, every set — all in the database, none of it through
         * Eloquent. The line hook below never fires either, so the user's
         * lifetime total kept the volume of a session they had deleted.
         *
         * The workout already carries the aggregate, so releasing it is exact
         * and costs nothing to compute.
         */
        static::deleting(function (self $workout): void {
            /**
             * Read back from the table, not from the attribute. The counter is
             * maintained with increment(), which writes straight to the database
             * and leaves whatever instance the caller is holding behind — an
             * in-memory workout can easily still say zero.
             */
            $stored = $workout->newQuery()->whereKey($workout->getKey())->value('workout_volume');
            $volume = is_numeric($stored) ? (float) $stored : 0.0;

            if ($volume !== 0.0) {
                $workout->user?->decrement('total_volume', $volume);
            }

            /*
             * Meme cascade, meme angle mort — et cette fois ce sont les records
             * personnels qui restaient debout.
             *
             * `recompute()` est branche sur `Set::deleted` et
             * `WorkoutLine::deleted`, mais la suppression d'une seance efface
             * lignes et series EN BASE, sans qu'aucun de ces evenements ne parte.
             * Or `personal_records.set_id` et `.workout_id` sont en
             * `ON DELETE SET NULL` : la ligne de record survit, simplement
             * detachee.
             *
             * Consequence mesuree : 500 kg saisis par erreur, la seance
             * supprimee pour corriger, et le record reste a 500 kg. Il ne se
             * repare jamais tout seul — la serie suivante a 100 kg voit
             * 100 <= 500 et sort, et rien ne pointe plus vers elle. Le chiffre
             * fantome remonte aussi dans les succes, dont le seuil de poids lit
             * un `max('value')` sur ces memes lignes.
             */
            $exerciseIds = [];

            foreach ($workout->workoutLines()->whereNotNull('exercise_id')->pluck('exercise_id') as $exerciseId) {
                // Le pilote peut rendre l'identifiant en entier ou en chaine
                // selon la configuration PDO ; les deux sont acceptes plutot que
                // de parier sur l'un et de sauter silencieusement l'autre.
                if (! is_int($exerciseId) && ! is_string($exerciseId)) {
                    continue;
                }

                $exerciseId = (int) $exerciseId;

                if (! in_array($exerciseId, $exerciseIds, true)) {
                    $exerciseIds[] = $exerciseId;
                }
            }

            $workout->exerciseIdsToRecompute = $exerciseIds;
        });

        static::deleted(function (self $workout): void {
            $user = $workout->user;

            if (! $user instanceof User) {
                return;
            }

            $records = app(\App\Services\PersonalRecordService::class);

            foreach ($workout->exerciseIdsToRecompute as $exerciseId) {
                $records->recompute($user, $exerciseId);
            }
        });
    }
}
