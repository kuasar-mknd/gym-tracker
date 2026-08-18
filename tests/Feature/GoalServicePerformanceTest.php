<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\GoalType;
use App\Models\Exercise;
use App\Models\Goal;
use App\Models\Set;
use App\Models\User;
use App\Models\Workout;
use App\Models\WorkoutLine;
use App\Services\GoalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Le pre-calcul de `syncGoals` tient-il sa promesse ?
 *
 * Ce test montait deja vingt objectifs sur dix exercices — exactement le jeu
 * qu'il faut pour exposer un N+1 — comptait les requetes, puis les imprimait
 * avec `echo` avant d'affirmer `assertTrue(true)`. Il mesurait la seule chose qui
 * compte ici et jetait la mesure. Sur un tableau de couverture, il cochait la
 * case « performance ».
 *
 * Le pre-calcul n'a pas d'autre effet observable : a valeurs egales avec le
 * chemin de repli — chaque objectif retombant sur sa propre requete et trouvant
 * le meme resultat — seul le NOMBRE de requetes distingue les deux. C'est donc la
 * seule chose qu'un test puisse verifier, et il faut qu'il la verifie.
 */
class GoalServicePerformanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_sync_goals_does_not_query_once_per_goal(): void
    {
        $user = User::factory()->create();
        $exercises = Exercise::factory()->count(10)->create();

        foreach ($exercises as $exercise) {
            // Une seance par exercice, pour que les deux agregats aient de quoi
            // travailler : sans series, les tableaux pre-calcules seraient vides
            // et le test passerait sans rien exercer.
            $workout = Workout::factory()->create(['user_id' => $user->id]);
            $line = WorkoutLine::factory()->create([
                'workout_id' => $workout->id,
                'exercise_id' => $exercise->id,
            ]);
            Set::factory()->create([
                'workout_line_id' => $line->id,
                'weight' => 60,
                'reps' => 8,
                'is_warmup' => false,
            ]);

            Goal::factory()->create([
                'user_id' => $user->id,
                'type' => GoalType::Weight,
                'exercise_id' => $exercise->id,
                'target_value' => 100,
                'current_value' => 0,
                'start_value' => 0,
            ]);

            Goal::factory()->create([
                'user_id' => $user->id,
                'type' => GoalType::Volume,
                'exercise_id' => $exercise->id,
                'target_value' => 1000,
                'current_value' => 0,
                'start_value' => 0,
            ]);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();

        app(GoalService::class)->syncGoals($user);

        $requetes = count(DB::getQueryLog());

        DB::disableQueryLog();

        /*
         * Vingt objectifs. Sans pre-calcul, c'est au moins une requete par
         * objectif, soit vingt, plus la lecture des objectifs et l'ecriture.
         * Avec, il en faut une poignee : les objectifs, le maximum de poids, le
         * maximum de volume, et l'upsert.
         *
         * La borne est a dix, largement au-dessus du compte reel et largement
         * en dessous du comportement degrade : elle attrape la disparition du
         * pre-calcul sans se casser au premier `with()` ajoute ailleurs.
         */
        $this->assertLessThan(
            10,
            $requetes,
            "syncGoals a emis {$requetes} requetes pour 20 objectifs : le pre-calcul ne joue plus son role."
        );
    }
}
