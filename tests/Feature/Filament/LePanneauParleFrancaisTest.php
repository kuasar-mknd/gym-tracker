<?php

declare(strict_types=1);

use App\Filament\Resources\Achievements\Pages\CreateAchievement;
use App\Filament\Resources\Achievements\Pages\EditAchievement;
use App\Filament\Resources\Achievements\Pages\ListAchievements;
use App\Filament\Resources\Exercises\Pages\CreateExercise;
use App\Filament\Resources\Exercises\Pages\EditExercise;
use App\Filament\Resources\Exercises\Pages\ListExercises;
use App\Filament\Resources\Goals\Pages\CreateGoal;
use App\Filament\Resources\Goals\Pages\EditGoal;
use App\Filament\Resources\Goals\Pages\ListGoals;
use App\Filament\Resources\Supplements\Pages\CreateSupplement;
use App\Filament\Resources\Supplements\Pages\EditSupplement;
use App\Filament\Resources\Supplements\Pages\ListSupplements;
use App\Filament\Resources\Supplements\SupplementResource;
use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Filament\Resources\Workouts\Pages\CreateWorkout;
use App\Filament\Resources\Workouts\Pages\EditWorkout;
use App\Filament\Resources\Workouts\Pages\ListWorkouts;
use App\Filament\Widgets\RecentUsersTable;
use App\Models\Achievement;
use App\Models\Exercise;
use App\Models\Goal;
use App\Models\Supplement;
use App\Models\User;
use App\Models\Workout;
use Illuminate\Database\Eloquent\Model;
use Livewire\Livewire;
use Tests\Support\FilamentAdminPanel;

/*
 * Le panneau était en français pour ce que Filament traduit lui-même, et en
 * anglais pour le reste : une colonne ou un champ sans `->label()` reçoit son
 * nom de colonne mis en forme (« Default rest time », « Current streak »), et
 * certains libellés étaient écrits en anglais à la main (« Email address »,
 * options « Weight », « Frequency », « Measurement ») (#1979).
 *
 * Chaque page de liste, de création et de modification est rendue ici avec
 * une ligne, colonnes masquées comprises, et son HTML ne doit plus porter
 * aucun de ces libellés.
 */

/**
 * Les libellés anglais que le panneau affichait. « Dosage », « Notes »,
 * « Type » et « Avatar » s'écrivent de même en français et n'y sont pas.
 *
 * @return list<string>
 */
function panneauLibellesAnglais(): array
{
    return [
        'Email address', 'Default rest time', 'Email verified at', 'Provider id', 'Current streak',
        'Longest streak', 'Last workout at', 'Created at', 'Updated at', 'Target value', 'Current value',
        'Start value', 'Measurement type', 'Completed at', 'Servings remaining', 'Low stock threshold',
        'Started at', 'Ended at', '>Name<', '>Title<', '>Brand<', '>Category<', '>Threshold<',
        '>Icon<', '>Slug<', '>Deadline<', '>Password<', '>Provider<', '>Exercise<', '>User<',
        '>Weight<', '>Frequency<', '>Measurement<', '>Strength<', '>Timed<', 'Registration Date', 'Last Activity',
        'Suppléments', 'Supplément',
    ];
}

/**
 * Les pages de chaque ressource, avec la fabrique de leur ligne.
 *
 * @return array<string, array{0: class-string<Model>, 1: class-string, 2: class-string, 3: class-string}>
 */
function panneauRessources(): array
{
    return [
        'User' => [User::class, ListUsers::class, CreateUser::class, EditUser::class],
        'Achievement' => [Achievement::class, ListAchievements::class, CreateAchievement::class, EditAchievement::class],
        'Exercise' => [Exercise::class, ListExercises::class, CreateExercise::class, EditExercise::class],
        'Goal' => [Goal::class, ListGoals::class, CreateGoal::class, EditGoal::class],
        'Supplement' => [Supplement::class, ListSupplements::class, CreateSupplement::class, EditSupplement::class],
        'Workout' => [Workout::class, ListWorkouts::class, CreateWorkout::class, EditWorkout::class],
    ];
}

/**
 * Le HTML débarrassé de ce que la mise en forme ajoute entre une balise et son
 * texte, pour que `>Name<` se lise même entouré de blancs.
 */
function panneauHtmlResserre(string $html): string
{
    return (string) preg_replace(['/>\s+/', '/\s+</'], ['>', '<'], $html);
}

it('affiche en français les colonnes, les champs et les options de chaque ressource', function (string $ressource): void {
    [$modele, $liste, $creation, $modification] = panneauRessources()[$ressource];
    $this->actingAs(FilamentAdminPanel::admin(FilamentAdminPanel::crudPermissions($ressource)), 'admin');
    $ligne = $modele::factory()->create();

    $pages = [
        // Les colonnes masquées par défaut (créé le, modifié le) se montrent aussi.
        'liste' => Livewire::test($liste)->toggleAllTableColumns()->html(),
        'création' => Livewire::test($creation)->html(),
        'modification' => Livewire::test($modification, ['record' => $ligne->getKey()])->html(),
    ];

    foreach ($pages as $page => $html) {
        $resserre = panneauHtmlResserre($html);

        foreach (panneauLibellesAnglais() as $libelle) {
            expect(str_contains($resserre, $libelle))->toBeFalse("{$ressource}, page de {$page} : « {$libelle} »");
        }
    }
})->with(array_keys(panneauRessources()));

it('nomme les types d’objectif et d’exercice en français, dans les options comme dans la liste', function (): void {
    $this->actingAs(FilamentAdminPanel::admin([
        ...FilamentAdminPanel::crudPermissions('Goal'),
        ...FilamentAdminPanel::crudPermissions('Exercise'),
    ]), 'admin');
    Goal::factory()->create(['type' => 'frequency']);
    Exercise::factory()->create(['type' => 'timed']);

    expect(panneauHtmlResserre(Livewire::test(ListGoals::class)->html()))->toContain('Fréquence (Séances)')
        ->and(panneauHtmlResserre(Livewire::test(ListExercises::class)->html()))->toContain('>Temps<');

    $champs = Livewire::test(CreateGoal::class)->instance()->form->getFlatFields();
    expect($champs['type']->getOptions())->toBe([
        'weight' => 'Force (Poids max)',
        'frequency' => 'Fréquence (Séances)',
        'volume' => 'Volume (Max par séance)',
        'measurement' => 'Mensuration',
    ]);

    $champs = Livewire::test(CreateExercise::class)->instance()->form->getFlatFields();
    expect($champs['type']->getOptions())->toBe(['strength' => 'Force', 'cardio' => 'Cardio', 'timed' => 'Temps']);
});

it('appelle « Compléments » la ressource des compléments, comme l’application', function (): void {
    expect(SupplementResource::getModelLabel())->toBe('Complément')
        ->and(SupplementResource::getPluralModelLabel())->toBe('Compléments')
        ->and(SupplementResource::getNavigationLabel())->toBe('Compléments');
});

it('affiche en français l’encart des derniers inscrits', function (): void {
    $this->actingAs(FilamentAdminPanel::admin(FilamentAdminPanel::crudPermissions('User')), 'admin');
    User::factory()->create();

    $html = panneauHtmlResserre(Livewire::test(RecentUsersTable::class)->html());

    expect($html)->toContain('Inscription', 'Dernière activité', 'Adresse e-mail');

    foreach (panneauLibellesAnglais() as $libelle) {
        expect($html)->not->toContain($libelle);
    }
});
