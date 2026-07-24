<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\SupplementLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin SupplementLog
 */
class SupplementLogResource extends JsonResource
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
            'supplement_id' => $this->supplement_id,
            'quantity' => $this->quantity,
            'consumed_at' => $this->consumed_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'supplement' => new SupplementResource($this->whenLoaded('supplement')),
        ];
    }
}
