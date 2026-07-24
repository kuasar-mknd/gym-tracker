<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Workout;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Workout */
class WorkoutResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'started_at' => $this->started_at,
            'ended_at' => $this->ended_at,
            'notes' => $this->notes,
            'lines' => WorkoutLineResource::collection($this->whenLoaded('workoutLines')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
