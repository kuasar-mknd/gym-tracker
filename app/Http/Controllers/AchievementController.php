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
        $userAchievements = $this->user()->achievements()->get()->keyBy('id');
        $achievements = Achievement::all()->map(fn ($achievement) => $this->formatAchievement($achievement, $userAchievements));

        return Inertia::render('Achievements/Index', [
            'achievements' => $achievements,
            'summary' => [
                'total' => $achievements->count(),
                'unlocked' => $achievements->where('is_unlocked', true)->count(),
            ],
        ]);
    }

    /**
     * @param  \Illuminate\Support\Collection<int, \App\Models\Achievement>  $userAchievements
     * @return array<string, mixed>
     */
    private function formatAchievement(Achievement $achievement, $userAchievements): array
    {
        $userAchievement = $userAchievements->get($achievement->id);

        return [
            'id' => $achievement->id,
            'slug' => $achievement->slug,
            'name' => $achievement->name,
            'description' => $achievement->description,
            'icon' => $achievement->icon,
            'category' => $achievement->category,
            'is_unlocked' => (bool) $userAchievement,
            // @phpstan-ignore-next-line
            'unlocked_at' => $userAchievement ? $userAchievement->pivot->achieved_at : null,
        ];
    }
}
