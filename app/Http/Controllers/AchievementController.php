<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Achievement;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Support\Facades\Date;
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
        $achievements = Achievement::enCachePourTous()->map(fn (Achievement $achievement): array => $this->formatAchievement($achievement, $userAchievements));

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
            'unlocked_at' => $this->instantDuDeblocage($userAchievement),
        ];
    }

    /**
     * L'instant du déblocage, écrit comme toute date qu'un modèle sert : en
     * ISO 8601, en UTC (#2082).
     *
     * Le pivot rend `achieved_at` tel que la base le garde : « 2026-10-14
     * 13:59:37 », une heure du fuseau de l'application, sans fuseau écrit. Le
     * navigateur lisait cette forme, qu'ECMAScript ne garantit pas, comme une
     * heure de l'appareil : ailleurs qu'en Europe/Paris, le jour du déblocage
     * pouvait changer.
     */
    private function instantDuDeblocage(?Achievement $userAchievement): ?string
    {
        $pivot = $userAchievement?->getRelationValue('pivot');
        $obtenuLe = $pivot instanceof Pivot ? $pivot->getAttribute('achieved_at') : null;

        return is_string($obtenuLe) ? Date::parse($obtenuLe, config()->string('app.timezone'))->toJSON() : null;
    }
}
