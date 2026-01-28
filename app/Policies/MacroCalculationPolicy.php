<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\MacroCalculation;
use App\Models\User;

class MacroCalculationPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, MacroCalculation $macroCalculation): bool
    {
        return $user->id === $macroCalculation->user_id;
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, MacroCalculation $macroCalculation): bool
    {
        return $user->id === $macroCalculation->user_id;
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, MacroCalculation $macroCalculation): bool
    {
        return $user->id === $macroCalculation->user_id;
    }
}
