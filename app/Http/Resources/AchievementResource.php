<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Achievement */
class AchievementResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        // Check if users relation is loaded and has any user (which would be the current user due to controller filtering)
        $userAchievement = $this->relationLoaded('users') ? $this->users->first() : null;

        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'name' => $this->name,
            'description' => $this->description,
            'icon' => $this->icon,
            'type' => $this->type,
            'threshold' => $this->threshold,
            'category' => $this->category,
            'is_unlocked' => (bool) $userAchievement,
            // @phpstan-ignore-next-line
            'unlocked_at' => $userAchievement?->pivot->achieved_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
