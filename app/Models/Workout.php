<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

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
    use HasFactory;

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
    /**
     * Le total de l'utilisateur, refait depuis les faits.
     *
     * L'ecart applique a chaque serie est constant mais ne se corrige pas :
     * une ecriture qui contourne `recomputeVolume()` le laisse faux. Cette
     * passe-ci resomme les seances et remet le compteur d'aplomb — elle coute
     * l'historique du compte, et ne tourne donc qu'une fois par seance, quand
     * celle-ci se termine, au lieu d'une fois par serie.
     *
     * Une derive dure ainsi au plus une seance, contre une nuit auparavant.
     */
    public function recalibrerLeTotalDeLUtilisateur(): void
    {
        User::whereKey($this->user_id)->update([
            'total_volume' => DB::raw(
                '(select coalesce(sum(workout_volume), 0) from workouts where workouts.user_id = users.id)'
            ),
        ]);
    }

    public function recomputeVolume(): void
    {
        /*
         * Le total de la seance en ABSOLU, celui de l'utilisateur par l'ECART.
         *
         * `users.total_volume = (select sum(workout_volume) ...)` etait exact et
         * se recalait de lui-meme, mais il resommait TOUTES les seances du
         * compte — a chaque serie enregistree. Mesure aux compteurs
         * `Handler_read_*` : sur les 208 lectures de cette methode a 200
         * seances, 202 etaient cette seule sous-requete, contre 42 a 40
         * seances. Une seance de vingt series a dix ans d'anciennete la paie
         * vingt fois.
         *
         * L'ecart, lui, ne depend de rien. Ce qui l'avait fait deriver avant
         * #1595 n'etait pas l'accumulation mais sa forme : un SELECT puis un
         * UPDATE, sans transaction, que deux requetes concurrentes traversaient
         * en lisant le meme etat. Le client en emet par construction —
         * `Workouts/Show.vue` tient deux files d'ecriture separees.
         *
         * Ici l'ancien total est relu SOUS VERROU dans la meme transaction que
         * l'ecriture : deux enregistrements sur la meme seance se serialisent,
         * et chacun ajoute son propre ecart. La suppression d'une seance
         * procedait deja ainsi (`Workout::deleting`), les deux chemins ne se
         * contredisent donc plus.
         *
         * Ce qui reste possible, c'est une ecriture qui contourne ce chemin —
         * un `UPDATE` a la main, une migration. `app:verify-data-coherence` le
         * detecte chaque nuit et sait le reparer.
         *
         * Seules les series validees comptent. La somme portait sur TOUTES les
         * series, validees ou non : demarrer une seance depuis un modele
         * creditait aussitot le volume complet, 4 000 kg avant le premier kilo
         * souleve, acquis meme si la seance etait abandonnee. Voir #1499.
         */
        DB::transaction(function (): void {
            $stocke = $this->newQuery()
                ->whereKey($this->getKey())
                ->lockForUpdate()
                ->value('workout_volume');

            $ancien = is_numeric($stocke) ? (float) $stocke : 0.0;

            $total = (float) $this->workoutLines()
                ->join('sets', 'sets.workout_line_id', '=', 'workout_lines.id')
                ->where('sets.is_completed', true)
                ->sum(\Illuminate\Support\Facades\DB::raw('COALESCE(sets.weight, 0) * COALESCE(sets.reps, 0)'));

            // Les volumes sont des decimaux a deux chiffres : en deca, l'ecart
            // vient de l'arrondi et non d'un changement.
            $ecart = round($total - $ancien, 2);

            if ($ecart === 0.0) {
                return;
            }

            $this->newQuery()->whereKey($this->getKey())->update(['workout_volume' => $total]);

            $utilisateur = User::whereKey($this->user_id);

            if ($ecart > 0.0) {
                $utilisateur->increment('total_volume', $ecart);

                return;
            }

            $utilisateur->decrement('total_volume', -$ecart);
        });
    }

    #[\Override]
    protected static function booted(): void
    {
        /*
         * La date recopiee sur les lignes suit celle de la seance.
         *
         * `started_at` est modifiable — `UpdateWorkoutRequest` l'accepte — et
         * une copie qui ne suit pas fait repondre a l'index une question
         * perimee.
         */
        static::updated(function (self $workout): void {
            if (! $workout->wasChanged('started_at')) {
                return;
            }

            $workout->workoutLines()->update(['workout_started_at' => $workout->started_at]);
        });

        /*
         * La seance qui se termine remet le compteur d'aplomb.
         *
         * C'est le seul moment ou payer une resomme de l'historique se justifie :
         * une fois par seance et non une fois par serie.
         */
        static::updated(function (self $workout): void {
            if ($workout->wasChanged('ended_at') && $workout->ended_at !== null) {
                $workout->recalibrerLeTotalDeLUtilisateur();
            }
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
