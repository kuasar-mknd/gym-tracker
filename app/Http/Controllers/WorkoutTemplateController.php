<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\CreateWorkoutTemplateAction;
use App\Actions\CreateWorkoutTemplateFromWorkoutAction;
use App\Actions\Workouts\CreateSetAction;
use App\Actions\Workouts\DemarrerUneSeanceAction;
use App\Exceptions\UneSeanceEstDejaEnCours;
use App\Models\Exercise;
use App\Models\Set;
use App\Models\Workout;
use App\Models\WorkoutTemplate;
use Illuminate\Http\Request;
use Inertia\Inertia;

class WorkoutTemplateController extends Controller
{
    /**
     * Les modèles, l'aperçu de chacun (`templates`), et de quoi lancer chacun
     * sans réseau (`lancements`, #2049) : la page est gardée pour la salle.
     */
    public function index(\App\Actions\FetchWorkoutTemplatesAction $fetchWorkoutTemplatesAction): \Inertia\Response
    {
        $this->authorize('viewAny', WorkoutTemplate::class);

        return Inertia::render('Workouts/Templates/Index', [
            'templates' => $fetchWorkoutTemplatesAction->execute($this->user()),
            'lancements' => $fetchWorkoutTemplatesAction->lancements($this->user()),
        ]);
    }

    public function create(): \Inertia\Response
    {
        $this->authorize('create', WorkoutTemplate::class);

        $idUtilisateur = $this->user()->id;

        return Inertia::render('Workouts/Templates/Create', [
            'exercises' => Exercise::enCachePourUtilisateur($idUtilisateur),
            ...$this->bornesDuFormulaire(),
        ]);
    }

    public function store(\App\Http\Requests\StoreWorkoutTemplateRequest $request, CreateWorkoutTemplateAction $createWorkoutTemplateAction): \Illuminate\Http\RedirectResponse
    {
        $this->authorize('create', WorkoutTemplate::class);

        /** @var array{name: string, description?: string|null, exercises?: array<int, array{id: int, sets?: array<int, array{reps?: int|null, weight?: float|null, is_warmup?: bool}>}>} $donneesValidees */
        $donneesValidees = $request->validated();
        $createWorkoutTemplateAction->execute($this->user(), $donneesValidees);

        return redirect()->route('templates.index');
    }

    /**
     * Ouvre une séance à partir du modèle et y envoie l'utilisateur.
     *
     * Quand une séance est déjà ouverte, le modèle ne démarre pas : l'utilisateur
     * est renvoyé vers elle, comme par « Démarrer », avec un message qui dit
     * pourquoi. Sans cette garde, il se retrouvait avec deux séances ouvertes,
     * dont le bandeau ne montrait que la plus récente, et l'autre restait « en
     * cours » indéfiniment (#1958). La garde tient sous le verrou du compte
     * (`DemarrerUneSeanceAction`), y compris entre deux onglets ou deux
     * appareils (#2049). Un lancement rejoué sous sa clé `Idempotency-Key`
     * mène à la séance qu'il a ouverte, sans message : ce n'est pas un second
     * lancement.
     */
    public function execute(Request $request, WorkoutTemplate $template, DemarrerUneSeanceAction $demarrer): \Illuminate\Http\RedirectResponse
    {
        $this->authorize('view', $template);

        $cle = CreateSetAction::idempotencyKey(['idempotency_key' => $request->header('Idempotency-Key')]);

        try {
            $workout = $demarrer->execute($this->user(), $cle, $template);
        } catch (UneSeanceEstDejaEnCours $refus) {
            return redirect()->route('workouts.show', $refus->seanceEnCours)->with(
                'error',
                "Le modèle « {$template->name} » n'a pas été démarré : une séance est déjà en cours. Terminez-la avant d'en commencer une autre.",
            );
        }

        return redirect()->route('workouts.show', $workout);
    }

    /**
     * Enregistre une séance, terminée ou en cours, comme modèle réutilisable.
     *
     * Une séance plus grande qu'un modèle y est ramenée aux bornes d'un
     * modèle ; le message le dit, pour que rien ne manque en silence.
     */
    public function saveFromWorkout(Workout $workout, CreateWorkoutTemplateFromWorkoutAction $createTemplate): \Illuminate\Http\RedirectResponse
    {
        $this->authorize('view', $workout);

        $createTemplate->execute($this->user(), $workout);

        $message = $createTemplate->depasseLesBornesDUnModele($workout)
            ? sprintf(
                'Modèle enregistré, ramené à ce qu’un modèle accepte : %d exercices et %d séries par exercice au plus, %s répétitions et %s kg par série au plus.',
                WorkoutTemplate::EXERCICES_MAX,
                WorkoutTemplate::SERIES_MAX_PAR_EXERCICE,
                number_format(Set::REPETITIONS_MAX, 0, ',', ' '),
                number_format(Set::POIDS_MAX_KG, 0, ',', ' '),
            )
            : 'Modèle enregistré avec succès !';

        return redirect()->route('templates.index')->with('success', $message);
    }

    public function destroy(WorkoutTemplate $template): \Illuminate\Http\RedirectResponse
    {
        $this->authorize('delete', $template);

        $template->delete();

        return back();
    }

    /**
     * Aucun écran ne montre un modèle seul : la route existe pour compléter la
     * ressource, elle répond 404.
     */
    public function show(WorkoutTemplate $template): \Inertia\Response
    {
        abort(404);
    }

    /**
     * Les lignes sont chargées avec leur exercice et leurs séries : c'est la
     * forme à partir de laquelle Templates/Edit construit son formulaire.
     */
    public function edit(WorkoutTemplate $template): \Inertia\Response
    {
        $this->authorize('update', $template);

        $template->load(['workoutTemplateLines.exercise', 'workoutTemplateLines.workoutTemplateSets']);

        return Inertia::render('Workouts/Templates/Edit', [
            'template' => $template,
            'exercises' => Exercise::enCachePourUtilisateur($this->user()->id),
            ...$this->bornesDuFormulaire(),
        ]);
    }

    /**
     * Les plafonds que le formulaire d'un modèle applique avant d'envoyer : ceux
     * d'un modèle, et ceux d'une série pour les répétitions et le poids. Ce sont
     * ceux que ses requêtes valident (`BorneLesSeriesDuGabarit`).
     *
     * @return array{bornesDuModele: array{exercices: int, seriesParExercice: int}, bornesDUneSerie: array{weight: int, reps: int, distance_km: int, duration_seconds: int}}
     */
    private function bornesDuFormulaire(): array
    {
        return [
            'bornesDuModele' => WorkoutTemplate::bornes(),
            'bornesDUneSerie' => Set::bornes(),
        ];
    }

    /**
     * Le travail revient à UpdateWorkoutTemplateAction, qui reconstruit les
     * lignes depuis l'état final soumis — la même action que le contrôleur API.
     */
    public function update(
        \App\Http\Requests\Api\WorkoutTemplateUpdateRequest $request,
        WorkoutTemplate $template,
        \App\Actions\UpdateWorkoutTemplateAction $updateWorkoutTemplateAction
    ): \Illuminate\Http\RedirectResponse {
        $this->authorize('update', $template);

        /** @var array{name: string, description?: string|null, exercises?: array<int, array{id: int, sets?: array<int, array{reps?: int|null, weight?: float|null, is_warmup?: bool}>}>} $donneesValidees */
        $donneesValidees = $request->validated();

        $updateWorkoutTemplateAction->execute($template, $donneesValidees);

        return redirect()->route('templates.index');
    }
}
