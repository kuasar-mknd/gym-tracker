<?php

declare(strict_types=1);

/*
 * `FetchWorkoutShowAction` ne fait que deux choses, et aucune des deux n'etait
 * verifiee : quatre mutants sur sept survivaient, soit un score de 42,9 %.
 *
 * Ce que chacun laissait passer :
 *
 *  - retirer `workoutLines.exercise` du chargement anticipe, ou
 *    `workoutLines.sets.personalRecord`, ou l'appel a `load()` en entier : la
 *    page s'affichait pareil, en payant une requete par ligne et par serie.
 *    Un cout que seule une assertion sur le nombre de requetes peut voir ;
 *  - retirer le calcul groupe des valeurs recommandees : la page s'affichait
 *    encore pareil, chaque ligne allant chercher sa recommandation toute seule
 *    au moment du rendu — le N+1 exact que cette ligne existe pour eviter.
 *
 * Les tests posent donc des comptes de requetes EXACTS et non des plafonds :
 * un plafond genereux laisse repasser le N+1 des que la seance s'allonge.
 */

use App\Actions\Workouts\FetchWorkoutShowAction;
use App\Models\Exercise;
use App\Models\PersonalRecord;
use App\Models\Set;
use App\Models\User;
use App\Models\Workout;
use App\Models\WorkoutLine;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

use function PHPUnit\Framework\assertNotNull;

/**
 * Trois exercices, trois lignes, deux series chacune, et un record accroche a
 * une serie.
 *
 * Trois lignes et non une : un chargement anticipe retire ne se distingue d'un
 * chargement paresseux que lorsqu'il y a plusieurs lignes a charger.
 *
 * @return array{0: User, 1: Workout}
 */
function seancePourAffichage(): array
{
    Carbon::setTestNow(Carbon::parse('2026-06-15 12:00:00'));

    $user = User::factory()->create();
    $workout = Workout::factory()->create([
        'user_id' => $user->id,
        'started_at' => Carbon::parse('2026-06-15 10:00:00'),
    ]);

    foreach (range(1, 3) as $rang) {
        $exercise = Exercise::factory()->create(['user_id' => $user->id]);

        $line = WorkoutLine::factory()->create([
            'workout_id' => $workout->id,
            'exercise_id' => $exercise->id,
            'order' => $rang,
        ]);

        foreach (range(1, 2) as $numero) {
            $set = Set::factory()->create([
                'workout_line_id' => $line->id,
                'weight' => 40.0,
                'reps' => 10,
            ]);

            if ($rang === 1 && $numero === 1) {
                PersonalRecord::factory()->create([
                    'user_id' => $user->id,
                    'exercise_id' => $exercise->id,
                    'workout_id' => $workout->id,
                    'set_id' => $set->id,
                    'type' => 'strength',
                    'value' => 40.0,
                ]);
            }
        }
    }

    return [$user, Workout::findOrFail($workout->id)];
}

it('rend la seance et la liste des exercices', function (): void {
    [$user, $workout] = seancePourAffichage();

    $donnees = app(FetchWorkoutShowAction::class)->execute($user, $workout);

    expect(array_keys($donnees))->toBe(['workout', 'exercises']);
    expect($donnees['workout']->id)->toBe($workout->id);
    expect($donnees['exercises'])->toHaveCount(3);
});

it('rapporte les exercices, les series et leurs records avec la seance', function (): void {
    [$user, $workout] = seancePourAffichage();

    $donnees = app(FetchWorkoutShowAction::class)->execute($user, $workout);
    $lignes = $donnees['workout']->workoutLines;

    expect($lignes)->toHaveCount(3);

    // Chaque relation nommee dans `load()` doit etre presente sur TOUTES les
    // lignes, pas sur un echantillon : retirer une seule des deux entrees du
    // tableau laisse l'autre en place, et un test qui ne regarde que la
    // premiere ligne ne voit rien.
    foreach ($lignes as $ligne) {
        expect($ligne->relationLoaded('exercise'))->toBeTrue();
        expect($ligne->relationLoaded('sets'))->toBeTrue();
        expect($ligne->sets)->toHaveCount(2);

        foreach ($ligne->sets as $serie) {
            expect($serie->relationLoaded('personalRecord'))->toBeTrue();
        }
    }

    // Et le record est bien celui de la premiere serie de la premiere ligne :
    // la relation est chargee ET peuplee. `relationLoaded()` seul serait vrai
    // meme si la relation valait `null` partout.
    $premiereSerie = $lignes->firstOrFail()->sets->firstOrFail();
    $record = $premiereSerie->personalRecord;
    assertNotNull($record, 'La premiere serie de la premiere ligne devrait porter un record.');
    expect($record->set_id)->toBe($premiereSerie->id);
});

it('lit la seance entiere en un nombre de requetes qui ne depend pas de sa longueur', function (): void {
    [$user, $workout] = seancePourAffichage();

    $requetes = 0;
    DB::listen(function () use (&$requetes): void {
        $requetes++;
    });

    app(FetchWorkoutShowAction::class)->execute($user, $workout);

    // Sept, et pas « au plus sept » : la liste des exercices, les lignes,
    // leurs exercices, leurs series, les records de ces series, puis les deux
    // requetes du calcul groupe des recommandations. Retirer une entree du
    // chargement anticipe en fait six, cinq, ou quatre — jamais sept.
    expect($requetes)->toBe(7);
});

it('pose les valeurs recommandees sur chaque ligne avant que la vue ne les lise', function (): void {
    [$user, $workout] = seancePourAffichage();

    $donnees = app(FetchWorkoutShowAction::class)->execute($user, $workout);

    // Le rendu Inertia serialise chaque ligne et lit `recommended_values`.
    // Sans le calcul groupe, cet acces retombe sur l'accesseur, qui interroge
    // la base ligne par ligne : c'est le N+1 que la ligne 37 existe pour
    // eviter, et il ne se voit qu'ici, apres l'action.
    $requetes = 0;
    DB::listen(function () use (&$requetes): void {
        $requetes++;
    });

    foreach ($donnees['workout']->workoutLines as $ligne) {
        // Aucun entrainement anterieur : la recommandation est la valeur par
        // defaut du service, posee ici plutot que recalculee.
        expect($ligne->recommended_values['reps'])->toBe(10);
        expect($ligne->recommended_values['duration_seconds'])->toBe(30);
    }

    expect($requetes)->toBe(0);
});
