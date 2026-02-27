<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\UserAchievement
 */
class UserAchievementResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * Returns:
     * - `id`: The user achievement ID.
     * - `user_id`: The ID of the user who earned the achievement.
     * - `achievement_id`: The ID of the achievement definition.
     * - `achieved_at`: The date/time when the achievement was earned.
     * - `achievement`: The full achievement details (if loaded).
     * - `created_at`: The creation timestamp.
     * - `updated_at`: The last update timestamp.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'achievement_id' => $this->achievement_id,
            'achieved_at' => $this->achieved_at,
            'achievement' => new AchievementResource($this->whenLoaded('achievement')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
