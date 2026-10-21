<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * @property int $id
 * @property int $user_id
 * @property string|null $name
 * @property float $workout_volume
 * @property \Illuminate\Support\Carbon $started_at
 * @property \Illuminate\Support\Carbon|null $ended_at
 * @property string|null $notes
 * @property string|null $idempotency_key nomme le démarrage que l'appareil a tiré (#2049), pour qu'un
 *                                        démarrage rejoué par la file rende la même séance. Unique par
 *                                        compte, posée à la main, hors de $fillable comme sur les lignes
 *                                        et les séries : elle nomme la tentative, jamais un champ du corps.
 * @property-read \App\Models\User $user
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\WorkoutLine> $workoutLines
 */
class Workout extends Model
{
    /** @use HasFactory<\Database\Factories\WorkoutFactory> */
    use HasFactory;

    /**
     * Combien de jours un geste fait sur l'appareil peut précéder le moment où
     * le serveur le reçoit : la vie d'une écriture dans la file hors ligne
     * (`JOURS_EN_FILE_AU_PLUS`, resources/js/Utils/ecrituresAbandonnees.js).
     * Au-delà, la file l'a abandonnée, et un geste plus ancien ne vient pas
     * d'elle.
     */
    public const int JOURS_EN_FILE_AU_PLUS = 30;

    /**
     * Le début d'une séance que l'appareil a démarrée : maintenant, moins
     * l'âge du geste, s'il a entre zéro et `JOURS_EN_FILE_AU_PLUS` jours ;
     * sinon maintenant (#2049).
     *
     * La file rejoue un démarrage des heures après le geste : l'heure du
     * rejeu ferait commencer la séance après ses propres séries. L'âge, en
     * secondes, est celui du geste au départ de la requête, lu sur la seule
     * horloge de l'appareil (`App\Support\HeureDeLAppareil`) : un décalage de
     * cette horloge s'y annule, quand l'instant lu tel quel faisait commencer
     * la séance trois heures trop tôt sur un appareil en retard de trois
     * heures (relu le 2026-10-21). Un âge négatif (un geste qui suivrait son
     * envoi), ou plus vieux que tout ce que la file garde, revient à
     * maintenant, comme un démarrage sans heure. Refuser la séance la
     * perdrait, et ses séries avec elle.
     *
     * Le début et la borne viennent de `$maintenant`, la lecture unique de
     * l'horloge de l'opération (`.ai/rules/horloge.md`) ; la valeur rendue est
     * une copie (`Carbon::instance()`), jamais l'instant reçu.
     */
    public static function debutBorne(?int $ageDuGeste, CarbonInterface $maintenant): Carbon
    {
        if ($ageDuGeste === null || $ageDuGeste < 0) {
            return Carbon::instance($maintenant);
        }

        $debut = $maintenant->copy()->subSeconds($ageDuGeste);

        return Carbon::instance($debut->lessThan($maintenant->copy()->subDays(self::JOURS_EN_FILE_AU_PLUS)) ? $maintenant : $debut);
    }

    /**
     * La fin de cette séance : maintenant, moins l'âge du geste
     * (`debutBorne()`), ou maintenant sans lui ; jamais après maintenant,
     * jamais avant le début (#2049).
     *
     * Un âge négatif fermerait la séance à une heure qui n'est pas venue :
     * elle se ferme maintenant. Une fin d'avant le début donnerait une durée
     * négative. Quand les deux bornes se contredisent, le début l'emporte :
     * seul un début futur les fait se contredire, que ni le démarrage
     * (`debutBorne()`) ni les réglages (`UpdateWorkoutRequest`) n'écrivent
     * plus, mais que le panneau peut poser ou qu'une séance plus ancienne peut
     * porter ; elle se ferme alors à ce début, avec une durée nulle. Une fin
     * rejouée longtemps après garde l'heure du geste.
     */
    public function finBornee(?int $ageDuGeste, CarbonInterface $maintenant): Carbon
    {
        $fin = $ageDuGeste !== null && $ageDuGeste > 0
            ? $maintenant->copy()->subSeconds($ageDuGeste)
            : $maintenant;

        return Carbon::instance($fin->lessThan($this->started_at) ? $this->started_at : $fin);
    }

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
     * Les exercices de cette séance, dans l'ordre où ils ont été ajoutés.
     *
     * `order` seul ne tranche pas : la colonne vaut 0 par défaut, donc toutes
     * les lignes écrites avant que `CreateWorkoutLineAction` n'attribue max+1
     * partagent la même valeur et leur ordre relatif est celui qui arrange la
     * base. `id` les départage de la seule façon qui ait un sens ici.
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
     * Recalcule le volume de cette séance depuis ses séries, et déplace d'autant
     * le total de l'utilisateur.
     *
     * Les compteurs étaient tenus en accumulant un écart à chaque sauvegarde de
     * série : le nouveau volume moins celui que la série portait au chargement
     * du modèle. Deux requêtes touchant la même série en même temps — le poids
     * et les répétitions d'une même ligne, que l'anti-rebond envoie en deux
     * PATCH séparés — calculaient leur écart depuis le même instantané. Les
     * appliquer tous les deux éloignait les totaux des séries qu'ils prétendent
     * décrire, définitivement et sans que rien ne le montre.
     *
     * Recalculer depuis les lignes coûte un agrégat au lieu d'un incrément et ne
     * peut pas dériver, quel que soit l'ordre d'arrivée des écritures.
     */
    public function recalculerLeVolume(): void
    {
        /*
         * L'ancien total est relu SOUS VERROU dans la transaction qui l'ecrit :
         * deux enregistrements sur la meme seance se serialisent (#1595).
         * Seules les series validees comptent : demarrer une seance depuis un
         * modele ne credite rien avant le premier kilo souleve (#1499).
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
        });
    }

    #[\Override]
    protected static function booted(): void
    {
        /*
         * Le propriétaire d'une séance est fixé à sa création (#1933).
         *
         * `workout_lines.user_id` et `sets.user_id` le recopient : la ligne à
         * chacun de ses enregistrements, la série seulement quand elle change
         * de ligne (ou que sa copie est nulle), et l'insertion en masse des
         * séries d'un modèle de séance le pose à la main. Aucun de ces
         * écrivains ne propage un changement de la séance : il en faudrait un
         * de plus, comme pour `workout_started_at` ci-dessous. Changer le
         * propriétaire laissait lignes et séries à l'ancien compte, avec la
         * série de jours et les records des deux comptes. Aucun chemin de
         * l'application n'en a besoin ; ceux que le panneau ouvrait (la page
         * de modification et l'action de modification de la table) sont
         * fermés, et ce refus tient pour ceux qui viendraient. Il ne voit pas
         * les chemins qui sautent les événements : `saveQuietly()`,
         * `withoutEvents()` et le constructeur de requêtes.
         */
        static::updating(function (self $workout): void {
            if (! $workout->isDirty('user_id')) {
                return;
            }

            throw new \LogicException(sprintf(
                'Le propriétaire de la séance %d ne change pas après sa création : ses lignes, ses séries, '
                .'ses records et la série de jours des deux comptes resteraient à l’ancien (#1933).',
                $workout->id,
            ));
        });

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

        $clearCache = function (self $workout): void {
            app(\App\Services\ActiveWorkoutService::class)->forget($workout->user_id);
        };

        static::saved($clearCache);
        static::deleted($clearCache);

        static::deleting(function (self $workout): void {
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
            $idsExercices = [];

            foreach ($workout->workoutLines()->whereNotNull('exercise_id')->pluck('exercise_id') as $idExercice) {
                // Le pilote peut rendre l'identifiant en entier ou en chaine
                // selon la configuration PDO ; les deux sont acceptes plutot que
                // de parier sur l'un et de sauter silencieusement l'autre.
                if (! is_int($idExercice) && ! is_string($idExercice)) {
                    continue;
                }

                $idExercice = (int) $idExercice;

                if (! in_array($idExercice, $idsExercices, true)) {
                    $idsExercices[] = $idExercice;
                }
            }

            $workout->exerciseIdsToRecompute = $idsExercices;
        });

        static::deleted(function (self $workout): void {
            $user = $workout->user;

            if (! $user instanceof User) {
                return;
            }

            $records = app(\App\Services\PersonalRecordService::class);

            foreach ($workout->exerciseIdsToRecompute as $idExercice) {
                $records->recompute($user, $idExercice);
            }

            /*
             * Les objectifs ensuite, et ici plutôt qu'à côté des autres
             * déclencheurs dans `AppServiceProvider` : ses écouteurs, posés au
             * démarrage, tournent avant ceux du modèle, et l'objectif de charge
             * y lisait les records d'avant la reconstruction. Sans ce recalcul,
             * supprimer la séance qui avait atteint un objectif le laissait
             * atteint (#1953).
             */
            \App\Jobs\SyncUserGoals::dispatch($user);
        });
    }
}
