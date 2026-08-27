<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use App\Models\Workout;
use Illuminate\Support\Facades\Cache;

/**
 * La séance ouverte d'un utilisateur, lue une fois par requête.
 *
 * Trois endroits posaient la même question et connaissaient la même clé de
 * cache. Deux d'entre eux la posaient dans la même requête HTTP.
 */
final class ActiveWorkoutService
{
    public function for(User $user): ?Workout
    {
        /*
         * L'absence est mise en cache sous une enveloppe.
         *
         * `Cache::remember()` rend le cache seulement si la valeur n'est pas
         * nulle (`Repository::rememberWithWarmth`). Un `null` stocké est donc
         * indiscernable d'une absence, et la requête repartait à CHAQUE requête
         * web — précisément dans le cas courant, celui où aucune séance n'est
         * ouverte.
         */
        $enveloppe = Cache::remember(
            self::cle($user->id),
            now()->addHours(2),
            fn (): array => ['seance' => self::chercher($user)],
        );

        if (! is_array($enveloppe)) {
            return null;
        }

        $seance = $enveloppe['seance'] ?? null;

        return $seance instanceof Workout ? $seance : null;
    }

    private static function chercher(User $user): ?Workout
    {
        return $user->workouts()
            ->select('id', 'name', 'started_at')
            ->whereNull('ended_at')
            ->withCount('workoutLines')
            ->latest('started_at')
            ->first();
    }

    public function forget(int $userId): void
    {
        Cache::forget(self::cle($userId));
    }

    private static function cle(int $userId): string
    {
        return "user_active_workout_{$userId}";
    }
}
