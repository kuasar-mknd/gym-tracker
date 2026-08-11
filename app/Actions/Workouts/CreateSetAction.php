<?php

declare(strict_types=1);

namespace App\Actions\Workouts;

use App\Models\Set;
use App\Models\User;
use App\Models\WorkoutLine;
use App\Services\StatsService;
use Illuminate\Database\UniqueConstraintViolationException;

final class CreateSetAction
{
    public function __construct(protected StatsService $statsService)
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

        $set = $workoutLine->sets()->make(
            collect($data)->except(['workout_line_id', 'idempotency_key'])->toArray()
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
        $this->statsService->clearVolumeStats($user);

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
