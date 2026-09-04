<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\GoalStoreRequest;
use App\Models\Exercise;
use App\Models\Goal;
use App\Services\GoalService;
use Inertia\Inertia;

/**
 * Controller for managing user goals.
 *
 * This controller handles the creation, retrieval, updating, and deletion
 * of user fitness and measurement goals. It interfaces with the GoalService
 * to recalculate goal progress whenever a goal is updated.
 */
class GoalController extends Controller
{
    /**
     * The measurement goals a user can set. The create form and the edit form
     * both fill their select from this list, so it lives in one place — a
     * second hand-written copy is how the two screens drift apart.
     *
     * No @var here: PHP Insights rejects any @var on a class constant outright,
     * and PHPStan reads the shape off the literal anyway.
     */
    /**
     * Les mensurations sur lesquelles un objectif peut reellement porter.
     *
     * Trois de plus etaient proposees — tour de taille, de poitrine, de bras —
     * et chacune provoquait une erreur 500 des que la progression etait
     * calculee : `GoalService::updateMeasurementGoal` lit une COLONNE de
     * `body_measurements`, qui n'a que `weight` et `body_fat`. Mesure :
     * « SQLSTATE[42S22]: Column not found: 1054 Unknown column 'waist' ».
     *
     * Le calcul se declenche a chaque pesee enregistree (SyncUserGoals) et a
     * l'ouverture de la page des objectifs, donc l'objectif etait casse des sa
     * creation.
     *
     * Ces trois mesures existent bien, mais dans `body_part_measurements`,
     * indexees par nom de partie en texte libre. Les y raccorder est une
     * fonctionnalite a part entiere, pas un correctif : voir #1454.
     */
    public const array MEASUREMENT_TYPES = [
        ['value' => 'weight', 'label' => 'Poids de corps'],
        ['value' => 'body_fat', 'label' => 'Masse grasse (%)'],
    ];

    /**
     * Les deux premieres se lisent dans une COLONNE de `body_measurements` ; les
     * suivantes dans une LIGNE de `body_part_measurements`, designee par son nom
     * de partie. Le nom sert donc de valeur, tel qu'il est propose a la saisie —
     * aucun tableau de correspondance a tenir a jour, et la collation
     * `utf8mb4_unicode_ci` de la colonne fait le rapprochement quelle que soit
     * la casse, sans fonction qui ecarterait l'index.
     *
     * @return list<array{value: string, label: string}>
     */
    public static function measurementTypes(): array
    {
        return array_merge(
            self::MEASUREMENT_TYPES,
            array_map(
                static fn (string $partie): array => ['value' => $partie, 'label' => $partie],
                \App\Models\BodyPartMeasurement::COMMON_PARTS
            )
        );
    }

    /**
     * @return list<string>
     */
    public static function measurementTypeValues(): array
    {
        return array_column(self::measurementTypes(), 'value');
    }

    /**
     * Create a new GoalController instance.
     *
     * @param  \App\Services\GoalService  $goalService  The service responsible for updating goal progress.
     */
    public function __construct(protected GoalService $goalService)
    {
    }

    /**
     * Display a listing of the user's goals.
     *
     * Retrieves all goals for the authenticated user, along with available
     * exercises and predefined measurement types for the creation form.
     * Eager loads the associated exercise for each goal.
     *
     * @return \Inertia\Response The Inertia response rendering the 'Goals/Index' page.
     */
    public function index(): \Inertia\Response
    {
        return Inertia::render('Goals/Index', [
            'goals' => $this->user()->goals()
                ->with('exercise')
                // Rien n'archive un objectif termine : la liste ne fait que
                // grandir, et `latest()` trie sur `created_at` que rien n'indexe.
                ->latest()
                ->limit(100)
                ->get()
                ->append(['unit']),
            'exercises' => Exercise::getCachedForUser($this->user()->id),
            'measurementTypes' => self::measurementTypes(),
        ]);
    }

    /**
     * Show the form for editing an existing goal.
     *
     * The deadline is cast to a date, so it serialises as a full ISO timestamp.
     * An `<input type="date">` only accepts `Y-m-d`, and silently renders blank
     * for anything else — which would have looked like a goal that never had a
     * deadline, and quietly cleared it on the next save. It is formatted here.
     *
     * @return \Inertia\Response The Inertia response rendering the 'Goals/Edit' page.
     *
     * @throws \Illuminate\Auth\Access\AuthorizationException If the goal is not the user's.
     */
    public function edit(Goal $goal): \Inertia\Response
    {
        $this->authorize('update', $goal);

        return Inertia::render('Goals/Edit', [
            'goal' => [
                'id' => $goal->id,
                'title' => $goal->title,
                'type' => $goal->type->value,
                'target_value' => $goal->target_value,
                'start_value' => $goal->start_value,
                // Empty string rather than null: these feed inputs whose value
                // is typed String|Number, and the create form seeds them the
                // same way. The request middleware turns them back into null.
                'exercise_id' => $goal->exercise_id ?? '',
                'measurement_type' => $goal->measurement_type ?? '',
                'deadline' => $goal->deadline?->format('Y-m-d') ?? '',
            ],
            'exercises' => Exercise::getCachedForUser($this->user()->id),
            'measurementTypes' => self::measurementTypes(),
        ]);
    }

    /**
     * Store a newly created goal in storage.
     *
     * Validates the request data, sets a default start value if none is provided,
     * creates the goal, and immediately calculates its initial progress.
     *
     * @param  \App\Http\Requests\GoalStoreRequest  $request  The validated request containing goal details.
     * @return \Illuminate\Http\RedirectResponse A redirect back to the goals index.
     *
     * @throws \Illuminate\Auth\Access\AuthorizationException If the user is not authorized to create a goal.
     */
    public function store(GoalStoreRequest $request): \Illuminate\Http\RedirectResponse
    {
        $this->authorize('create', Goal::class);

        $data = $request->validated();
        $data['start_value'] ??= 0;

        $goal = new Goal();
        $goal->fill($data);
        $goal->user_id = $this->user()->id;

        /*
         * La progression est calculee AVANT l'enregistrement, et non apres.
         *
         * `updateGoalProgress()` ne persiste rien — c'est `syncGoals()` qui
         * ecrit, par un upsert groupe. L'appel qui suivait le `save()` calculait
         * donc `current_value` et `progress_pct` pour les jeter aussitot : un
         * objectif « developpe 100 kg » cree par quelqu'un qui souleve deja 80 kg
         * s'affichait a 0 %, jusqu'a ce qu'un enregistrement de seance declenche
         * le job et remette les compteurs d'aplomb.
         *
         * Calculer d'abord evite en prime la seconde ecriture.
         */
        $this->goalService->updateGoalProgress($goal);

        $goal->save();

        return redirect()->route('goals.index')->with('success', 'Objectif créé avec succès.');
    }

    /**
     * Update the specified goal in storage.
     *
     * Validates the incoming data, updates the goal's attributes, and recalculates
     * its progress to reflect the new target or criteria.
     *
     * @param  \App\Http\Requests\GoalStoreRequest  $request  The validated request containing updated goal details.
     * @param  \App\Models\Goal  $goal  The goal instance to update.
     * @return \Illuminate\Http\RedirectResponse A redirect back to the goals index.
     *
     * @throws \Illuminate\Auth\Access\AuthorizationException If the user is not authorized to update the goal.
     */
    public function update(GoalStoreRequest $request, Goal $goal): \Illuminate\Http\RedirectResponse
    {
        // L'autorisation est faite par `GoalStoreRequest::authorize()`, qui
        // s'execute avant les regles. La redemander ici evaluait la policy deux
        // fois pour le proprietaire legitime sans jamais pouvoir refuser : quand
        // ce corps s'execute, la requete a deja ete autorisee.

        // Meme raison qu'a la creation : `updateGoalProgress()` ne persiste pas.
        // Un `update()` suivi de l'appel enregistrait les champs soumis et jetait
        // la progression recalculee — changer la cible d'un objectif laissait donc
        // le pourcentage d'avant.
        $goal->fill($request->validated());

        $this->goalService->updateGoalProgress($goal);

        $goal->save();

        return redirect()->route('goals.index')->with('success', 'Objectif mis à jour.');
    }

    /**
     * Remove the specified goal from storage.
     *
     * Permanently deletes the given goal from the database.
     *
     * @param  \App\Models\Goal  $goal  The goal instance to delete.
     * @return \Illuminate\Http\RedirectResponse A redirect back to the goals index.
     *
     * @throws \Illuminate\Auth\Access\AuthorizationException If the user is not authorized to delete the goal.
     */
    public function destroy(Goal $goal): \Illuminate\Http\RedirectResponse
    {
        $this->authorize('delete', $goal);

        $goal->delete();

        return redirect()->route('goals.index')->with('success', 'Objectif supprimé.');
    }
}
