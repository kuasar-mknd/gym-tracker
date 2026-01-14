<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SetResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'weight' => $this->weight,
            'reps' => $this->reps,
            'order' => $this->order,
            'is_completed' => (bool) $this->is_completed,
            'rpe' => $this->rpe,
        ];
    }
}
