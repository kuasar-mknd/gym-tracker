<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\WarmupPreference
 */
class WarmupPreferenceResource extends JsonResource
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
            'bar_weight' => $this->bar_weight,
            'rounding_increment' => $this->rounding_increment,
            'steps' => $this->steps,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
