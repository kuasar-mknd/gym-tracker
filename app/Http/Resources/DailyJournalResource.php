<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DailyJournalResource extends JsonResource
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
            'date' => $this->date ? $this->date->format('Y-m-d') : null,
            'content' => $this->content,
            'mood_score' => $this->mood_score,
            'sleep_quality' => $this->sleep_quality,
            'stress_level' => $this->stress_level,
            'energy_level' => $this->energy_level,
            'motivation_level' => $this->motivation_level,
            'nutrition_score' => $this->nutrition_score,
            'training_intensity' => $this->training_intensity,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
