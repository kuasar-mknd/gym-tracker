<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property int $id
 * @property \Illuminate\Support\Carbon|null $date
 * @property string $content
 * @property int|null $mood_score
 * @property int|null $sleep_quality
 * @property int|null $stress_level
 * @property int|null $energy_level
 * @property int|null $motivation_level
 * @property int|null $nutrition_score
 * @property int|null $training_intensity
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
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
