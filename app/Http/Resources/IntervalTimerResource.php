<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class IntervalTimerResource extends JsonResource
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
            'name' => $this->name,
            'work_seconds' => $this->work_seconds,
            'rest_seconds' => $this->rest_seconds,
            'rounds' => $this->rounds,
            'warmup_seconds' => $this->warmup_seconds,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
