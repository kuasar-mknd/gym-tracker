<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Achievement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Spatie\QueryBuilder\QueryBuilder;

class AchievementController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $achievements = QueryBuilder::for(Achievement::class)
            ->allowedFilters(['type', 'slug'])
            ->allowedSorts(['name', 'created_at'])
            ->get();

        return response()->json($achievements);
    }

    public function show(Achievement $achievement): JsonResponse
    {
        return response()->json($achievement);
    }
}
