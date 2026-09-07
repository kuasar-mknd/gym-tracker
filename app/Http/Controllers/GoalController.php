<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\GoalStoreRequest;
use App\Models\Exercise;
use App\Models\Goal;
use App\Services\GoalService;
use Inertia\Inertia;

class GoalController extends Controller
{
    /**
     * Les mensurations sur lesquelles un objectif peut réellement porter.
     *
     * Le formulaire de création et celui de modification remplissent tous deux
     * leur liste déroulante ici : une seconde copie écrite à la main est
     * exactement la façon dont les deux écrans finissent par diverger.
     *
     * Trois mensurations de plus étaient proposées — tour de taille, de
     * poitrine, de bras — et chacune provoquait une erreur 500 dès que la
     * progression était calculée : `GoalService::updateMeasurementGoal` lit une
     * COLONNE de `body_measurements`, qui n'a que `weight` et `body_fat`.
     * Mesure : « SQLSTATE[42S22]: Column not found: 1054 Unknown column
     * 'waist' ».
     *
     * Le calcul se déclenche à chaque pesée enregistrée (SyncUserGoals) et à
     * l'ouverture de la page des objectifs, donc l'objectif était cassé dès sa
     * création.
     *
     * Ces trois mesures existent bien, mais dans `body_part_measurements`,
     * indexées par nom de partie en texte libre. Les y raccorder est une
     * fonctionnalité à part entière, pas un correctif : voir #1454.
     *
     * Pas de `@var` ici : PHP Insights refuse toute annotation de ce genre sur
     * une constante de classe, et PHPStan lit la forme sur le littéral.
     */
    public const array MEASUREMENT_TYPES = [
        ['value' => 'weight', 'label' => 'Poids de corps'],
        ['value' => 'body_fat', 'label' => 'Masse grasse (%)'],
    ];

    /**
     * Les deux premières se lisent dans une COLONNE de `body_measurements` ; les
     * suivantes dans une LIGNE de `body_part_measurements`, désignée par son nom
     * de partie. Le nom sert donc de valeur, tel qu'il est proposé à la saisie —
     * aucun tableau de correspondance à tenir à jour, et la collation
     * `utf8mb4_unicode_ci` de la colonne fait le rapprochement quelle que soit
     * la casse, sans fonction qui écarterait l'index.
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

    public function __construct(protected GoalService $goalService)
    {
    }

    public function index(): \Inertia\Response
    {
        return Inertia::render('Goals/Index', [
            'goals' => $this->user()->goals()
                ->with('exercise')
                // Rien n'archive un objectif terminé : la liste ne fait que
                // grandir, et `latest()` trie sur `created_at` que rien n'indexe.
                ->latest()
                ->limit(100)
                ->get()
                ->append(['unit']),
            'exercises' => Exercise::enCachePourUtilisateur($this->user()->id),
            'measurementTypes' => self::measurementTypes(),
        ]);
    }

    /**
     * L'échéance est castée en date, donc sérialisée en horodatage ISO complet.
     *
     * Un `<input type="date">` n'accepte que `Y-m-d` et s'affiche vide, sans
     * rien dire, pour tout le reste — l'objectif aurait donc eu l'air de n'avoir
     * jamais eu d'échéance, et l'aurait effacée en silence à l'enregistrement
     * suivant. D'où le formatage ici.
     *
     * @throws \Illuminate\Auth\Access\AuthorizationException Si l'objectif n'est pas celui de l'utilisateur.
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
                // Chaîne vide plutôt que null : ces valeurs alimentent des
                // champs typés String|Number, et le formulaire de création les
                // amorce de la même façon. Le middleware de la requête les
                // ramène à null.
                'exercise_id' => $goal->exercise_id ?? '',
                'measurement_type' => $goal->measurement_type ?? '',
                'deadline' => $goal->deadline?->format('Y-m-d') ?? '',
            ],
            'exercises' => Exercise::enCachePourUtilisateur($this->user()->id),
            'measurementTypes' => self::measurementTypes(),
        ]);
    }

    /**
     * @throws \Illuminate\Auth\Access\AuthorizationException Si l'utilisateur n'a pas le droit de créer un objectif.
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
         * La progression est calculée AVANT l'enregistrement, et non après.
         *
         * `updateGoalProgress()` ne persiste rien — c'est `syncGoals()` qui
         * écrit, par un upsert groupé. L'appel qui suivait le `save()` calculait
         * donc `current_value` et `progress_pct` pour les jeter aussitôt : un
         * objectif « développé 100 kg » créé par quelqu'un qui soulève déjà
         * 80 kg s'affichait à 0 %, jusqu'à ce qu'un enregistrement de séance
         * déclenche le job et remette les compteurs d'aplomb.
         *
         * Calculer d'abord évite en prime la seconde écriture.
         */
        $this->goalService->updateGoalProgress($goal);

        $goal->save();

        return redirect()->route('goals.index')->with('success', 'Objectif créé avec succès.');
    }

    /**
     * @throws \Illuminate\Auth\Access\AuthorizationException Si l'utilisateur n'a pas le droit de modifier l'objectif.
     */
    public function update(GoalStoreRequest $request, Goal $goal): \Illuminate\Http\RedirectResponse
    {
        // L'autorisation est faite par `GoalStoreRequest::authorize()`, qui
        // s'exécute avant les règles. La redemander ici évaluait la policy deux
        // fois pour le propriétaire légitime sans jamais pouvoir refuser : quand
        // ce corps s'exécute, la requête a déjà été autorisée.

        // Même raison qu'à la création : `updateGoalProgress()` ne persiste pas.
        // Un `update()` suivi de l'appel enregistrait les champs soumis et jetait
        // la progression recalculée — changer la cible d'un objectif laissait donc
        // le pourcentage d'avant.
        $goal->fill($request->validated());

        $this->goalService->updateGoalProgress($goal);

        $goal->save();

        return redirect()->route('goals.index')->with('success', 'Objectif mis à jour.');
    }

    /**
     * @throws \Illuminate\Auth\Access\AuthorizationException Si l'utilisateur n'a pas le droit de supprimer l'objectif.
     */
    public function destroy(Goal $goal): \Illuminate\Http\RedirectResponse
    {
        $this->authorize('delete', $goal);

        $goal->delete();

        return redirect()->route('goals.index')->with('success', 'Objectif supprimé.');
    }
}
