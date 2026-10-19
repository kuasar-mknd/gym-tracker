<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Workouts\CreateSetAction;
use App\Actions\Workouts\DemarrerUneSeanceAction;
use App\Actions\Workouts\FetchWorkoutShowAction;
use App\Actions\Workouts\FetchWorkoutsIndexAction;
use App\Actions\Workouts\UpdateWorkoutAction;
use App\Exceptions\UneSeanceEstDejaEnCours;
use App\Http\Requests\UpdateWorkoutRequest;
use App\Models\Set;
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
     * La séance, et les plafonds d'une série : la page ramène dessous ce
     * qu'elle recopie dans une série qu'elle ajoute (`Set::bornes()`).
     *
     * @throws \Symfony\Component\HttpKernel\Exception\HttpException Si la séance n'est pas celle de l'utilisateur (403).
     */
    public function show(Workout $workout, FetchWorkoutShowAction $fetchWorkoutShow): \Inertia\Response
    {
        $this->authorize('view', $workout);

        return Inertia::render('Workouts/Show', [
            ...$fetchWorkoutShow->execute($this->user(), $workout),
            'bornesDUneSerie' => Set::bornes(),
        ]);
    }

    /**
     * L'adresse d'une séance démarrée sans réseau, nommée par la clé que
     * l'appareil a tirée (#2049) : une fois la séance créée par la file, elle
     * mène à la séance ; avant, ou pour la clé d'un autre compte, à l'accueil.
     *
     * La page de la séance en attente vit sur l'appareil, construite depuis
     * la file ; cette route sert quand la page se recharge en ligne, quand
     * Retour la rouvre depuis l'historique, ou au retour du réseau. Une clé
     * inconnue et celle d'un autre compte reçoivent la même réponse, entière :
     * rien ne dit qu'une séance porte cette clé ailleurs. La clé ne se
     * cherche que parmi les séances du compte, à l'octet près (`ascii_bin`).
     */
    public function enAttente(string $cle): \Illuminate\Http\RedirectResponse
    {
        $seance = Workout::query()
            ->where('user_id', $this->user()->id)
            ->where('idempotency_key', $cle)
            ->first();

        return $seance instanceof Workout
            ? redirect()->route('workouts.show', $seance)
            : redirect()->route('dashboard');
    }

    /**
     * Démarrer une séance quand une autre est déjà ouverte renvoie vers
     * celle-là, plutôt que d'en ouvrir une deuxième.
     *
     * Le démarrage passe par le verrou du compte (`DemarrerUneSeanceAction`) :
     * deux onglets ou deux appareils qui démarraient au même instant
     * ouvraient chacun une séance. La clé `Idempotency-Key`, quand la page en
     * envoie une, fait d'un démarrage rejoué la même séance (#2049).
     */
    public function store(Request $request, DemarrerUneSeanceAction $demarrer): \Illuminate\Http\RedirectResponse
    {
        $this->authorize('create', Workout::class);

        $cle = CreateSetAction::idempotencyKey(['idempotency_key' => $request->header('Idempotency-Key')]);

        try {
            $workout = $demarrer->execute($this->user(), $cle);
        } catch (UneSeanceEstDejaEnCours $refus) {
            return redirect()->route('workouts.show', $refus->seanceEnCours);
        }

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
            if ($this->user()->doitRecevoirLInvitationAuxNotifications()) {
                Inertia::flash('proposerLesNotifications', true);
            }

            return redirect()->route('dashboard');
        }

        return back();
    }

    public function destroy(Workout $workout): \Illuminate\Http\RedirectResponse
    {
        $this->authorize('delete', $workout);

        $workout->delete();

        $this->statsCache->clearWorkoutRelatedStats($this->user());

        return redirect()->route('workouts.index');
    }
}
