<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\MacroCalculation;
use App\Models\User;

class MacroCalculationPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, MacroCalculation $macroCalculation): bool
    {
        return $user->id === $macroCalculation->user_id;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, MacroCalculation $macroCalculation): bool
    {
        return $user->id === $macroCalculation->user_id;
    }

    public function delete(User $user, MacroCalculation $macroCalculation): bool
    {
        return $user->id === $macroCalculation->user_id;
    }

    public function restore(User $user, MacroCalculation $macroCalculation): bool
    {
        return $user->id === $macroCalculation->user_id;
    }

    public function forceDelete(User $user, MacroCalculation $macroCalculation): bool
    {
        return $user->id === $macroCalculation->user_id;
    }
}
