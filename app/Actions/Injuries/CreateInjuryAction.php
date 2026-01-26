<?php

declare(strict_types=1);

namespace App\Actions\Injuries;

use App\Models\Injury;
use App\Models\User;

final class CreateInjuryAction
{
    /**
     * @param array<string, mixed> $data
     */
    public function execute(User $user, array $data): Injury
    {
        /** @var Injury */
        return $user->injuries()->create($data);
    }
}
