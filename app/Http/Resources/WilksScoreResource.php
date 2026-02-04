<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\WilksScore
 */
class WilksScoreResource extends JsonResource
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
            'body_weight' => $this->body_weight,
            'lifted_weight' => $this->lifted_weight,
            'gender' => $this->gender,
            'unit' => $this->unit,
            'score' => $this->score,
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
