<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Habits\CreateHabitAction;
use App\Actions\Habits\FetchHabitsIndexAction;
use App\Actions\Habits\ToggleHabitAction;
use App\Http\Requests\HabitStoreRequest;
use App\Http\Requests\HabitUpdateRequest;
use App\Http\Requests\ToggleHabitRequest;
use App\Models\Habit;
use Illuminate\Http\Request;
use Inertia\Inertia;

class HabitController extends Controller
{
    public function index(Request $request, FetchHabitsIndexAction $fetchHabits): \Inertia\Response
    {
        $this->authorize('viewAny', Habit::class);
        $user = $this->user();

        return Inertia::render('Habits/Index', [
            ...$fetchHabits->getImmediateData($user),
            // ⚡ Bolt : regrouper les props différées en une seule, pour épargner
            // autant de requêtes asynchrones et d'exécutions côté serveur.
            'stats' => Inertia::defer(fn (): array => $fetchHabits->getStatsData($user)),
        ]);
    }

    public function store(HabitStoreRequest $request, CreateHabitAction $createHabitAction): \Illuminate\Http\RedirectResponse
    {
        $this->authorize('create', Habit::class);

        $data = $request->validated();
        $createHabitAction->execute($this->user(), $data);

        return redirect()->back()->with('success', 'Habitude créée.');
    }

    public function update(HabitUpdateRequest $request, Habit $habit): \Illuminate\Http\RedirectResponse
    {
        $this->authorize('update', $habit);

        $habit->update($request->validated());

        return redirect()->back()->with('success', 'Habitude mise à jour.');
    }

    /**
     * @throws \Symfony\Component\HttpKernel\Exception\HttpException Si l'habitude n'est pas celle de l'utilisateur (403).
     */
    public function destroy(Habit $habit): \Illuminate\Http\RedirectResponse
    {
        $this->authorize('delete', $habit);

        $habit->delete();

        return redirect()->back()->with('success', 'Habitude supprimée.');
    }

    /**
     * Coche ou décoche l'habitude à la date donnée : le relevé du jour est créé
     * s'il n'existe pas, supprimé s'il existe.
     *
     * @throws \Symfony\Component\HttpKernel\Exception\HttpException Si l'habitude n'est pas celle de l'utilisateur (403).
     */
    public function toggle(ToggleHabitRequest $request, Habit $habit, ToggleHabitAction $toggleHabitAction): \Illuminate\Http\RedirectResponse
    {
        $this->authorize('update', $habit);

        $validated = $request->validated();

        /** @var string $date */
        $date = $validated['date'];

        $toggleHabitAction->execute($habit, $date);

        return redirect()->back();
    }
}
