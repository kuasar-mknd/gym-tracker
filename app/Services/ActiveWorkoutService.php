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

    /**
     * La séance porte sa clé d'idempotence (#2049) : la page reconnaît ainsi,
     * dans le bandeau de la séance en cours, une séance démarrée sans
     * réponse dont la création attend encore dans sa file.
     */
    private static function chercher(User $user): ?Workout
    {
        return $user->workouts()
            ->select('id', 'name', 'started_at', 'idempotency_key')
            ->whereNull('ended_at')
            ->withCount('workoutLines')
            ->latest('started_at')
            ->first();
    }

    public function forget(int $idUtilisateur): void
    {
        Cache::forget(self::cle($idUtilisateur));
    }

    /**
     * Versionnée par la forme de ce qu'elle garde : une entrée de deux heures
     * écrite avant que la séance porte sa clé (#2049) servirait sinon
     * l'ancienne forme, sans `idempotency_key`, jusqu'à son expiration.
     */
    private static function cle(int $idUtilisateur): string
    {
        return "user_active_workout_v2_{$idUtilisateur}";
    }
}
