<?php

declare(strict_types=1);

use App\Enums\ExerciseCategory;
use App\Filament\Resources\Achievements\Pages\EditAchievement;
use App\Filament\Resources\Exercises\Pages\EditExercise;
use App\Filament\Resources\Goals\Pages\EditGoal;
use App\Filament\Resources\Supplements\Pages\EditSupplement;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Workouts\Pages\EditWorkout;
use App\Models\Achievement;
use App\Models\Exercise;
use App\Models\Goal;
use App\Models\Supplement;
use App\Models\User;
use App\Models\Workout;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Exceptions;
use Livewire\Livewire;
use Tests\Support\FilamentAdminPanel;

/*
 * Un formulaire du panneau qui écrivait plus que sa colonne (#2071) : un
 * texte plus long que ses 255 caractères, une note ou une description de
 * quatre octets par caractère au-delà d'un `TEXT`, un nombre plus grand
 * qu'un entier, une catégorie d'exercice qu'aucune `ExerciseCategory` ne lit.
 * L'enregistrement répondait 500 ; chacun est refusé sur son champ, la ligne
 * telle qu'elle était. `LesChampsDuPanneauTiennentDansLeursColonnesTest`
 * confronte chaque champ à sa colonne.
 */

beforeEach(function (): void {
    Exceptions::fake();
});

it('refuse sur son champ ce que la colonne du panneau ne tient pas', function (string $page, string $modele, string $champ, string $valeur): void {
    $this->actingAs(FilamentAdminPanel::admin(FilamentAdminPanel::crudPermissions(class_basename($modele))), 'admin');
    /** @var class-string<Model> $modele */
    $ligne = Factory::factoryForModel($modele)->createOne();
    $avant = $ligne->fresh()?->getRawOriginal($champ);

    Livewire::test($page, ['record' => $ligne->getKey()])
        ->fillForm([$champ => $valeur])
        ->call('save')
        ->assertHasFormErrors([$champ]);

    expect($ligne->fresh()?->getRawOriginal($champ))->toBe($avant);
    Exceptions::assertNothingReported();
})->with(function (): array {
    $long = str_repeat('a', 256);
    $texteDeQuatreOctets = str_repeat("\u{1F600}", 20_000);

    return [
        'identifiant d’un badge' => [EditAchievement::class, Achievement::class, 'slug', $long],
        'nom d’un badge' => [EditAchievement::class, Achievement::class, 'name', $long],
        'description d’un badge' => [EditAchievement::class, Achievement::class, 'description', $texteDeQuatreOctets],
        'icône d’un badge' => [EditAchievement::class, Achievement::class, 'icon', $long],
        'type d’un badge' => [EditAchievement::class, Achievement::class, 'type', $long],
        'catégorie d’un badge' => [EditAchievement::class, Achievement::class, 'category', $long],
        'nom d’un exercice' => [EditExercise::class, Exercise::class, 'name', $long],
        'repos d’un exercice' => [EditExercise::class, Exercise::class, 'default_rest_time', '1e30'],
        'catégorie inconnue d’un exercice' => [EditExercise::class, Exercise::class, 'category', 'Inconnue'],
        'titre d’un objectif' => [EditGoal::class, Goal::class, 'title', $long],
        'mensuration d’un objectif' => [EditGoal::class, Goal::class, 'measurement_type', $long],
        'nom d’un complément' => [EditSupplement::class, Supplement::class, 'name', $long],
        'marque d’un complément' => [EditSupplement::class, Supplement::class, 'brand', $long],
        'dosage d’un complément' => [EditSupplement::class, Supplement::class, 'dosage', $long],
        'doses restantes d’un complément' => [EditSupplement::class, Supplement::class, 'servings_remaining', '1e30'],
        'seuil de stock d’un complément' => [EditSupplement::class, Supplement::class, 'low_stock_threshold', (string) PHP_INT_MAX],
        'nom d’un compte' => [EditUser::class, User::class, 'name', $long],
        'avatar d’un compte' => [EditUser::class, User::class, 'avatar', $long],
        'repos d’un compte' => [EditUser::class, User::class, 'default_rest_time', '-1e30'],
        'nom d’une séance' => [EditWorkout::class, Workout::class, 'name', $long],
        'notes d’une séance' => [EditWorkout::class, Workout::class, 'notes', $texteDeQuatreOctets],
    ];
});

it('écrit encore une catégorie d’exercice de l’application et un repos d’une heure', function (): void {
    $this->actingAs(FilamentAdminPanel::admin(FilamentAdminPanel::crudPermissions('Exercise')), 'admin');
    $exercice = Exercise::factory()->create();

    Livewire::test(EditExercise::class, ['record' => $exercice->getKey()])
        ->fillForm(['category' => ExerciseCategory::Epaules->value, 'default_rest_time' => Exercise::REPOS_MAX_SECONDES])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($exercice->fresh()?->category)->toBe(ExerciseCategory::Epaules)
        ->and($exercice->fresh()?->default_rest_time)->toBe(Exercise::REPOS_MAX_SECONDES);
    Exceptions::assertNothingReported();
});
