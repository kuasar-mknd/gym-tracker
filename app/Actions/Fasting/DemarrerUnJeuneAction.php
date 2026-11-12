<?php

declare(strict_types=1);

namespace App\Actions\Fasting;

use App\Models\Fast;
use App\Models\User;
use Closure;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

final class DemarrerUnJeuneAction
{
    public const int VIE_DU_VERROU = 10;

    public const int ATTENTE_DU_VERROU = 10;

    public const string MESSAGE = 'Un jeûne est déjà en cours.';

    public const string MESSAGE_VERROU_PRIS = 'Une autre requête met à jour tes jeûnes. Réessaie dans un instant.';

    public static function verrouDuCompte(User $user): Lock
    {
        return Cache::lock("jeunes-du-compte:{$user->id}", self::VIE_DU_VERROU);
    }

    /**
     * Le travail sous le verrou des jeûnes du compte, ou un refus de validation
     * quand un autre l'a gardé plus de `ATTENTE_DU_VERROU` secondes.
     *
     * @template T
     *
     * @param  Closure(): T  $travail
     * @return T
     *
     * @throws ValidationException Si le verrou reste pris plus de `ATTENTE_DU_VERROU` secondes.
     */
    public static function sousLeVerrou(User $user, Closure $travail): mixed
    {
        try {
            return self::verrouDuCompte($user)->block(self::ATTENTE_DU_VERROU, $travail);
        } catch (LockTimeoutException) {
            throw ValidationException::withMessages(['base' => self::MESSAGE_VERROU_PRIS]);
        }
    }

    /**
     * Vérification et création se tiennent sous le verrou des jeûnes du compte.
     *
     * @param  array<string, mixed>  $donnees
     *
     * @throws ValidationException Si le compte a déjà un jeûne en cours, ou si le verrou reste pris plus de `ATTENTE_DU_VERROU` secondes.
     */
    public function execute(User $user, array $donnees): Fast
    {
        /** @var Fast $jeune */
        $jeune = self::sousLeVerrou($user, function () use ($user, $donnees): Fast {
            if ($user->fasts()->where('status', 'active')->exists()) {
                throw ValidationException::withMessages(['base' => self::MESSAGE]);
            }

            return $user->fasts()->create([...$donnees, 'status' => 'active']);
        });

        return $jeune;
    }
}
