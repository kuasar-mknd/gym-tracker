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
     * Create a new set for a workout line.
     *
     * @param  array<string, mixed>  $data
     */
    public function execute(User $user, WorkoutLine $workoutLine, array $data): Set
    {
        $key = self::idempotencyKey($data);

        /**
         * Scoped to the line, never searched globally. The key arrives in a
         * client-controlled header, and a global lookup would hand back another
         * user's set to anyone who guessed or replayed their key. The line here
         * has already been authorised for this user.
         */
        if ($key !== null) {
            $existing = $workoutLine->sets()->where('idempotency_key', $key)->first();

            if ($existing instanceof Set) {
                return $existing;
            }
        }

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

        $attributs['order'] ??= $dernierRang === null ? 0 : $dernierRang + 1;

        $set = $workoutLine->sets()->make(
            $attributs
        );
        $set->idempotency_key = $key;

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
     * @param  array<string, mixed>  $data
     */
    public static function idempotencyKey(array $data): ?string
    {
        $key = $data['idempotency_key'] ?? null;

        return is_string($key) && $key !== '' && mb_strlen($key) <= 64 ? $key : null;
    }
}
