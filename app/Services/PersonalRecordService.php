<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\PersonalRecord;
use App\Models\Set;
use App\Models\User;
use App\Notifications\PersonalRecordAchieved;
use App\Traits\CalculatesOneRepMax;

/**
 * Service for managing Personal Records (PRs).
 *
 * This service calculates and updates a user's personal records (e.g., max weight,
 * max 1RM, max volume set) after a workout set is completed. It also handles
 * dispatching notifications when new records are achieved.
 */
final class PersonalRecordService
{
    use CalculatesOneRepMax;

    /**
     * Synchronize personal records based on a completed set.
     *
     * Evaluates the given set against the user's existing personal records for the
     * associated exercise. If the set establishes a new record for any tracked metric,
     * the corresponding PersonalRecord is created or updated.
     *
     * @param  \App\Models\Set  $set  The workout set to evaluate for potential PRs.
     * @param  \App\Models\User|null  $user  The user who performed the set (optional, resolved from set if null).
     */
    public function syncSetPRs(Set $set, ?User $user = null): void
    {
        if ($this->shouldSkipSync($set)) {
            return;
        }

        $set->loadMissing(['workoutLine.workout.user', 'workoutLine.exercise']);

        /*
         * Trois gardes sont parties d'ici : `! $workout`, `! $user` et
         * `! $exerciseId`. Aucune ne pouvait se declencher.
         *
         * `sets.workout_line_id`, `workout_lines.workout_id`,
         * `workout_lines.exercise_id` et `workouts.user_id` sont toutes NOT NULL
         * et porteuses d'une contrainte de cle etrangere : la chaine ne peut pas
         * rendre null, et un `exercise_id` a 0 ne reference rien.
         *
         * Le `! $user` se refutait tout seul : `loadMissing()` etait appele sur
         * `$user` a la ligne precedente. Si la valeur avait pu etre nulle, on
         * aurait plante avant d'arriver au test cense l'empecher.
         *
         * Les gardes homologues de `refreshFor()` restent, elles : cette
         * methode-la s'execute sur `deleted`, ou la ligne parente peut avoir
         * disparu malgre la contrainte — c'est le defaut corrige en #1476.
         */
        $user ??= $set->workoutLine->workout->user;
        $user->loadMissing('notificationPreferences');

        $this->processUpdates($user, $set->workoutLine->exercise_id, $set);
    }

    /**
     * Create or update a specific personal record type.
     *
     * Compares the new value against the existing record (if any). If the new value
     * is greater, it persists the new record and optionally sends a notification
     * to the user if they have PR notifications enabled.
     *
     * @param  \App\Models\User  $user  The user achieving the PR.
     * @param  int  $exerciseId  The ID of the exercise.
     * @param  string  $type  The type of PR (e.g., 'max_weight', 'max_1rm', 'max_volume_set').
     * @param  float  $value  The primary value of the new record.
     * @param  float|null  $secondary  An optional secondary value (e.g., reps associated with max weight).
     * @param  \App\Models\Set  $set  The set that achieved this record.
     * @param  \App\Models\PersonalRecord|null  $pr  The existing personal record, if any.
     */
    protected function update(User $user, int $exerciseId, string $type, float $value, ?float $secondary, Set $set, ?PersonalRecord $pr): void
    {
        if ($pr !== null && $value <= $pr->value) {
            return;
        }

        $pr ??= new PersonalRecord(['user_id' => $user->id, 'exercise_id' => $exerciseId, 'type' => $type]);
        /*
         * `secondary_value` et `workout_id` sont ecrases juste apres.
         *
         * `refreshRecordsHeldBy()` s'execute dans la meme sauvegarde de serie
         * (AppServiceProvider::registerSetEvents) et passe par `recompute()`,
         * qui remplit les memes cinq champs ligne 179. Les retirer d'ici ne
         * change donc rien d'observable, ce qui rend leurs mutants equivalents
         * plutot que non couverts — un test qui pretendrait les tuer verifierait
         * en realite l'ecriture de l'autre chemin.
         *
         * Ils restent la parce que `update()` doit se tenir seul : rien ne
         * garantit que le second chemin l'accompagnera toujours.
         *
         * @pest-mutate-ignore RemoveArrayItem
         */
        $pr->fill(['value' => $value, 'secondary_value' => $secondary, 'workout_id' => $set->workoutLine->workout_id, 'set_id' => $set->id, 'achieved_at' => now()])->save();

        if ($user->isNotificationEnabled('personal_record')) {
            $user->notify(new PersonalRecordAchieved($pr));
        }
    }

    /**
     * Determine if a set should be excluded from PR evaluation.
     *
     * Warmup sets, or sets missing either weight or reps, are not considered
     * valid for setting personal records.
     *
     * @param  \App\Models\Set  $set  The set to check.
     * @return bool True if the set should be skipped, false otherwise.
     */
    private function shouldSkipSync(Set $set): bool
    {
        /*
         * `! $set->weight` etait vrai pour null comme pour zero. Les deux cas
         * doivent bien etre ignores — une serie a zero kilo ou zero repetition
         * ne produit que des records nuls — mais le code disait « non renseigne »
         * en pensant « nul ou zero ». Un poids negatif, lui, passait.
         */
        return $set->is_warmup
            || $set->weight === null || $set->weight <= 0.0
            || $set->reps === null || $set->reps <= 0;
    }

    /**
     * Rebuilds an exercise's records from the sets that actually exist.
     *
     * update() only ever raises a record, and nothing ever lowered one. A single
     * mistyped weight — 500 for 50 — became that exercise's personal record
     * permanently: correcting the set did nothing, deleting it did nothing, and
     * the figure stayed on the user's profile for good.
     *
     * Only called when the set behind a record changes or goes, so the cost is
     * paid on the rare event rather than on every save.
     *
     * @param  list<string>|null  $types  Limit the rebuild to these records. Null
     *                                    means all of them, which is right when a set has gone and there is no
     *                                    longer any way to know which records it was holding.
     */
    /** @var list<string> */
    private const array TYPES = ['max_weight', 'max_1rm', 'max_volume_set'];

    /**
     * Rebuilds an exercise's records from the sets that actually exist.
     *
     * update() only ever raises a record, and nothing ever lowered one. A single
     * mistyped weight — 500 for 50 — became that exercise's personal record
     * permanently: correcting the set did nothing, deleting it did nothing, and
     * the figure stayed on the user's profile for good.
     *
     * @param  list<string>|null  $types  Limit the rebuild to these records. Null
     *                                    means all of them, which is right when a set has gone and there is no
     *                                    longer any way to know which records it was holding.
     */
    public function recompute(User $user, int $exerciseId, ?array $types = null): void
    {
        $records = PersonalRecord::query()
            ->where('user_id', $user->id)
            ->where('exercise_id', $exerciseId)
            ->get()
            ->keyBy(fn (PersonalRecord $record): string => $record->type->value);

        foreach (self::TYPES as $type) {
            if ($types !== null && ! in_array($type, $types, true)) {
                continue;
            }

            /** @var PersonalRecord|null $record */
            $record = $records->get($type);
            $meilleure = $this->meilleureSerie($user, $exerciseId, $type);

            if (! $meilleure instanceof Set) {
                // Nothing left that qualifies; the record no longer stands.
                $record?->delete();

                continue;
            }

            [$valeur, $secondaire] = $this->mesurer($type, $meilleure);
            $seanceId = $meilleure->getAttribute('workout_id');

            $record ??= new PersonalRecord(['user_id' => $user->id, 'exercise_id' => $exerciseId, 'type' => $type]);

            /**
             * No notification here. This is a correction, not an achievement —
             * telling someone they have set a personal record because they just
             * fixed a typo would be worse than saying nothing.
             */
            $record->fill([
                'value' => $valeur,
                'secondary_value' => $secondaire,
                'workout_id' => is_numeric($seanceId) ? (int) $seanceId : null,
                'set_id' => $meilleure->id,
                'achieved_at' => $meilleure->created_at ?? now(),
            ])->save();
        }
    }

    /** A egalite, la premiere serie : `sets.id` croissant, plutot que l'ordre du moteur. */
    private function meilleureSerie(User $user, int $exerciseId, string $type): ?Set
    {
        $requete = Set::query()
            ->join('workout_lines', 'workout_lines.id', '=', 'sets.workout_line_id')
            ->join('workouts', 'workouts.id', '=', 'workout_lines.workout_id')
            ->where('workouts.user_id', $user->id)
            ->where('workout_lines.exercise_id', $exerciseId)
            ->where('sets.is_warmup', false)
            ->where('sets.weight', '>', 0)
            ->where('sets.reps', '>', 0)
            ->select(['sets.id', 'sets.weight', 'sets.reps', 'sets.created_at', 'workout_lines.workout_id']);

        $ordonnee = match ($type) {
            'max_weight' => $requete->orderByDesc('sets.weight'),
            'max_1rm' => $requete->orderByRaw('case when sets.reps <= 1 then sets.weight else sets.weight * (1 + sets.reps / 30) end desc'),
            default => $requete->orderByRaw('sets.weight * sets.reps desc'),
        };

        return $ordonnee->orderBy('sets.id')->first();
    }

    /** Retient ce que la serie detient, tant que la base le sait encore. */
    public function retenirTypesDetenus(Set $set): void
    {
        $set->setAttribute('records_detenus', $this->typesDetenusPar($set));
    }

    /** @return list<string>|null */
    public function typesRetenus(Set $set): ?array
    {
        $retenus = $set->getAttribute('records_detenus');

        if (! is_array($retenus)) {
            return null;
        }

        return array_values(array_filter($retenus, is_string(...)));
    }

    /**
     * @return array{0: float, 1: float|null}
     */
    private function mesurer(string $type, Set $set): array
    {
        return match ($type) {
            'max_weight' => [(float) $set->weight, (float) $set->reps],
            'max_1rm' => [$this->calculate1RM((float) $set->weight, (int) $set->reps), (float) $set->weight],
            default => [(float) $set->weight * (int) $set->reps, null],
        };
    }

    /**
     * Recomputes only when the set that changed is the one a record points at.
     *
     * Safe to call on every save: the lookup is a single indexed existence
     * check, and almost every save is of a set holding nothing.
     */
    public function refreshRecordsHeldBy(Set $set, ?User $user = null): void
    {
        /**
         * Only the records this set is actually holding. Rebuilding all three
         * would reach past the change and reset records that nothing about this
         * set affects — a set that beats the 1RM but not the max weight would
         * drag the max weight down with it.
         */
        /*
         * Les valeurs de l'enumeration, pas ses instances : `type` est cast, donc
         * une comparaison stricte contre les cles textuelles plus bas ne
         * correspondrait jamais et la reconstruction ne selectionnerait
         * silencieusement rien.
         *
         * Le detour defensif qui etait ici — `instanceof BackedEnum`, sinon
         * `is_string`, sinon chaine vide — supposait que `pluck()` puisse rendre
         * autre chose qu'une instance. Mesure faite : il applique le cast et rend
         * toujours un `PersonalRecordType`. La moitie de l'aiguillage etait donc
         * morte, avec ses quatre mutants, et le `filter()` qui ecartait la chaine
         * vide n'avait rien a ecarter.
         */
        $types = $this->typesDetenusPar($set);

        if ($types === []) {
            return;
        }

        $this->refreshFor($set, $user, $types);
    }

    /** @return list<string> */
    public function typesDetenusPar(Set $set): array
    {
        return array_values(
            PersonalRecord::query()
                ->where('set_id', $set->id)
                ->get(['type'])
                ->map(fn (PersonalRecord $record): string => $record->type->value)
                ->all()
        );
    }

    /**
     * Recomputes unconditionally, for when the set is on its way out.
     *
     * The gate above cannot be used after a deletion: personal_records.set_id is
     * resolved by the database the moment the row goes, so by the time the
     * `deleted` event fires nothing still admits the set held anything.
     */
    /**
     * @param  list<string>|null  $types
     */
    public function refreshFor(Set $set, ?User $user = null, ?array $types = null): void
    {
        $set->loadMissing(['workoutLine.workout.user']);
        $exerciseId = $set->workoutLine?->exercise_id;
        $user ??= $set->workoutLine?->workout?->user;

        if (! $user instanceof User || $exerciseId === null) {
            return;
        }

        $this->recompute($user, (int) $exerciseId, $types);
    }

    /**
     * Process all tracked PR metrics for a valid set.
     *
     * Retrieves existing PRs for the user and exercise, then evaluates the set
     * against each tracked metric (max weight, estimated 1RM, max volume per set).
     *
     * @param  \App\Models\User  $user  The user who performed the set.
     * @param  int  $exerciseId  The ID of the exercise.
     * @param  \App\Models\Set  $set  The completed valid set.
     */
    private function processUpdates(User $user, int $exerciseId, Set $set): void
    {
        $existingPRs = PersonalRecord::where('user_id', $user->id)
            ->where('exercise_id', $exerciseId)
            ->get()
            ->keyBy('type');

        $this->update($user, $exerciseId, 'max_weight', (float) $set->weight, (float) $set->reps, $set, $existingPRs->get('max_weight'));
        $this->update($user, $exerciseId, 'max_1rm', $this->calculate1RM((float) $set->weight, (int) $set->reps), (float) $set->weight, $set, $existingPRs->get('max_1rm'));
        $this->update($user, $exerciseId, 'max_volume_set', (float) $set->weight * (int) $set->reps, null, $set, $existingPRs->get('max_volume_set'));
    }
}
