<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Achievement;
use Illuminate\Http\Resources\Json\JsonResource;

class AchievementController extends Controller
{
    public function index(): JsonResource
    {
        return JsonResource::collection(Achievement::all());
    }

    public function show(Achievement $achievement): JsonResource
    {
        return new JsonResource($achievement);
    }
}
