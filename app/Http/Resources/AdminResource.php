<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdminResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var \App\Models\Admin $admin */
        $admin = $this->resource;

        return [
            'id' => $admin->id,
            'name' => $admin->name,
            'email' => $admin->email,
            'created_at' => $admin->created_at,
            'updated_at' => $admin->updated_at,
        ];
    }
}
