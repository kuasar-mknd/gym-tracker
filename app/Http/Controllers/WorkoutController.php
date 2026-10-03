<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Workouts\CreateWorkoutAction;
use App\Actions\Workouts\FetchWorkoutShowAction;
use App\Actions\Workouts\FetchWorkoutsIndexAction;
use App\Actions\Workouts\UpdateWorkoutAction;
use App\Http\Requests\UpdateWorkoutRequest;
use App\Models\Workout;
use Illuminate\Http\Request;
use Inertia\Inertia;

class WorkoutController extends Controller
{
    public function __construct(protected \App\Services\Stats\StatsCacheManager $statsCache)
    {
    }

    public function index(Request $request, FetchWorkoutsIndexAction $fetchWorkouts): \Inertia\Response
    {
        $this->authorize('viewAny', Workout::class);

        $user = $this->user();
        $data = $fetchWorkouts->execute($user);

        return Inertia::render('Workouts/Index', [
            ...$data,
            // ⚡ Bolt : les données lourdes du graphique et la liste des
            // exercices tiennent en une seule prop différée — une requête XHR au
            // lieu de deux, et un seul état de chargement à l'écran plutôt que
            // deux qui se terminent à des moments différents.
            'deferredData' => Inertia::defer(fn (): array => $fetchWorkouts->getDeferredData($user)),
        ]);
    }

    /**
     * @throws \Symfony\Component\HttpKernel\Exception\HttpException Si la séance n'est pas celle de l'utilisateur (403).
     */
    public function show(Workout $workout, FetchWorkoutShowAction $fetchWorkoutShow): \Inertia\Response
    {
        $this->authorize('view', $workout);

        return Inertia::render('Workouts/Show', $fetchWorkoutShow->execute($this->user(), $workout));
    }

    /**
     * Démarrer une séance quand une autre est déjà ouverte renvoie vers
     * celle-là, plutôt que d'en ouvrir une deuxième.
     */
    public function store(CreateWorkoutAction $createWorkout): \Illuminate\Http\RedirectResponse
    {
        $this->authorize('create', Workout::class);

        // La même question que la prop partagée pose déjà, dans cette requête.
        $activeWorkout = app(\App\Services\ActiveWorkoutService::class)->for($this->user());

        if ($activeWorkout instanceof Workout) {
            return redirect()->route('workouts.show', $activeWorkout);
        }

        $workout = $createWorkout->execute($this->user());

        return redirect()->route('workouts.show', $workout);
    }

    /**
     * Terminer une séance ramène au tableau de bord ; toute autre modification
     * reste sur place, puisqu'elle est faite depuis la séance elle-même.
     *
     * La fin de séance porte aussi, en donnée flash, l'invitation aux
     * notifications : l'accueil la reçoit une fois, et une navigation arrière
     * ne la rejoue pas, Inertia ne gardant pas le flash dans l'historique.
     */
    public function update(UpdateWorkoutRequest $request, Workout $workout, UpdateWorkoutAction $updateWorkout): \Illuminate\Http\RedirectResponse
    {
        $this->authorize('update', $workout);

        /** @var array{started_at?: string|null, name?: string|null, notes?: string|null, is_finished?: bool} $data */
        $data = $request->validated();
        $updateWorkout->execute($workout, $data);

        if ($request->boolean('is_finished')) {
            if ($this->doitProposerLesNotifications()) {
                Inertia::flash('proposerLesNotifications', true);
            }

            return redirect()->route('dashboard');
        }

        return back();
    }

    /**
     * Faut-il proposer les notifications au retour sur l'accueil (#1848) ?
     *
     * Rien ne les proposait hors du profil. La fin d'une séance est le moment
     * où elles ont un sens : un record à annoncer, plutôt qu'une demande à
     * froid au premier écran.
     *
     * Le serveur ne répond que pour le COMPTE : aucun abonnement tenu, et les
     * records pas coupés dans le profil — l'activation les ferait partir en
     * push sans y toucher, donc n'enverrait rien. Ce que tient l'appareil
     * (permission, abonnement, refus mémorisé), seul le navigateur le sait :
     * `useInvitationAuxNotifications` le juge à son tour.
     */
    private function doitProposerLesNotifications(): bool
    {
        $user = $this->user();

        return ! $user->pushSubscriptions()->exists()
            && ! $user->notificationPreferences()
                ->where('type', 'personal_record')
                ->where('is_enabled', false)
                ->exists();
    }

    public function destroy(Workout $workout): \Illuminate\Http\RedirectResponse
    {
        $this->authorize('delete', $workout);

        $workout->delete();

        $this->statsCache->clearWorkoutRelatedStats($this->user());

        return redirect()->route('workouts.index');
    }
}
