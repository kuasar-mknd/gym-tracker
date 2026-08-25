<?php

declare(strict_types=1);

use App\Actions\Workouts\FetchWorkoutsIndexAction;
use App\Models\Set;
use App\Models\User;
use App\Models\Workout;
use App\Models\WorkoutLine;
use Illuminate\Support\Carbon;

it('calculates volume history correctly', function (): void {
    $user = User::factory()->create();
    $action = app(FetchWorkoutsIndexAction::class);

    // Workout 1: 125 kg total volume
    // 10 * 10 = 100
    // 5 * 5 = 25
    $workout1 = Workout::factory()->create([
        'user_id' => $user->id,
        'started_at' => Carbon::now()->subDays(2),
        'ended_at' => Carbon::now()->subDays(2)->addHour(),
        'name' => 'Workout 1',
    ]);
    $line1 = WorkoutLine::factory()->create(['workout_id' => $workout1->id]);
    Set::factory()->create(['workout_line_id' => $line1->id, 'weight' => 10, 'reps' => 10]);
    Set::factory()->create(['workout_line_id' => $line1->id, 'weight' => 5, 'reps' => 5]);

    // Workout 2: 300 volume
    $workout2 = Workout::factory()->create([
        'user_id' => $user->id,
        'started_at' => Carbon::now()->subDays(1),
        'ended_at' => Carbon::now()->subDays(1)->addHour(),
        'name' => 'Workout 2',
    ]);
    $line2 = WorkoutLine::factory()->create(['workout_id' => $workout2->id]);
    Set::factory()->create(['workout_line_id' => $line2->id, 'weight' => 20, 'reps' => 15]);

    // Workout 3: Ongoing (should be excluded from volume history per current logic checks 'ended_at'?)
    // Checking code: ->whereNotNull('ended_at')
    $workout3 = Workout::factory()->create([
        'user_id' => $user->id,
        'started_at' => Carbon::now(),
        'ended_at' => null,
        'name' => 'Workout 3',
    ]);

    // Create a set for ongoing workout to make sure it's NOT included
    $line3 = WorkoutLine::factory()->create(['workout_id' => $workout3->id]);
    Set::factory()->create(['workout_line_id' => $line3->id, 'weight' => 100, 'reps' => 1]);

    $result = $action->getDeferredData($user);
    $volumeHistory = $result['charts']['volume_history'];

    expect($volumeHistory)->toHaveCount(2); // Workout 1 and 2

    // volumeHistory is ordered by date (oldest first? or latest first? Logic says ->reverse() at end)
    // Original code: latest('started_at') -> get() -> map -> reverse() -> values()
    // So it should be chronological (oldest to newest).

    // Workout 1 (Oldest)
    expect($volumeHistory[0]->name)->toBe('Workout 1');
    expect($volumeHistory[0]->volume)->toBe(125.0); // 10*10 + 5*5

    // Workout 2 (Newest)
    expect($volumeHistory[1]->name)->toBe('Workout 2');
    expect($volumeHistory[1]->volume)->toBe(300.0); // 20*15
});

/*
 * ---------------------------------------------------------------------------
 * Les graphes de la page d'index etaient EXECUTES sans etre verifies : 50 des
 * 67 mutants de `FetchWorkoutsIndexAction` survivaient (score 25,4 %).
 *
 * Concretement, chacune des reecritures suivantes passait la suite au vert :
 *
 *  - retirer `day_of_week_frequency` ou `duration_history` de la charge
 *    differee, donc rendre un graphe vide a la page ;
 *  - borner les historiques a 19 ou 21 seances au lieu de 20 ;
 *  - rendre 5 ou 7 mois de frequence au lieu de 6, ou les decaler d'un mois,
 *    ou les lire a l'envers (`5 + $i` au lieu de `5 - $i`) ;
 *  - retirer la cle `month` (ou `count`, ou `day`) de chaque entree ;
 *  - repondre 1 ou -1 la ou il n'y a eu aucune seance ;
 *  - decaler d'un cran n'importe laquelle des sept cles du tableau des jours,
 *    ce qui soit fait collider deux jours (six barres au lieu de sept), soit
 *    attribue le compte d'un jour a son voisin ;
 *  - supprimer le prechargement de `workoutLines`, de `exercise` et du compte
 *    de series, c'est-a-dire reintroduire le N+1 que ce `with()` existe pour
 *    eviter.
 *
 * Les assertions ci-dessous ferment ces portes. Toutes les valeurs comparees
 * sont POSEES : la fabrique de `Workout` tire un nom et met `started_at` a
 * maintenant, ce qui rendrait n'importe quelle attente dependante du hasard ou
 * du jour ou la suite tourne.
 * ---------------------------------------------------------------------------
 */

/**
 * Une seance TERMINEE, a une date posee.
 *
 * Terminee parce que les deux historiques filtrent sur `ended_at` : une seance
 * en cours n'y entre pas, et un jeu de donnees fait de seances ouvertes rendrait
 * des historiques vides sans que le test s'en apercoive.
 */
function seancePourIndex(User $user, string $quand, string $nom = 'Seance'): Workout
{
    return Workout::factory()->create([
        'user_id' => $user->id,
        'name' => $nom,
        'started_at' => Carbon::parse($quand),
        'ended_at' => Carbon::parse($quand)->addHour(),
    ]);
}

it('rend les cinq graphes annonces, et la bibliotheque, sans en perdre un en route', function (): void {
    $user = User::factory()->create();

    $donnees = app(FetchWorkoutsIndexAction::class)->getDeferredData($user);

    // La forme exacte du contrat que la page consomme. Comparee cle par cle
    // plutot que par `toHaveKey()` : c'est la seule facon de voir qu'il en
    // MANQUE une, et chaque cle absente est un graphe vide a l'ecran.
    expect(array_keys($donnees))->toBe(['charts', 'exercises']);
    expect(array_keys($donnees['charts']))->toBe([
        'monthly_frequency',
        'day_of_week_frequency',
        'monthly_volume',
        'duration_history',
        'volume_history',
    ]);
});

it('borne les deux historiques a vingt seances, la plus ancienne pour l un, la plus recente pour l autre', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-06-25 12:00:00'));
    $user = User::factory()->create();

    // Vingt-et-une : une de plus que la borne, sinon la borne ne se voit pas.
    for ($jour = 1; $jour <= 21; $jour++) {
        seancePourIndex($user, sprintf('2026-06-%02d 10:00:00', $jour), sprintf('S%02d', $jour));
    }

    $charts = app(FetchWorkoutsIndexAction::class)->getDeferredData($user)['charts'];

    // Les deux historiques sont bornes a 20 — mais PAS aux vingt memes seances,
    // et c'est ce que les noms disent ici. La duree prend les vingt DERNIERES
    // puis les remet a l'endroit (S02 -> S21) ; le volume prend les vingt
    // PREMIERES (S01 -> S20). Sans ces deux bornes nommees, un historique de 19
    // ou de 21 entrees passait.
    expect($charts['duration_history'])->toHaveCount(20);
    expect($charts['duration_history'][0]->name)->toBe('S02');
    expect($charts['duration_history'][19]->name)->toBe('S21');

    expect($charts['volume_history'])->toHaveCount(20);
    expect($charts['volume_history'][0]->name)->toBe('S01');
    expect($charts['volume_history'][19]->name)->toBe('S20');
});

it('rend six mois de frequence, du plus ancien au plus recent, et zero sur un mois creux', function (): void {
    // Le 15 juin : loin d'un changement d'heure, et le 15 de chaque mois existe
    // partout, donc `subMonths()` ne deborde sur aucun mois court.
    Carbon::setTestNow(Carbon::parse('2026-06-15 12:00:00'));
    $user = User::factory()->create();

    seancePourIndex($user, '2026-01-20 10:00:00');  // janvier : le mois le plus ancien de la fenetre
    seancePourIndex($user, '2026-06-02 10:00:00');  // juin
    seancePourIndex($user, '2026-06-10 10:00:00');  // juin
    seancePourIndex($user, '2025-12-20 10:00:00');  // decembre : hors fenetre, ne doit rien peser

    $frequence = app(FetchWorkoutsIndexAction::class)->getDeferredData($user)['charts']['monthly_frequency'];

    // Six mois, ni cinq ni sept : c'est la fenetre que la page annonce, et la
    // seule assertion qui tienne les deux bornes du `range(0, 5)`.
    expect($frequence)->toHaveCount(6);

    // Le sens de lecture et le calage du mois, que rien ne verifiait. Janvier
    // en tete tient a la fois la borne SQL (`subMonths(5)->startOfMonth()`, qui
    // decide si la seance du 20 janvier entre) et le decalage de la boucle : un
    // mois de plus, un mois de moins, ou une lecture a l'envers, et cette entree
    // ne s'appelle plus « janv. ».
    expect($frequence[0])->toBe(['month' => 'janv.', 'count' => 1]);
    expect($frequence[5])->toBe(['month' => 'juin', 'count' => 2]);

    // Deux seances en juin se comptent, elles ne s'ecrasent pas.
    // Et fevrier vaut exactement 0 — pas 1, pas -1 : le graphe trace ce chiffre.
    expect($frequence[1])->toBe(['month' => 'févr.', 'count' => 0]);

    // La forme de CHAQUE entree, pas d'un echantillon : une boucle qui ne
    // remplirait qu'une cle sur deux passerait un test qui ne regarde que la
    // premiere.
    foreach ($frequence as $entree) {
        expect(array_keys($entree))->toBe(['month', 'count']);
    }
});

it('associe chaque jour de la semaine a son propre compte', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-06-15 12:00:00'));
    $user = User::factory()->create();

    // Un compte DIFFERENT par jour. Avec le meme compte partout — ou avec un
    // seul jour rempli — decaler une cle du tableau `$days` ne se verrait pas :
    // toutes les barres se ressembleraient. Ici, chaque jour porte sa signature.
    $seancesParJour = [
        '2026-06-08' => 1, // lundi
        '2026-06-09' => 2, // mardi
        '2026-06-10' => 3, // mercredi
        '2026-06-11' => 4, // jeudi
        '2026-06-12' => 5, // vendredi
        '2026-06-13' => 6, // samedi
        '2026-06-14' => 7, // dimanche
    ];

    foreach ($seancesParJour as $jour => $combien) {
        for ($i = 0; $i < $combien; $i++) {
            seancePourIndex($user, $jour.' 10:00:00');
        }
    }

    $frequence = app(FetchWorkoutsIndexAction::class)->getDeferredData($user)['charts']['day_of_week_frequency'];

    // Le tableau entier, dans l'ordre, avec ses deux cles et ses comptes.
    // `toBe()` compare a l'identique : cette ligne seule tient les sept cles de
    // `$days` (DAYOFWEEK vaut 1 le dimanche et 7 le samedi, donc la semaine
    // commence a 2), l'ordre des barres, la presence de `day` et de `count`,
    // et le fait qu'aucun compte ne parte chez le voisin.
    expect($frequence->all())->toBe([
        ['day' => 'Lun', 'count' => 1],
        ['day' => 'Mar', 'count' => 2],
        ['day' => 'Mer', 'count' => 3],
        ['day' => 'Jeu', 'count' => 4],
        ['day' => 'Ven', 'count' => 5],
        ['day' => 'Sam', 'count' => 6],
        ['day' => 'Dim', 'count' => 7],
    ]);
});

it('met zero, et non un ni moins un, sur un jour sans seance', function (): void {
    // Le test precedent remplit les sept jours, donc le repli du ternaire ne s'y
    // execute jamais. Sans utilisateur vide, « 1 » ou « -1 » a la place de « 0 »
    // passait : un graphe qui affiche une barre la ou il n'y a rien.
    $user = User::factory()->create();

    $frequence = app(FetchWorkoutsIndexAction::class)->getDeferredData($user)['charts']['day_of_week_frequency'];

    expect($frequence->all())->toBe([
        ['day' => 'Lun', 'count' => 0],
        ['day' => 'Mar', 'count' => 0],
        ['day' => 'Mer', 'count' => 0],
        ['day' => 'Jeu', 'count' => 0],
        ['day' => 'Ven', 'count' => 0],
        ['day' => 'Sam', 'count' => 0],
        ['day' => 'Dim', 'count' => 0],
    ]);
});

it('pagine par vingt et precharge les lignes, leur exercice et leur compte de series', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-06-25 12:00:00'));
    $user = User::factory()->create();

    // Vingt-et-une seances : la vingt-et-unieme est celle qui rend la borne
    // visible. La plus recente porte les lignes, c'est donc elle qu'on retrouve
    // en tete (`latest('started_at')`).
    for ($jour = 1; $jour <= 20; $jour++) {
        seancePourIndex($user, sprintf('2026-06-%02d 10:00:00', $jour));
    }
    $derniere = seancePourIndex($user, '2026-06-21 10:00:00');
    $ligne = WorkoutLine::factory()->create(['workout_id' => $derniere->id]);
    Set::factory()->count(3)->create(['workout_line_id' => $ligne->id]);

    $workouts = app(FetchWorkoutsIndexAction::class)->execute($user)['workouts'];

    // Vingt par page, pas dix-neuf ni vingt-et-une.
    expect($workouts->perPage())->toBe(20);
    expect($workouts->total())->toBe(21);
    expect($workouts->items())->toHaveCount(20);

    $premiere = $workouts->items()[0];
    expect($premiere->id)->toBe($derniere->id);

    // Le prechargement, qui n'etait verifie nulle part : sans lui la page
    // affiche exactement la meme chose, en une requete par seance. C'est la
    // seule assertion qui distingue `with([...])` de `with([])`.
    expect($premiere->relationLoaded('workoutLines'))->toBeTrue();

    $ligneChargee = $premiere->workoutLines->first();
    expect($ligneChargee->relationLoaded('exercise'))->toBeTrue();

    // `withCount('sets')` : trois series posees, pas une quantite tiree.
    expect($ligneChargee->sets_count)->toBe(3);
});
