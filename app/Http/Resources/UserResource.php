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
            // Les deux reglages du minuteur de repos voyagent ensemble : un
            // client qui recoit l'un sans l'autre ne peut pas rendre l'ecran.
            'default_rest_time' => $this->default_rest_time,
            'auto_rest_timer' => $this->auto_rest_timer,
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
