<?php

declare(strict_types=1);

namespace App\Actions\Injuries;

use App\Models\Injury;

final class DeleteInjuryAction
{
    public function execute(Injury $injury): void
    {
        $injury->delete();
    }
}
