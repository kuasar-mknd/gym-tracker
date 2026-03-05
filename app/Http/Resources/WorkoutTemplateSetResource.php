<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\WorkoutTemplateSet */
class WorkoutTemplateSetResource extends JsonResource
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
            'workout_template_line_id' => $this->workout_template_line_id,
            'reps' => $this->reps,
            'weight' => (float) $this->weight,
            'is_warmup' => $this->is_warmup,
            'order' => $this->order,
        ];
    }
}
