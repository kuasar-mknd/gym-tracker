<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Supplement */
class SupplementResource extends JsonResource
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
            'name' => $this->name,
            'brand' => $this->brand,
            'dosage' => $this->dosage,
            'servings_remaining' => $this->servings_remaining,
            'low_stock_threshold' => $this->low_stock_threshold,
            'last_taken_at' => $this->whenLoaded('latestLog', fn () => $this->latestLog?->consumed_at),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
