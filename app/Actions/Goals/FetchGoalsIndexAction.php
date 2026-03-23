<?php

declare(strict_types=1);

namespace App\Actions\Goals;

use App\Models\Exercise;
use App\Models\User;

class FetchGoalsIndexAction
{
    /**
     * @return array<string, mixed>
     */
    public function execute(User $user): array
    {
        return [
            'goals' => $user->goals()
                ->with('exercise')
                ->latest()
                ->get()
                ->append(['progress', 'unit']),
            'exercises' => Exercise::getCachedForUser($user->id),
            'measurementTypes' => [
                ['value' => 'weight', 'label' => 'Poids de corps'],
                ['value' => 'waist', 'label' => 'Tour de taille'],
                ['value' => 'body_fat', 'label' => 'Masse grasse (%)'],
                ['value' => 'chest', 'label' => 'Tour de poitrine'],
                ['value' => 'arms', 'label' => 'Tour de bras'],
            ],
        ];
    }
}
