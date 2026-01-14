<?php

namespace App\Http\Controllers;

use App\Models\Achievement;
use Inertia\Inertia;

class AchievementController extends Controller
{
    /**
     * Display a listing of all achievements and user progress.
     */
    public function index(): \Inertia\Response
    {
        $user = auth()->user();

        $achievements = Achievement::all()->map(function ($achievement) use ($user) {
            $userAchievement = $user->achievements()
                ->where('achievement_id', $achievement->id)
                ->first();

            return [
                'id' => $achievement->id,
                'slug' => $achievement->slug,
                'name' => $achievement->name,
                'description' => $achievement->description,
                'icon' => $achievement->icon,
                'category' => $achievement->category,
                'is_unlocked' => (bool) $userAchievement,
                'unlocked_at' => $userAchievement ? $userAchievement->pivot->achieved_at : null,
            ];
        });

        return Inertia::render('Achievements/Index', [
            'achievements' => $achievements,
            'summary' => [
                'total' => $achievements->count(),
                'unlocked' => $achievements->where('is_unlocked', true)->count(),
            ],
        ]);
    }
}
