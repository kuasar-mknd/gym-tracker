<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PersonalRecordResource extends JsonResource
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
            'user_id' => $this->user_id,
            'exercise_id' => $this->exercise_id,
            'type' => $this->type,
            'value' => $this->value,
            'secondary_value' => $this->secondary_value,
            'workout_id' => $this->workout_id,
            'set_id' => $this->set_id,
            'achieved_at' => $this->achieved_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'exercise' => new ExerciseResource($this->whenLoaded('exercise')),
            // We can load other relationships if needed, but exercise is most important for context
        ];
    }
}
