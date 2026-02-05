<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property int $id
 * @property string $start_time
 * @property string|null $end_time
 * @property int $target_duration_minutes
 * @property string $type
 * @property string $status
 */
class FastResource extends JsonResource
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
            'start_time' => $this->start_time,
            'end_time' => $this->end_time,
            'target_duration_minutes' => $this->target_duration_minutes,
            'type' => $this->type,
            'status' => $this->status,
        ];
    }
}
