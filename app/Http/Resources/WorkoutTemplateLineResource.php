<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\WorkoutTemplateLine;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin WorkoutTemplateLine */
class WorkoutTemplateLineResource extends JsonResource
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
            'exercise' => new ExerciseResource($this->whenLoaded('exercise')),
            'order' => $this->order,
            'sets' => WorkoutTemplateSetResource::collection($this->whenLoaded('workoutTemplateSets')),
        ];
    }
}
