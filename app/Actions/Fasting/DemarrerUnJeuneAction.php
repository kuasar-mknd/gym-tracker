<?php

declare(strict_types=1);

namespace App\Actions\Fasting;

use App\Models\Fast;
use App\Models\User;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

final class DemarrerUnJeuneAction
{
    public const int VIE_DU_VERROU = 10;

    public const int ATTENTE_DU_VERROU = 10;

    public const string MESSAGE = 'Un jeûne est déjà en cours.';

    public static function verrouDuCompte(User $user): Lock
    {
        return Cache::lock("jeunes-du-compte:{$user->id}", self::VIE_DU_VERROU);
    }

    /**
     * Vérification et création se tiennent sous le verrou des jeûnes du compte.
     *
     * @param  array<string, mixed>  $donnees
     *
     * @throws ValidationException Si le compte a déjà un jeûne en cours.
     * @throws \Illuminate\Contracts\Cache\LockTimeoutException Si le verrou reste pris plus de `ATTENTE_DU_VERROU` secondes.
     */
    public function execute(User $user, array $donnees): Fast
    {
        /** @var Fast $jeune */
        $jeune = self::verrouDuCompte($user)
            ->block(self::ATTENTE_DU_VERROU, function () use ($user, $donnees): Fast {
                if ($user->fasts()->where('status', 'active')->exists()) {
                    throw ValidationException::withMessages(['base' => self::MESSAGE]);
                }

                return $user->fasts()->create([...$donnees, 'status' => 'active']);
            });

        return $jeune;
    }
}
