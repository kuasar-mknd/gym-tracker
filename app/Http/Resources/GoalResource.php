<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Goal */
class GoalResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'type' => $this->type,
            'target_value' => $this->target_value,
            'current_value' => $this->current_value,
            'start_value' => $this->start_value,
            'exercise_id' => $this->exercise_id,
            'exercise' => new ExerciseResource($this->whenLoaded('exercise')),
            'measurement_type' => $this->measurement_type,
            'deadline' => $this->deadline?->format('Y-m-d'),
            'completed_at' => $this->completed_at,
            'progress' => $this->progress,
            'unit' => $this->unit,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
