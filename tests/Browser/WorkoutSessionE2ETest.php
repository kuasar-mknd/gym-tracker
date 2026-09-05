<?php

declare(strict_types=1);

namespace Tests\Browser;

use App\Models\Exercise;
use App\Models\PersonalRecord;
use App\Models\Set;
use App\Models\User;
use App\Models\Workout;
use App\Models\WorkoutLine;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

class WorkoutSessionE2ETest extends DuskTestCase
{
    use DatabaseTruncation;

    private function performFullWorkout(Browser $browser, string $sizeMacro): void
    {
        $user = User::factory()->create([
            'name' => 'John Doe',
            'email' => 'john'.time().random_int(0, 9999).'@example.com',
            'email_verified_at' => now(),
        ]);

        // EDGE CASE PRE-REQUISITE: Multiple past workouts
        $recommenderEx = Exercise::factory()->create(['user_id' => $user->id, 'type' => 'strength', 'name' => 'Recommender Ex']);

        // 1. Very old workout (3 days ago) - Should be ignored
        $oldestWorkout = Workout::factory()->create([
            'user_id' => $user->id,
            'started_at' => now()->subDays(3),
            'ended_at' => now()->subDays(3)->addHour(),
        ]);
        $oldestLine = WorkoutLine::factory()->create(['workout_id' => $oldestWorkout->id, 'exercise_id' => $recommenderEx->id]);
        Set::factory()->count(5)->create(['workout_line_id' => $oldestLine->id, 'weight' => 120, 'reps' => 2, 'is_completed' => true]);

        // 2. Most recent past workout (2 days ago) - Should be the source
        $lastWorkout = Workout::factory()->create([
            'user_id' => $user->id,
            'started_at' => now()->subDays(2),
            'ended_at' => now()->subDays(2)->addHour(),
        ]);
        $lastLine = WorkoutLine::factory()->create(['workout_id' => $lastWorkout->id, 'exercise_id' => $recommenderEx->id]);

        // Distribution in last workout:
        // - 110kg x 3 reps (Frequency: 3) -> TARGET
        // - 100kg x 5 reps (Frequency: 2)
        Set::factory()->count(3)->create(['workout_line_id' => $lastLine->id, 'weight' => 110, 'reps' => 3, 'is_completed' => true]);
        Set::factory()->count(2)->create(['workout_line_id' => $lastLine->id, 'weight' => 100, 'reps' => 5, 'is_completed' => true]);

        $strengthEx = Exercise::factory()->create(['user_id' => $user->id, 'type' => 'strength', 'name' => 'Strength Ex']);
        $cardioEx = Exercise::factory()->create(['user_id' => $user->id, 'type' => 'cardio', 'name' => 'Cardio Ex']);
        $timedEx = Exercise::factory()->create(['user_id' => $user->id, 'type' => 'timed', 'name' => 'Timed Ex']);

        $workout = Workout::factory()->create([
            'user_id' => $user->id,
            'started_at' => now(),
            'name' => 'Séance Test',
        ]);

        try {
            $browser->loginAs(User::findOrFail($user->id))
                ->{$sizeMacro}()
                ->visit("/workouts/{$workout->id}")
                ->disableAnimations()
                ->waitFor('#main-content', 30);

            // 1. Add Strength exercise
            $browser->waitFor('[dusk="add-first-exercise"]', 15)->click('[dusk="add-first-exercise"]');
            $browser->waitFor('input[placeholder="Rechercher..."]', 15)->type('input[placeholder="Rechercher..."]', 'Strength Ex')
                ->waitFor('@select-exercise-'.$strengthEx->id, 20)->click('@select-exercise-'.$strengthEx->id);
            $browser->waitUntilMissing('[role="dialog"]', 15);

            // 2. Add Cardio exercise
            $browser->clickWhenSettled('[dusk="add-exercise-existing"]', 15);
            $browser->waitFor('input[placeholder="Rechercher..."]', 15)->type('input[placeholder="Rechercher..."]', 'Cardio Ex')
                ->waitFor('@select-exercise-'.$cardioEx->id, 20)->click('@select-exercise-'.$cardioEx->id);
            $browser->waitUntilMissing('[role="dialog"]', 15);

            // 3. Add Timed exercise
            $browser->clickWhenSettled('[dusk="add-exercise-existing"]', 15);
            $browser->waitFor('input[placeholder="Rechercher..."]', 15)->type('input[placeholder="Rechercher..."]', 'Timed Ex')
                ->waitFor('@select-exercise-'.$timedEx->id, 20)->click('@select-exercise-'.$timedEx->id);
            $browser->waitUntilMissing('[role="dialog"]', 15);

            // 3b. Create NEW exercise from workout
            $browser->clickWhenSettled('[dusk="add-exercise-existing"]', 15);
            $browser->waitFor('input[placeholder="Rechercher..."]', 15)->type('input[placeholder="Rechercher..."]', 'Brand New Exercise');
            $browser->waitFor('@quick-create-exercise', 15)->click('@quick-create-exercise');
            $browser->waitFor('@new-exercise-name', 15)
                ->assertInputValue('@new-exercise-name', 'Brand New Exercise')
                ->select('@new-exercise-type', 'strength')
                ->select('@new-exercise-category', 'Pectoraux')
                ->click('@submit-new-exercise');
            $browser->waitUntilMissing('[role="dialog"]', 20);

            // 3c. Add Recommender Exercise (TESTING VALUES FROM LAST WORKOUT)
            $browser->clickWhenSettled('[dusk="add-exercise-existing"]', 15);
            $browser->waitFor('input[placeholder="Rechercher..."]', 15)->type('input[placeholder="Rechercher..."]', 'Recommender Ex')
                ->waitFor('@select-exercise-'.$recommenderEx->id, 20)->click('@select-exercise-'.$recommenderEx->id);
            $browser->waitUntilMissing('[role="dialog"]', 15);

            // Wait for all cards to be present and stabilize
            $browser->waitFor('@exercise-card-4', 25)
                ->waitForServerIds()
                ->assertSee('BRAND NEW EXERCISE')
                ->assertSee('RECOMMENDER EX');

            // 4. Fill Strength set
            $html = $browser->script('return document.body.innerHTML;')[0];
            file_put_contents(storage_path('logs/dusk_html_dump.html'), $html);

            $browser->waitFor('@add-set-0', 15);
            $browser->script("document.querySelector('[dusk=\"add-set-0\"]').scrollIntoView({block: 'center'});");
            $browser->click('[dusk="add-set-0"]')
                ->waitFor('@weight-input-0-0', 15);

            /*
             * On attend que la serie existe VRAIMENT avant de taper dedans.
             *
             * Sans cela, la frappe tombe pendant que la creation est en vol, et
             * la CI perdait une des deux valeurs — le poids ou les repetitions
             * selon le moment. L'etat releve a l'echec le disait sans ambiguite :
             * « poids=80 reps=null validee=oui ». La validation etait arrivee,
             * donc le vidage des ecritures en attente avait bien eu lieu, et il
             * ne portait pas les repetitions : la frappe n'avait jamais atteint
             * le modele.
             *
             * Ce n'est PAS un contournement du defaut : taper pendant la creation
             * doit marcher, et #1489 reste ouverte pour ca. Mais ce parcours-la
             * teste une seance complete, pas la course a la creation —
             * WorkoutSyncRaceTest couvre cette famille. Lui faire porter les deux
             * rendait ses echecs illisibles, et bloquait toutes les PR pour un
             * defaut sans rapport avec elles.
             */
            $this->waitForDatabase(
                fn (): bool => Set::query()
                    ->whereHas('workoutLine', fn ($ligne) => $ligne
                        ->where('workout_id', $workout->id)
                        ->where('exercise_id', $strengthEx->id))
                    ->exists(),
                message: 'la série de force n\'a jamais été créée en base'
            );

            $browser->type('@weight-input-0-0', '80')
                ->type('@reps-input-0-0', '5');

            // 5. Fill Cardio set
            $browser->waitFor('@add-set-1', 15);
            $browser->script("document.querySelector('[dusk=\"add-set-1\"]').scrollIntoView({block: 'center'});");
            $browser->click('[dusk="add-set-1"]')
                ->waitFor('@distance-input-1-0', 15)
                ->type('@distance-input-1-0', '5.5')
                ->pickDuration('@duration-input-1-0', 0, 25, 30);

            // 6. Fill Timed set
            $browser->waitFor('@add-set-2', 15);
            $browser->script("document.querySelector('[dusk=\"add-set-2\"]').scrollIntoView({block: 'center'});");
            $browser->click('[dusk="add-set-2"]')
                ->waitFor('@duration-input-2-0', 15)
                ->pickDuration('@duration-input-2-0', 0, 10, 0);

            // 6b. Verify RECOMMENDED values for Recommender Ex (Index 4)
            // It should be 110kg x 3 reps (most frequent in the LAST workout)
            $browser->waitFor('@add-set-4', 15);
            $browser->script("document.querySelector('[dusk=\"add-set-4\"]').scrollIntoView({block: 'center'});");
            $browser->click('[dusk="add-set-4"]')
                ->waitFor('@weight-input-4-0', 15)
                ->assertInputValue('@weight-input-4-0', '110')
                ->assertInputValue('@reps-input-4-0', '3');

            // 6c. Add an extra set to Strength Ex, then delete it
            $browser->script("document.querySelector('[dusk=\"add-set-0\"]').scrollIntoView({block: 'center'});");
            $browser->click('[dusk="add-set-0"]')
                ->waitFor('@weight-input-0-1', 15)
                ->type('@weight-input-0-1', '85')
                ->type('@reps-input-0-1', '3');

            // Debounced write: the set is in the database before it is deleted.
            $this->waitForDatabase(fn (): bool => Set::query()->where('weight', 85)->where('reps', 3)->exists());

            /**
             * The row's own delete is hidden at this width — a phone swipes
             * instead — so this goes through the swipe action, reached the way a
             * keyboard reaches it. A flick is not something WebDriver can
             * perform, but focusing the action opens the row, which is exactly
             * what SwipeableRow does so the gesture is not the only way in.
             *
             * Focus comes before waitFor: the action rests at opacity 0, so it
             * is present long before it is visible.
             */
            $browser->script(
                "document.querySelector('[dusk=\"swipe-remove-set-0-1\"]').scrollIntoView({block: 'center'});"
                ."document.querySelector('[dusk=\"swipe-remove-set-0-1\"]').focus();"
            );

            $browser->waitFor('@swipe-remove-set-0-1', 10)
                ->click('@swipe-remove-set-0-1')
                ->waitUntilMissing('@weight-input-0-1', 15);

            // 6d. Modify workout settings
            $browser->clickWhenSettled('[dusk="workout-settings-button"]');
            $browser->waitFor('@workout-name-input', 15)
                ->clear('@workout-name-input')
                ->type('@workout-name-input', 'Workout Updated')
                ->click('@save-settings-button')
                ->waitUntilMissing('[role="dialog"]', 15)
                ->waitForText('WORKOUT UPDATED', 15);

            // 6e. Delete Cardio exercise (Index 1)
            $browser->script("document.querySelector('[dusk=\"remove-line-1\"]').scrollIntoView({block: 'center'});");

            // Get the stable ID of the cardio line before deleting it
            $cardioLineId = $browser->attribute('[dusk="exercise-card-1"]', 'data-line-id');

            $browser->click('@remove-line-1')
                ->waitFor('@confirm-delete-button', 15)
                ->clickWhenSettled('[dusk="confirm-delete-button"]')
                ->waitUntilMissing("[dusk-id=\"exercise-line-{$cardioLineId}\"]", 15);

            $browser->within('@exercise-list', function (Browser $list) use ($cardioLineId): void {
                $list->assertDontSee('CARDIO EX');
                $list->assertMissing("[dusk-id=\"exercise-line-{$cardioLineId}\"]");
            });

            // 7. Complete one set and verify PR trophy
            $browser->clickWhenSettled('[dusk="complete-set-0-0"]');

            // Le trophée était auparavant enveloppé dans un try/catch, au motif d'un
            // "job asynchrone". Il n'y en a pas : QUEUE_CONNECTION vaut sync en test,
            // le record est donc calculé dans la requête elle-même. Le pause(3000)
            // ne couvrait qu'un aléa de rendu, et l'assertion avalée ne vérifiait
            // plus rien sur aucun viewport.
            //
            // On attend le fait métier — le record écrit en base — puis on EXIGE le
            // badge : si la ligne existe et que le badge manque, c'est un vrai défaut
            // d'affichage, et le test doit échouer.
            //
            // L'attente porte sur la COMPLÉTION de la série, pas sur l'existence
            // d'un record.
            //
            // Attendre un record ne gardait rien, et l'assertion ci-dessous le
            // prouve plutôt que de le raconter : il en existe déjà pour cet
            // utilisateur avant même que le navigateur s'ouvre, les séances
            // passées semées en tête de test ayant des sets à 100, 110 et 120 kg.
            //
            // Une attente déjà satisfaite rend la main immédiatement, si bien que
            // les deux temporisations que ce bloc croyait enchaîner — d'abord
            // l'écriture, ensuite le rendu — se réduisaient à une seule course de
            // 15 s contre l'aller-retour réseau. C'est ce qui a fait échouer le
            // viewport iphone mini sur une passe froide : « Waited 15 seconds for
            // selector [@pr-trophy-0-0] ».
            //
            // Restreindre l'attente à CETTE séance n'aurait pas suffi non plus,
            // et c'était mon premier correctif : `Set::saved` calcule les records
            // dès qu'un poids et des répétitions sont présents, sans attendre la
            // validation, donc taper « 80 » à l'étape 4 en crée déjà un. Ce fait
            // n'est PAS asséré : le moment où l'écriture débouncée atterrit n'est
            // pas déterministe, et l'assertion a fait rougir main une fois — poser
            // une condition de course pour documenter une condition de course
            // était une mauvaise idée.
            //
            // La complétion de CETTE série, en revanche, ne peut venir que du clic
            // ci-dessus : c'est la seule de cet exercice, l'autre ayant été
            // supprimée à l'étape 6c. C'est donc elle qu'on attend.
            $this->assertTrue(
                PersonalRecord::query()->where('user_id', $user->id)->exists(),
                'des records existent déjà pour cet utilisateur : les attendre ne garderait rien'
            );

            /*
             * Les valeurs font partie de l'attente, et pas seulement la coche.
             *
             * `toggleSetCompletion` vide les ecritures en attente AVANT d'envoyer
             * la validation : a ce point, le poids et les repetitions saisis a
             * l'etape 4 doivent donc etre en base. Les exiger ici sert de
             * diagnostic — sans eux, le trophee ne peut pas apparaitre, puisque
             * `shouldSkipSync()` ecarte toute serie sans poids OU sans
             * repetitions, et l'echec se lisait alors comme un probleme
             * d'affichage.
             *
             * Les captures d'echec de la CI l'ont montre : sur un viewport le
             * poids etait la et les repetitions vides, sur l'autre l'inverse.
             * Une des deux valeurs se perdait. Le trophee manquant n'etait que le
             * symptome — voir l'issue ouverte a ce sujet.
             *
             * Ne pas remonter cette attente a l'etape 4 : les valeurs y sont
             * encore des brouillons cote client, et rien ne promet qu'elles
             * soient ecrites avant la validation.
             */
            $this->waitForDatabase(
                fn (): bool => Set::query()
                    ->whereHas('workoutLine', fn ($ligne) => $ligne
                        ->where('workout_id', $workout->id)
                        ->where('exercise_id', $strengthEx->id))
                    ->where('is_completed', true)
                    ->where('weight', 80)
                    ->where('reps', 5)
                    ->exists(),
                message: 'la série validée n\'a pas atteint la base avec son poids et ses répétitions',
                etatAuMomentDeLEchec: fn (): string => Set::query()
                    ->whereHas('workoutLine', fn ($ligne) => $ligne
                        ->where('workout_id', $workout->id)
                        ->where('exercise_id', $strengthEx->id))
                    ->get(['id', 'weight', 'reps', 'is_completed'])
                    ->map(fn (Set $serie): string => sprintf(
                        'série %d : poids=%s reps=%s validée=%s',
                        $serie->id,
                        $serie->weight ?? 'null',
                        $serie->reps ?? 'null',
                        $serie->is_completed ? 'oui' : 'non',
                    ))
                    ->whenEmpty(fn (): \Illuminate\Support\Collection => collect(['aucune série pour cet exercice']))
                    ->implode(' | '),
            );

            $browser->waitFor('@pr-trophy-0-0', 15);

            $browser->waitFor('[dusk="skip-rest-timer"]', 20)
                ->click('[dusk="skip-rest-timer"]')
                ->waitUntilMissing('[dusk="skip-rest-timer"]', 15);

            // 8. Finish Workout
            $browser->clickWhenSettled('[dusk="finish-workout-mobile"]', 20);

            $browser->waitFor('@finish-workout-modal-title', 15)
                ->clickWhenSettled('#confirm-finish-button');

            $browser->waitUsing(15, 500, fn (): bool => \App\Models\Workout::findOrFail($workout->id)->ended_at !== null);

            $browser->visit('/dashboard')
                ->waitFor('#dashboard-header', 30)
                ->assertSee('RETOUR')
                ->assertNoConsoleExceptions();
        } catch (\Exception $e) {
            $browser->screenshot('workout-failure-'.$sizeMacro);
            throw $e;
        }
    }

    public function test_workout_session_on_iphone_mini(): void
    {
        $this->browse(function (Browser $browser): void {
            $this->performFullWorkout($browser, 'resizeToIphoneMini');
        });
    }

    public function test_workout_session_on_iphone_15(): void
    {
        $this->browse(function (Browser $browser): void {
            $this->performFullWorkout($browser, 'resizeToIphone15');
        });
    }

    public function test_workout_session_on_iphone_max(): void
    {
        $this->browse(function (Browser $browser): void {
            $this->performFullWorkout($browser, 'resizeToIphoneMax');
        });
    }
}
