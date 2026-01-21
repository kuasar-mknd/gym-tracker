<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Set */
class SetResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'workout_line_id' => $this->workout_line_id,
            'weight' => $this->weight,
            'reps' => $this->reps,
            'duration_seconds' => $this->duration_seconds,
            'distance_km' => $this->distance_km,
            'is_warmup' => (bool) $this->is_warmup,
            'is_completed' => (bool) $this->is_completed,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
