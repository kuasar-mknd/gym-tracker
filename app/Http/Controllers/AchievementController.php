<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Achievement;
use Illuminate\Database\Eloquent\Collection;
use Inertia\Inertia;
use Inertia\Response;

class AchievementController extends Controller
{
    /**
     * La page montre TOUS les succès, débloqués ou non : ceux qui restent à
     * atteindre sont la moitié de l'intérêt.
     */
    public function index(): Response
    {
        $this->authorize('viewAny', Achievement::class);

        $userAchievements = $this->user()->achievements()->get()->keyBy('id');
        // ⚡ Bolt : le catalogue passe par le cache (`rememberForever`). Il est
        // le même pour tout le monde et ne bouge qu'au réamorçage des données ;
        // le relire à chaque ouverture de la page était du gaspillage.
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
     * @param  Collection<int, Achievement>  $userAchievements  Les succès déjà débloqués, indexés par identifiant.
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
