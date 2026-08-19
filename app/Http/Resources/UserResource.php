<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\User */
class UserResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    #[\Override]
    public function toArray(Request $request): array
    {
        /** @var \App\Models\User $utilisateur */
        $utilisateur = $this->resource;

        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'avatar' => $this->avatar,
            'stats' => [
                // Par le service, comme `HandleInertiaRequests` : la valeur
                // stockee se perime sans que personne l'ecrive.
                'current_streak' => app(\App\Services\StreakService::class)
                    ->currentStreakFor($utilisateur),
                'longest_streak' => $this->longest_streak,
                'last_workout_at' => $this->last_workout_at,
            ],
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
