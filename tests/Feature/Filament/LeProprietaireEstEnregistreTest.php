<?php

declare(strict_types=1);

use App\Filament\Resources\Exercises\Pages\CreateExercise;
use App\Filament\Resources\Exercises\Pages\EditExercise;
use App\Filament\Resources\Goals\Pages\CreateGoal;
use App\Filament\Resources\Goals\Pages\EditGoal;
use App\Filament\Resources\Workouts\Pages\CreateWorkout;
use App\Filament\Resources\Workouts\Pages\EditWorkout;
use App\Filament\Resources\Workouts\Pages\ListWorkouts;
use App\Models\Exercise;
use App\Models\Set;
use App\Models\User;
use App\Models\Workout;
use App\Models\WorkoutLine;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;
use Tests\Support\FilamentAdminPanel;

/*
 * Le chemin nominal que #1352 disait impossible à écrire.
 *
 * `user_id` est exposé au formulaire mais absent du `$fillable` de ces trois
 * modèles. Hors production, le mode strict faisait lever et rien n'était
 * enregistré — d'où l'impossibilité d'écrire ces tests. EN PRODUCTION, le champ
 * était ignoré en silence : l'exploitant voyait une notification de succès et
 * la ligne se retrouvait sans propriétaire.
 *
 * Les pages l'affectent désormais explicitement, sans élargir le `$fillable` —
 * qui vaut pour tous les chemins d'assignation en masse, y compris ceux qui
 * partent d'une requête utilisateur.
 */

it('enregistre le propriétaire choisi à la création d’un exercice', function (): void {
    $this->actingAs(FilamentAdminPanel::admin(FilamentAdminPanel::crudPermissions('Exercise')), 'admin');

    $proprietaire = User::factory()->create();

    Livewire::test(CreateExercise::class)
        ->fillForm([
            'name' => 'Soulevé de terre roumain',
            'type' => 'strength',
            'category' => 'Jambes',
            'default_rest_time' => 120,
            'user_id' => $proprietaire->getKey(),
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $this->assertDatabaseHas(Exercise::class, [
        'name' => 'Soulevé de terre roumain',
        'user_id' => $proprietaire->getKey(),
    ]);
});

it('enregistre le changement de propriétaire à l’édition', function (): void {
    $this->actingAs(FilamentAdminPanel::admin(FilamentAdminPanel::crudPermissions('Exercise')), 'admin');

    $avant = User::factory()->create();
    $apres = User::factory()->create();
    $exercise = Exercise::factory()->create(['user_id' => $avant->getKey()]);

    Livewire::test(EditExercise::class, ['record' => $exercise->getKey()])
        ->fillForm(['user_id' => $apres->getKey()])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($exercise->refresh()->user_id)->toBe($apres->getKey());
});

it('enregistre le propriétaire choisi à la création d’un objectif', function (): void {
    $this->actingAs(FilamentAdminPanel::admin(FilamentAdminPanel::crudPermissions('Goal')), 'admin');

    $proprietaire = User::factory()->create();

    Livewire::test(CreateGoal::class)
        ->fillForm([
            'user_id' => $proprietaire->getKey(),
            'title' => 'Descendre à 78 kg',
            'type' => 'measurement',
            'measurement_type' => 'weight',
            'start_value' => 82,
            'current_value' => 82,
            'target_value' => 78,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $this->assertDatabaseHas(\App\Models\Goal::class, [
        'title' => 'Descendre à 78 kg',
        'user_id' => $proprietaire->getKey(),
    ]);
});

it('enregistre le propriétaire choisi à la création d’une séance', function (): void {
    $this->actingAs(FilamentAdminPanel::admin(FilamentAdminPanel::crudPermissions('Workout')), 'admin');

    $proprietaire = User::factory()->create();

    Livewire::test(CreateWorkout::class)
        ->fillForm([
            'user_id' => $proprietaire->getKey(),
            'name' => 'Séance du matin',
            'started_at' => now()->toDateTimeString(),
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $this->assertDatabaseHas(\App\Models\Workout::class, [
        'name' => 'Séance du matin',
        'user_id' => $proprietaire->getKey(),
    ]);
});

/*
 * L'edition des deux autres ressources, laissee de cote au premier passage.
 *
 * `handleRecordUpdate` est ecrit trois fois — une par ressource — et n'etait
 * couvert que pour les exercices : la mesure de couverture donnait 33 % sur
 * `EditWorkout`, exactement les lignes ajoutees. Trois copies d'une meme regle
 * dont une seule est tenue, c'est deux qui derivent.
 *
 * La seance a cesse d'en etre une copie avec #1933 : son proprietaire est fixe
 * a la creation, et son cas prouve desormais l'inverse.
 */
it('enregistre le changement de propriétaire d’un objectif', function (): void {
    $this->actingAs(FilamentAdminPanel::admin(FilamentAdminPanel::crudPermissions('Goal')), 'admin');

    $avant = User::factory()->create();
    $apres = User::factory()->create();
    $goal = \App\Models\Goal::factory()->create(['user_id' => $avant->getKey()]);

    Livewire::test(EditGoal::class, ['record' => $goal->getKey()])
        ->fillForm(['user_id' => $apres->getKey()])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($goal->refresh()->user_id)->toBe($apres->getKey());
});

/*
 * Changer le proprietaire d'une seance laissait ses lignes et ses series a
 * l'ancien compte (#1933). Le champ est desactive a la modification, mais
 * Livewire accepte toujours qu'on ecrive sa valeur : `set()` fait ici ce que
 * ferait une requete forgee. Le reste du formulaire s'enregistre, le
 * proprietaire ne bouge pas, ni sur la seance ni sur ses copies.
 */
it('ne change pas le propriétaire d’une séance, même par une requête forgée', function (): void {
    $this->actingAs(FilamentAdminPanel::admin(FilamentAdminPanel::crudPermissions('Workout')), 'admin');

    $avant = User::factory()->create();
    $apres = User::factory()->create();
    $workout = Workout::factory()->create(['user_id' => $avant->getKey(), 'name' => 'Avant']);
    $ligne = WorkoutLine::factory()->create(['workout_id' => $workout->getKey()]);
    $serie = Set::factory()->create(['workout_line_id' => $ligne->getKey()]);

    Livewire::test(EditWorkout::class, ['record' => $workout->getKey()])
        ->set('data.user_id', $apres->getKey())
        ->set('data.name', 'Après')
        ->call('save')
        ->assertHasNoFormErrors();

    $workout->refresh();

    expect($workout->name)->toBe('Après')
        ->and($workout->user_id)->toBe($avant->getKey())
        ->and($ligne->refresh()->user_id)->toBe($avant->getKey())
        ->and($serie->refresh()->user_id)->toBe($avant->getKey());
});

/*
 * Le second chemin Livewire qui ecrit une seance : l'action de modification de
 * la table des seances. L'interface la rend en lien vers la page de
 * modification, mais un client peut la monter et la soumettre directement, et
 * elle enregistre par `$record->update($data)` sans passer par
 * `EditWorkout::handleRecordUpdate()`. Elle partage le formulaire de la page :
 * c'est la desactivation du champ dans `WorkoutForm` qui la garde, pas la page.
 * Si cette desactivation quittait le formulaire pour la seule page, ce cas
 * tomberait, sur l'assignation en masse refusee ou sur l'exception du modele.
 */
it('ne change pas le propriétaire d’une séance par l’action de modification de la table', function (): void {
    $this->actingAs(FilamentAdminPanel::admin(FilamentAdminPanel::crudPermissions('Workout')), 'admin');

    $avant = User::factory()->create();
    $apres = User::factory()->create();
    $workout = Workout::factory()->create(['user_id' => $avant->getKey(), 'name' => 'Avant']);
    $ligne = WorkoutLine::factory()->create(['workout_id' => $workout->getKey()]);
    $serie = Set::factory()->create(['workout_line_id' => $ligne->getKey()]);

    Livewire::test(ListWorkouts::class)
        ->callAction(TestAction::make('edit')->table($workout), [
            'user_id' => $apres->getKey(),
            'name' => 'Après',
        ])
        ->assertHasNoFormErrors();

    $workout->refresh();

    expect($workout->name)->toBe('Après')
        ->and($workout->user_id)->toBe($avant->getKey())
        ->and($ligne->refresh()->user_id)->toBe($avant->getKey())
        ->and($serie->refresh()->user_id)->toBe($avant->getKey());
});
