<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\WorkoutLine */
class WorkoutLineResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'order' => $this->order,
            'notes' => $this->notes,
            'exercise' => new ExerciseResource($this->whenLoaded('exercise')),
            'sets' => SetResource::collection($this->whenLoaded('sets')),
        ];
    }
}
