<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\WilksScore */
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
            'user_id' => $this->user_id,
            'body_weight' => $this->body_weight,
            'lifted_weight' => $this->lifted_weight,
            'gender' => $this->gender,
            'unit' => $this->unit,
            'score' => $this->score,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
