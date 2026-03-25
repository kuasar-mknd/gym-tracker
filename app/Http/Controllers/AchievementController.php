<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Achievement;
use Illuminate\Database\Eloquent\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Controller for managing User Achievements.
 *
 * This controller handles the display of all available achievements
 * and the user's progress towards unlocking them. It prepares the data
 * for the frontend via Inertia.
 */
class AchievementController extends Controller
{
    /**
     * Display a listing of all achievements and user progress.
     *
     * Retrieves all achievements from the cache, matches them against
     * the authenticated user's unlocked achievements, and formats the data
     * for the 'Achievements/Index' Inertia view.
     *
     * @return Response The Inertia response rendering the 'Achievements/Index' page.
     */
    public function index(): Response
    {
        $this->authorize('viewAny', Achievement::class);

        $userAchievements = $this->user()->achievements()->get()->keyBy('id');
        // ⚡ Bolt Optimization: Use the cached all() method to hydrate achievements
        // Impact: Reduces load time for the achievements index view
        $achievements = Achievement::getCachedAll()->map(fn (Achievement $achievement): array => $this->formatAchievement($achievement, $userAchievements));

        return Inertia::render('Achievements/Index', [
            'achievements' => $achievements,
            'summary' => [
                'total' => $achievements->count(),
                'unlocked' => $achievements->where('is_unlocked', true)->count(),
            ],
        ]);
    }

    /**
     * Format a single achievement for the frontend.
     *
     * Checks if the given achievement is present in the user's unlocked
     * achievements collection and formats the output array accordingly,
     * including the unlock date if applicable.
     *
     * @param  Achievement  $achievement  The achievement to format.
     * @param  Collection<int, Achievement>  $userAchievements  The collection of achievements unlocked by the user.
     * @return array<string, mixed> The formatted achievement data array.
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
