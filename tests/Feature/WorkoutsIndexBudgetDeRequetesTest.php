<?php

declare(strict_types=1);

use App\Actions\Workouts\FetchWorkoutsIndexAction;
use App\Models\Exercise;
use App\Models\User;
use App\Models\Workout;
use App\Models\WorkoutLine;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Un compte avec une grande bibliotheque : vingt seances qui, ensemble,
 * touchent quatre-vingts exercices differents.
 */
function semerUneGrandeBibliotheque(User $user, int $seances = 20, int $exercices = 80): void
{
    $bibliotheque = Exercise::factory()->count($exercices)->create(['user_id' => null]);

    for ($rang = 0; $rang < $seances; $rang++) {
        $seance = Workout::factory()->create([
            'user_id' => $user->id,
            'started_at' => now()->subDays($rang),
            'ended_at' => now()->subDays($rang)->addHour(),
        ]);

        foreach ($bibliotheque->slice($rang * 4, 4) as $exercice) {
            WorkoutLine::factory()->create([
                'workout_id' => $seance->id,
                'user_id' => $user->id,
                'exercise_id' => $exercice->id,
                'workout_started_at' => $seance->started_at,
            ]);
        }
    }
}

/**
 * @return list<string>
 */
function requetesDeLaPage(User $user, string $url): array
{
    Cache::flush();
    DB::flushQueryLog();
    DB::enableQueryLog();

    test()->actingAs($user)->get($url)->assertOk();

    $requetes = array_values(array_map(fn (array $entree): string => (string) $entree['query'], DB::getQueryLog()));
    DB::disableQueryLog();

    return $requetes;
}

/**
 * Un compte semé en masse, sans les écouteurs des fabriques : une séance par
 * jour, huit lignes par séance, les exercices pris tour à tour dans la
 * bibliothèque donnée.
 *
 * @param  Collection<int, Exercise>  $bibliotheque
 * @return int le nombre de lignes semées
 */
function budgetSemerLesLignesDUnCompte(User $user, Collection $bibliotheque, int $seances): int
{
    $maintenant = now();

    DB::table('workouts')->insert(array_map(fn (int $rang): array => [
        'user_id' => $user->id,
        'name' => 'S'.$rang,
        'started_at' => $maintenant->copy()->subDays($rang),
        'ended_at' => $maintenant->copy()->subDays($rang)->addHour(),
        'created_at' => $maintenant,
        'updated_at' => $maintenant,
    ], range(0, $seances - 1)));

    $exercices = $bibliotheque->pluck('id')->all();
    $lignes = [];

    foreach (DB::table('workouts')->where('user_id', $user->id)->orderBy('id')->get(['id', 'started_at'])->values() as $rangDeLaSeance => $seance) {
        foreach (range(0, 7) as $rang) {
            $lignes[] = [
                'workout_id' => $seance->id,
                'user_id' => $user->id,
                'workout_started_at' => $seance->started_at,
                'exercise_id' => $exercices[($rangDeLaSeance * 8 + $rang) % count($exercices)],
                'order' => $rang,
                'created_at' => $maintenant,
                'updated_at' => $maintenant,
            ];
        }
    }

    foreach (array_chunk($lignes, 500) as $lot) {
        DB::table('workout_lines')->insert($lot);
    }

    return count($lignes);
}

/**
 * Ce que coûte un geste en base : les entrées d'index lues (deltas de
 * `Handler_read%`, `.ai/rules/services.md`) et les requêtes envoyées.
 *
 * @return array{lectures: int, requetes: list<array{query: string, bindings: list<mixed>}>}
 */
function budgetReleverUnGeste(callable $geste): array
{
    $lecturesDeLaSession = function (): int {
        $total = 0;

        /** @var list<object{Value: string}> $compteurs */
        $compteurs = DB::select("show session status like 'Handler_read%'");

        foreach ($compteurs as $compteur) {
            $total += (int) $compteur->Value;
        }

        return $total;
    };

    $avant = $lecturesDeLaSession();
    DB::flushQueryLog();
    DB::enableQueryLog();
    $geste();

    $requetes = array_values(array_map(fn (array $entree): array => [
        'query' => (string) $entree['query'],
        'bindings' => array_values((array) $entree['bindings']),
    ], DB::getQueryLog()));
    DB::disableQueryLog();

    return ['lectures' => $lecturesDeLaSession() - $avant, 'requetes' => $requetes];
}

/**
 * La page des seances comptait ses exercices distincts par un saut d'index,
 * une requete par exercice trouve : juste en lectures, mais quatre-vingts
 * allers-retours pour un compte de quatre-vingts exercices, a chaque
 * expiration du cache. Le compte tient en une seule instruction, que MySQL
 * peut parcourir lui-meme par sauts (`count(distinct user_id, exercise_id)`,
 * #2106).
 */
it('tient sous quinze requêtes à froid, quel que soit le nombre d’exercices', function (): void {
    $user = User::factory()->create();
    semerUneGrandeBibliotheque($user);

    $requetes = requetesDeLaPage($user, route('workouts.index'));

    expect(count($requetes))->toBeLessThanOrEqual(15, implode("\n", $requetes));
});

/**
 * Les props immédiates de la page, résolues comme Inertia le fait pour la
 * page complète : chacune est une fermeture depuis #2148 (étape 3a).
 *
 * @param  array<string, mixed>  $props
 * @return array<string, mixed>
 */
function seancesBudgetResoudreLesProps(array $props): array
{
    return array_map(fn (mixed $prop): mixed => $prop instanceof Closure ? $prop() : $prop, $props);
}

/*
 * Compter les requêtes ne voyait pas le coût de la seule : l'expression de
 * table récursive qui comptait les exercices tenait en une instruction, mais
 * son membre récursif était une sous-requête dépendante qui relisait toutes
 * les lignes du compte à chaque exercice, E × L entrées d'index (720 244 à
 * quatre-vingts exercices et 9 000 lignes, #2106).
 *
 * Le contrôle porte sur la forme, pas sur un compte exact que les
 * statistiques de la table feraient bouger (`.ai/rules/services.md`) : pas de
 * sous-requête dépendante dans le plan, et au plus une entrée d'index par
 * ligne du compte, plus celle qui ouvre la plage. Les deux plans de MySQL
 * tiennent cette borne ici, et le cas lit l'un ou l'autre selon ce qui a
 * tourné avant lui : la lecture des lignes du compte (L + 1, 401) quand il
 * tourne seul, le saut d'un exercice au suivant (E + 3, 43) quand le
 * recalcul automatique d'InnoDB a échantillonné les lignes, jamais validées,
 * d'un test voisin (`.ai/rules/services.md`, piège 5). La requête et son coût
 * sont isolés par le cache : la seconde lecture de la page ne refait que ce
 * que le cache n'épargne pas.
 */
it('compte les exercices sans sous-requête dépendante, en une lecture d’index au plus par ligne du compte, plus une', function (): void {
    $user = User::factory()->create();
    $voisin = User::factory()->create();
    $lignesDuCompte = budgetSemerLesLignesDUnCompte($user, Exercise::factory()->count(40)->create(['user_id' => null]), 50);
    budgetSemerLesLignesDUnCompte($voisin, Exercise::factory()->count(10)->create(['user_id' => null]), 50);

    $action = app(FetchWorkoutsIndexAction::class);
    $compte = null;

    // Un premier passage à vide : la première lecture d'une table fausserait le relevé.
    Cache::flush();
    seancesBudgetResoudreLesProps($action->execute($user));
    Cache::flush();

    $aFroid = budgetReleverUnGeste(function () use ($action, $user, &$compte): void {
        $compte = seancesBudgetResoudreLesProps($action->execute($user))['totalExercises'];
    });
    $aChaud = budgetReleverUnGeste(fn (): array => seancesBudgetResoudreLesProps($action->execute($user)));

    $requetesDuCompte = array_values(array_udiff(
        $aFroid['requetes'],
        $aChaud['requetes'],
        fn (array $une, array $autre): int => strcmp($une['query'], $autre['query']),
    ));

    expect($compte)->toBe(40)
        ->and($lignesDuCompte)->toBe(400)
        ->and($requetesDuCompte)->toHaveCount(1);

    $explication = 'explain format=tree '.$requetesDuCompte[0]['query'];

    /** @var list<object{EXPLAIN: string}> $plan */
    $plan = DB::select($explication, $requetesDuCompte[0]['bindings']);
    $arbre = implode("\n", array_map(fn (object $ligne): string => $ligne->EXPLAIN, $plan));

    expect(str_contains(mb_strtolower($arbre), 'dependent'))->toBeFalse($arbre)
        ->and($aFroid['lectures'] - $aChaud['lectures'])->toBeLessThanOrEqual($lignesDuCompte + 1, $arbre);
});

/**
 * Les instructions SQL de la requête de la prop différée de la page des
 * séances, telle que la page l'envoie : une visite partielle qui ne demande
 * que `deferredData`, le cache vidé juste avant.
 *
 * @return list<string>
 */
function seancesBudgetInstructionsDeLaPropDifferee(User $user): array
{
    $page = test()->actingAs($user)->get(route('workouts.index'))->assertOk()->viewData('page');
    $version = is_array($page) && is_string($page['version'] ?? null) ? $page['version'] : '';

    Cache::flush();
    DB::flushQueryLog();
    DB::enableQueryLog();

    // Les en-têtes passent à la seule requête (`.ai/rules/tests.md`).
    test()->actingAs($user)->get(route('workouts.index'), [
        'X-Inertia' => 'true',
        'X-Inertia-Version' => $version,
        'X-Inertia-Partial-Component' => 'Workouts/Index',
        'X-Inertia-Partial-Data' => 'deferredData',
    ])->assertOk()
        ->assertJsonPath('component', 'Workouts/Index')
        ->assertJsonMissingPath('props.workouts')
        ->assertJsonPath('props.deferredData.exercises.0.id', fn (mixed $id): bool => is_int($id));

    $requetes = array_values(array_map(fn (array $entree): string => (string) $entree['query'], DB::getQueryLog()));
    DB::disableQueryLog();

    return $requetes;
}

/*
 * La requête de la prop différée rejouait tout le contrôleur (#2148, étape
 * 3a) : Inertia ne saute, dans une réponse partielle, que les props encore à
 * calculer, et le contrôleur étalait la page de séances et le compte des
 * exercices déjà calculés, que la réponse jetait. La pagination (son compte
 * et sa page, avec les lignes, leurs exercices et le compte de leurs séries)
 * et le compte des exercices partaient donc deux fois par affichage. Ce sont
 * désormais des fermetures : la requête différée ne lit plus aucune de leurs
 * instructions.
 */
it('ne relit ni la pagination ni le compte des exercices dans la requête de la prop différée', function (): void {
    $user = User::factory()->create();
    semerUneGrandeBibliotheque($user);

    Cache::flush();
    DB::flushQueryLog();
    DB::enableQueryLog();

    seancesBudgetResoudreLesProps(app(FetchWorkoutsIndexAction::class)->execute($user));

    $propsImmediates = array_values(array_map(fn (array $entree): string => (string) $entree['query'], DB::getQueryLog()));
    DB::disableQueryLog();

    $propDifferee = seancesBudgetInstructionsDeLaPropDifferee($user);

    // Le compte et la page des séances, leurs lignes, leurs exercices, et le compte des exercices distincts.
    expect($propsImmediates)->toHaveCount(5, implode("\n", $propsImmediates))
        ->and(array_values(array_intersect($propsImmediates, $propDifferee)))->toBe([], implode("\n", $propDifferee));
});
