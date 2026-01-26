<?php

declare(strict_types=1);

namespace App\Actions\Injuries;

use App\Models\Injury;

final class UpdateInjuryAction
{
    /**
     * @param array<string, mixed> $data
     */
    public function execute(Injury $injury, array $data): Injury
    {
        if (
            isset($data['status']) &&
            $data['status'] === 'healed' &&
            $injury->status !== 'healed' &&
            empty($data['healed_at'])
        ) {
            $data['healed_at'] = now();
        }

        $injury->update($data);

        return $injury;
    }
}
