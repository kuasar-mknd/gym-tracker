<?php

declare(strict_types=1);

namespace App\Actions\Workouts;

use App\Models\Set;
use App\Models\User;
use App\Models\WorkoutLine;
use App\Services\Stats\StatsCacheManager;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Arr;

final class CreateSetAction
{
    public function __construct(protected StatsCacheManager $statsCache)
    {
    }

    /**
     * La série que la ligne porte déjà sous la clé de la tentative, rien sans clé.
     *
     * Scoped to the line, never searched globally. The key arrives in a
     * client-controlled header, and a global lookup would hand back another
     * user's set to anyone who guessed or replayed their key. The caller has
     * already established that the line belongs to this user.
     *
     * @param  array<string, mixed>  $data
     */
    public function serieDejaCreee(WorkoutLine $workoutLine, array $data): ?Set
    {
        $key = self::idempotencyKey($data);

        return $key === null ? null : $workoutLine->sets()->where('idempotency_key', $key)->first();
    }

    /**
     * Create a new set for a workout line.
     *
     * `$dejaCherchee` : l'appelant a déjà cherché la série que la clé a créée
     * (`StoreSetAction`) ; l'index unique tranche une course.
     *
     * @param  array<string, mixed>  $data
     */
    public function execute(User $user, WorkoutLine $workoutLine, array $data, bool $dejaCherchee = false): Set
    {
        $existing = $dejaCherchee ? null : $this->serieDejaCreee($workoutLine, $data);

        if ($existing instanceof Set) {
            return $existing;
        }

        $key = self::idempotencyKey($data);

        /**
         * La forme est declaree parce que rien ne la porte jusqu'ici.
         *
         * `collect($data)->except(...)->toArray()` — ce qui etait ecrit — rend
         * un `array<int|string, mixed>` : la traversee par une collection perd
         * la garantie que les cles sont textuelles, que la forme du parametre
         * donne pourtant. `Arr::except` ne la perd pas moins : son stub Laravel
         * rend un `array` nu. `create()` et `make()` exigent
         * `array<string, mixed>`, d'ou une entree de baseline par appel — cinq
         * en tout.
         *
         * L'annotation dit ce que la signature garantit deja, elle n'affirme
         * rien de neuf.
         *
         * @var array<string, mixed> $attributs
         */
        $attributs = Arr::except($data, ['workout_line_id', 'idempotency_key']);

        /*
         * Une serie nait EN DERNIER. La colonne vaut zero par defaut : sans ce
         * rang, chaque serie ajoutee se placerait en tete de l'exercice.
         */
        /** @var int|null $dernierRang */
        $dernierRang = $workoutLine->sets()->max('order');

        $attributs['order'] ??= ($dernierRang ?? -1) + 1;

        $set = $workoutLine->sets()->make(
            $attributs
        );
        $set->idempotency_key = $key;
        // La ligne est déjà en mémoire : les écouteurs de la série ne la relisent pas.
        $set->setRelation('workoutLine', $workoutLine);

        try {
            $set->save();
        } catch (UniqueConstraintViolationException $e) {
            // Two replays of the same attempt raced and the index settled it.
            $winner = $workoutLine->sets()->where('idempotency_key', $key)->first();

            if (! $winner instanceof Set) {
                throw $e;
            }

            return $winner;
        }

        // Bolt: Only clear volume-related stats for set additions
        $this->statsCache->clearVolumeStats($user);

        return $set;
    }

    /**
     * La clé de la tentative, lue dans un en-tête que rien ne valide : de 1 à
     * 64 caractères ASCII imprimables, sans espace, ce qu'écrit le client
     * (un UUID). Toute autre est ignorée, et la création a lieu sans clé :
     * un octet hors UTF-8 (un « é » qu'un navigateur envoie en latin-1)
     * allait jusqu'à la colonne, que MySQL refuse, et la création répondait
     * 500, que la file hors ligne réessaie (#2071).
     *
     * @param  array<string, mixed>  $data
     */
    public static function idempotencyKey(array $data): ?string
    {
        $key = $data['idempotency_key'] ?? null;

        return is_string($key) && preg_match('/^[\x21-\x7E]{1,64}$/D', $key) === 1 ? $key : null;
    }
}
