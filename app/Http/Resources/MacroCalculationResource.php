<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

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
            'user_id' => $this->user_id,
            'gender' => $this->gender,
            'age' => $this->age,
            'height' => $this->height,
            'weight' => $this->weight,
            'activity_level' => $this->activity_level,
            'goal' => $this->goal,
            'tdee' => $this->tdee,
            'target_calories' => $this->target_calories,
            'protein' => $this->protein,
            'fat' => $this->fat,
            'carbs' => $this->carbs,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
