<?php

declare(strict_types=1);

namespace App\Support;

use Filament\Auth\MultiFactor\MultiFactorChallenge;
use Filament\Facades\Filament;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Le défi de la connexion au panneau, avec un plafond par jour en plus de
 * celui de la minute (audit 5.4) : cinq essais par minute sur une fenêtre de
 * huit pas laissaient environ 11 % de chances par jour à qui détient le mot de
 * passe.
 */
final class DefiDeDoubleAuthentification extends MultiFactorChallenge
{
    public const int ESSAIS_PAR_JOUR = 20;

    private const int SECONDES_PAR_JOUR = 86_400;

    #[\Override]
    public function isRateLimited(Authenticatable $user): bool
    {
        return parent::isRateLimited($user)
            || RateLimiter::tooManyAttempts($this->cleDuJour($user), self::ESSAIS_PAR_JOUR);
    }

    #[\Override]
    public function hitRateLimiter(Authenticatable $user): void
    {
        parent::hitRateLimiter($user);

        RateLimiter::hit($this->cleDuJour($user), self::SECONDES_PAR_JOUR);
    }

    #[\Override]
    public function getRateLimiterAvailableInSeconds(Authenticatable $user): int
    {
        $duJour = RateLimiter::tooManyAttempts($this->cleDuJour($user), self::ESSAIS_PAR_JOUR)
            ? RateLimiter::availableIn($this->cleDuJour($user))
            : 0;

        return max(parent::getRateLimiterAvailableInSeconds($user), $duJour);
    }

    private function cleDuJour(Authenticatable $user): string
    {
        return 'filament:auth:multi_factor_challenge:rate_limit_jour:'.Filament::getUserScopedAuthIdentifier($user);
    }
}
