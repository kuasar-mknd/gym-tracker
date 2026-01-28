<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\MacroCalculation
 */
class MacroCalculationResource extends JsonResource
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
            'gender' => $this->gender,
            'age' => $this->age,
            'height' => (float) $this->height,
            'weight' => (float) $this->weight,
            'activity_level' => (float) $this->activity_level,
            'goal' => $this->goal,
            'tdee' => $this->tdee,
            'target_calories' => $this->target_calories,
            'protein' => $this->protein,
            'fat' => $this->fat,
            'carbs' => $this->carbs,
            'created_at' => $this->created_at->toIso8601String(),
            'updated_at' => $this->updated_at->toIso8601String(),
        ];
    }
}
