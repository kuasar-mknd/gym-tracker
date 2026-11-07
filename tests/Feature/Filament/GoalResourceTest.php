<?php

declare(strict_types=1);

use App\Enums\GoalType;
use App\Filament\Resources\Goals\GoalResource;
use App\Filament\Resources\Goals\Pages\CreateGoal;
use App\Filament\Resources\Goals\Pages\EditGoal;
use App\Filament\Resources\Goals\Pages\ListGoals;
use App\Http\Controllers\GoalController;
use App\Models\Exercise;
use App\Models\Goal;
use App\Models\Set;
use App\Models\User;
use App\Models\Workout;
use App\Models\WorkoutLine;
use Filament\Actions\Testing\TestAction;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Select;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Support\FilamentAdminPanel;

beforeEach(function (): void {
    $this->actingAs(FilamentAdminPanel::admin(FilamentAdminPanel::crudPermissions('Goal')), 'admin');
});

it('liste les objectifs avec leur propriétaire, leur type et leurs valeurs', function (): void {
    $owner = User::factory()->create(['name' => 'Objectif Owner']);
    $exercise = Exercise::factory()->create(['name' => 'Squat']);
    $goal = Goal::factory()->create([
        'user_id' => $owner->getKey(),
        'exercise_id' => $exercise->getKey(),
        'title' => 'Atteindre 100 kg au squat',
        'type' => 'weight',
        'target_value' => 100,
        'current_value' => 80,
        'start_value' => 60,
    ]);

    Livewire::test(ListGoals::class)
        ->assertCanSeeTableRecords([$goal])
        ->assertTableColumnStateSet('user.name', 'Objectif Owner', $goal)
        ->assertTableColumnStateSet('exercise.name', 'Squat', $goal)
        ->assertTableColumnStateSet('title', 'Atteindre 100 kg au squat', $goal)
        ->assertTableColumnStateSet('type', GoalType::Weight, $goal)
        ->assertTableColumnStateSet('target_value', 100.0, $goal)
        ->assertTableColumnStateSet('current_value', 80.0, $goal)
        ->assertTableColumnStateSet('start_value', 60.0, $goal);
});

it('trie les objectifs par valeur cible', function (): void {
    $small = Goal::factory()->create(['target_value' => 10]);
    $big = Goal::factory()->create(['target_value' => 900]);
    $mid = Goal::factory()->create(['target_value' => 400]);

    Livewire::test(ListGoals::class)
        ->sortTable('target_value', 'desc')
        ->assertCanSeeTableRecords([$big, $mid, $small], inOrder: true);
});

it('cherche un objectif par son titre', function (): void {
    $wanted = Goal::factory()->create(['title' => 'Perdre 5 kilos']);
    $unwanted = Goal::factory()->create(['title' => 'Courir un semi-marathon']);

    Livewire::test(ListGoals::class)
        ->searchTable('Perdre 5 kilos')
        ->assertCanSeeTableRecords([$wanted])
        ->assertCanNotSeeTableRecords([$unwanted]);
});

it('refuse un objectif sans utilisateur, sans titre, sans type ni cible', function (): void {
    $before = Goal::query()->count();

    Livewire::test(CreateGoal::class)
        ->fillForm([
            'user_id' => null,
            'title' => null,
            'type' => null,
            'target_value' => null,
            'current_value' => 0,
            'start_value' => 0,
        ])
        ->call('create')
        ->assertHasFormErrors([
            'user_id' => 'required',
            'title' => 'required',
            'type' => 'required',
            'target_value' => 'required',
        ]);

    expect(Goal::query()->count())->toBe($before);
});

it("refuse un type d'objectif hors de la liste proposée", function (): void {
    $owner = User::factory()->create();

    Livewire::test(CreateGoal::class)
        ->fillForm([
            'user_id' => $owner->getKey(),
            'title' => 'Objectif exotique',
            'type' => 'astrologie',
            'target_value' => 10,
            'current_value' => 0,
            'start_value' => 0,
        ])
        ->call('create')
        ->assertHasFormErrors(['type']);

    expect(Goal::query()->where('title', 'Objectif exotique')->exists())->toBeFalse();
});

/*
 * Un objectif de mensuration suit une mensuration : sans elle, il ne suit
 * rien (#2132).
 *
 * `GoalStoreRequest` l'exige (`required_if:type,measurement`), mais le
 * panneau n'y passe pas : la mensuration y était facultative quel que soit le
 * type, et la valeur actuelle valait 0 par défaut. L'objectif « de 80 à 75 »
 * ainsi créé affichait une barre pleine au premier recalcul, et ne s'achevait
 * jamais. La création, la page de modification et l'action de la table
 * partagent le formulaire : les trois refusent.
 */
dataset('mensurations absentes d’un objectif du panneau', [
    'sans mensuration' => [null],
    'mensuration vide' => [''],
    'mensuration faite d’espaces' => ['   '],
]);

it('exige la mensuration d’un objectif de mensuration créé au panneau', function (?string $mensuration): void {
    $owner = User::factory()->create();

    Livewire::test(CreateGoal::class)
        ->fillForm([
            'user_id' => $owner->getKey(),
            'title' => 'Descendre à 75',
            'type' => 'measurement',
            'measurement_type' => $mensuration,
            'target_value' => 75,
            'current_value' => 0,
            'start_value' => 80,
        ])
        ->call('create')
        ->assertHasFormErrors(['measurement_type' => 'required']);

    expect(Goal::query()->where('title', 'Descendre à 75')->exists())->toBeFalse();
})->with('mensurations absentes d’un objectif du panneau');

it('exige la mensuration d’un objectif de mensuration modifié au panneau, par la page et par la table', function (): void {
    $goal = Goal::factory()->create([
        'type' => 'measurement',
        'measurement_type' => null,
        'title' => 'Sans mensuration',
        'start_value' => 80,
        'current_value' => 0,
        'target_value' => 75,
    ]);

    Livewire::test(EditGoal::class, ['record' => $goal->getKey()])
        ->fillForm(['title' => 'Toujours sans mensuration'])
        ->call('save')
        ->assertHasFormErrors(['measurement_type' => 'required']);

    Livewire::test(ListGoals::class)
        ->callAction(TestAction::make('edit')->table($goal), ['title' => 'Toujours sans mensuration'])
        ->assertHasFormErrors(['measurement_type' => 'required']);

    expect($goal->refresh()->title)->toBe('Sans mensuration');
});

/*
 * Le type se change dans le même formulaire : un objectif de fréquence passé
 * en mensuration doit en nommer une. Le type est `live()` : le champ se
 * marque obligatoire dès le choix, sans attendre l'envoi.
 */
it('exige la mensuration quand un objectif passe en mensuration au panneau', function (): void {
    $goal = Goal::factory()->create(['type' => 'frequency', 'measurement_type' => null]);

    Livewire::test(EditGoal::class, ['record' => $goal->getKey()])
        ->assertFormFieldExists('type', fn (Field $champ): bool => $champ->isLive())
        ->assertFormFieldExists('measurement_type', fn (Field $champ): bool => ! $champ->isRequired())
        ->fillForm(['type' => 'measurement'])
        ->assertFormFieldExists('measurement_type', fn (Field $champ): bool => $champ->isRequired())
        ->call('save')
        ->assertHasFormErrors(['measurement_type' => 'required']);

    expect($goal->refresh()->type)->toBe(GoalType::Frequency);
});

/*
 * L'exigence ne vaut que pour une mensuration : un objectif de fréquence n'en
 * nomme aucune, et un objectif de mensuration qui en nomme une s'enregistre.
 */
/*
 * Le départ est de 80 : une mensuration descend vers sa cible de 75, une
 * fréquence ne descend pas, et prend une cible au moins égale à son départ
 * (#2164).
 */
it('n’exige la mensuration que d’un objectif de mensuration', function (string $type, ?string $mensuration, int $cible): void {
    $owner = User::factory()->create();

    Livewire::test(CreateGoal::class)
        ->fillForm([
            'user_id' => $owner->getKey(),
            'title' => 'Objectif bien formé',
            'type' => $type,
            'measurement_type' => $mensuration,
            'target_value' => $cible,
            'current_value' => 0,
            'start_value' => 80,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Goal::query()->where('title', 'Objectif bien formé')->sole()->measurement_type)->toBe($mensuration);
})->with([
    'fréquence, sans mensuration' => ['frequency', null, 80],
    'mensuration, tour de taille' => ['measurement', 'Waist', 75],
    'mensuration, poids de corps' => ['measurement', 'weight', 75],
]);

/*
 * Un objectif de charge ou de volume suit un exercice : sans lui, il ne suit
 * rien (#2164).
 *
 * `GoalStoreRequest` l'exige (`required_if:type,weight,volume`), le panneau
 * non : l'objectif ainsi créé gardait la valeur actuelle tapée, 0 par défaut,
 * et sous une cible plus basse que son départ, sa barre restait pleine sans
 * qu'il soit jamais atteint. La création, la page de modification et l'action
 * de la table partagent le formulaire : les trois refusent, y compris quand
 * seul le type change.
 */
it('exige l’exercice d’un objectif de charge ou de volume créé au panneau', function (string $type): void {
    $owner = User::factory()->create();

    Livewire::test(CreateGoal::class)
        ->fillForm([
            'user_id' => $owner->getKey(),
            'title' => 'Sans exercice',
            'type' => $type,
            'exercise_id' => null,
            'target_value' => 100,
            'current_value' => 0,
            'start_value' => 60,
        ])
        ->call('create')
        ->assertHasFormErrors(['exercise_id' => 'required']);

    expect(Goal::query()->where('title', 'Sans exercice')->exists())->toBeFalse();
})->with([
    'charge' => ['weight'],
    'volume' => ['volume'],
]);

it('exige l’exercice d’un objectif de charge ou de volume modifié au panneau, par la page et par la table', function (string $type): void {
    $goal = Goal::factory()->create([
        'type' => $type,
        'exercise_id' => null,
        'title' => 'Sans exercice',
        'start_value' => 60,
        'current_value' => 0,
        'target_value' => 100,
    ]);

    Livewire::test(EditGoal::class, ['record' => $goal->getKey()])
        ->fillForm(['title' => 'Toujours sans exercice'])
        ->call('save')
        ->assertHasFormErrors(['exercise_id' => 'required']);

    Livewire::test(ListGoals::class)
        ->callAction(TestAction::make('edit')->table($goal), ['title' => 'Toujours sans exercice'])
        ->assertHasFormErrors(['exercise_id' => 'required']);

    expect($goal->refresh()->title)->toBe('Sans exercice');
})->with([
    'charge' => ['weight'],
    'volume' => ['volume'],
]);

it('exige l’exercice quand un objectif passe en charge au panneau', function (): void {
    $goal = Goal::factory()->create(['type' => 'frequency', 'exercise_id' => null, 'start_value' => 0, 'target_value' => 20]);

    Livewire::test(EditGoal::class, ['record' => $goal->getKey()])
        ->assertFormFieldExists('exercise_id', fn (Field $champ): bool => ! $champ->isRequired())
        ->fillForm(['type' => 'weight'])
        ->assertFormFieldExists('exercise_id', fn (Field $champ): bool => $champ->isRequired())
        ->call('save')
        ->assertHasFormErrors(['exercise_id' => 'required']);

    expect($goal->refresh()->type)->toBe(GoalType::Frequency);
});

it('n’exige l’exercice ni d’une fréquence ni d’une mensuration', function (string $type, ?string $mensuration): void {
    $owner = User::factory()->create();

    Livewire::test(CreateGoal::class)
        ->fillForm([
            'user_id' => $owner->getKey(),
            'title' => 'Sans exercice, à raison',
            'type' => $type,
            'exercise_id' => null,
            'measurement_type' => $mensuration,
            'target_value' => 90,
            'current_value' => 0,
            'start_value' => 80,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Goal::query()->where('title', 'Sans exercice, à raison')->sole()->exercise_id)->toBeNull();
})->with([
    'fréquence' => ['frequency', null],
    'mensuration' => ['measurement', 'Waist'],
]);

/*
 * La mensuration se choisit dans la liste de l'application, sous son nom
 * français, comme `GoalStoreRequest` la ferme (`Rule::in()`) : un nom qu'aucune
 * mesure ne porte, « Taille » tapé à la place de `Waist`, ne suivait rien et
 * revenait à son départ (#2164). La liste vient de
 * `GoalController::measurementTypes()`, que les deux formulaires de
 * l'application lisent aussi ; `waist` est refusé comme la requête le refuse,
 * même si la collation de la colonne le rapprocherait de `Waist`.
 */
it('propose les mensurations de l’application, sous leur nom français', function (): void {
    $attendues = array_column(GoalController::measurementTypes(), 'label', 'value');

    Livewire::test(CreateGoal::class)
        ->assertFormFieldExists('measurement_type', fn (Select $champ): bool => $champ->getOptions() === $attendues);

    expect($attendues)->toHaveCount(15)
        ->and($attendues['Waist'])->toBe('Taille')
        ->and($attendues['weight'])->toBe('Poids de corps');
});

it('refuse au panneau une mensuration hors de la liste', function (string $mensuration): void {
    $owner = User::factory()->create();

    Livewire::test(CreateGoal::class)
        ->fillForm([
            'user_id' => $owner->getKey(),
            'title' => 'Mensuration hors liste',
            'type' => 'measurement',
            'measurement_type' => $mensuration,
            'target_value' => 80,
            'current_value' => 0,
            'start_value' => 90,
        ])
        ->call('create')
        ->assertHasFormErrors(['measurement_type' => 'in']);

    expect(Goal::query()->where('title', 'Mensuration hors liste')->exists())->toBeFalse();
})->with([
    'le nom français de Waist' => ['Taille'],
    'un nom inventé' => ['tour_de_taille'],
    'la clef dans une autre casse' => ['waist'],
]);

it('admet au panneau chacune des mensurations de la liste', function (string $mensuration): void {
    $owner = User::factory()->create();

    Livewire::test(CreateGoal::class)
        ->fillForm([
            'user_id' => $owner->getKey(),
            'title' => 'Mensuration de la liste',
            'type' => 'measurement',
            'measurement_type' => $mensuration,
            'target_value' => 80,
            'current_value' => 0,
            'start_value' => 90,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Goal::query()->where('title', 'Mensuration de la liste')->sole()->measurement_type)->toBe($mensuration);
})->with(fn (): array => GoalController::measurementTypeValues());

/*
 * L'exercice d'un objectif se choisit parmi le catalogue et les exercices du
 * compte de l'objectif, comme `GoalStoreRequest` le borne (#2166).
 *
 * Le panneau proposait les exercices de tous les comptes, et enregistrait sans
 * refus un objectif posé sur l'exercice personnel d'un autre : le recalcul lit
 * les records du compte de l'objectif, qu'il n'a pas sur cet exercice, et
 * l'objectif ne suivait rien. Les options suivent le compte choisi, qui est
 * `live()` ; sans compte, le catalogue seul. Le `Select` ne retrouve que ce
 * que sa liste filtrée contient, et refuse le reste (`in`), à la création, à
 * la page de modification et à l'action de la table, y compris quand seul le
 * compte de l'objectif change.
 */

/**
 * Deux comptes, chacun avec son exercice personnel, et un exercice du
 * catalogue.
 *
 * @return array{0: User, 1: User, 2: Exercise, 3: Exercise, 4: Exercise} le compte A, le compte B, l'exercice personnel de A, celui de B, l'exercice du catalogue
 */
function exercicesDeDeuxComptesAuPanneau(): array
{
    $compteA = User::factory()->create();
    $compteB = User::factory()->create();

    return [
        $compteA,
        $compteB,
        Exercise::factory()->create(['user_id' => $compteA->id, 'name' => 'Presse secrète de A']),
        Exercise::factory()->create(['user_id' => $compteB->id, 'name' => 'Fente de B']),
        Exercise::factory()->create(['user_id' => null, 'name' => 'Rowing de la salle']),
    ];
}

it('ne propose que le catalogue et les exercices du compte choisi', function (): void {
    [, $compteB, , $exerciceDeB, $catalogue] = exercicesDeDeuxComptesAuPanneau();
    $proposes = [];
    $releve = function (Select $champ) use (&$proposes): bool {
        $resultats = $champ->getSearchResults('de');
        ksort($resultats);
        $proposes[] = $resultats;

        return true;
    };

    Livewire::test(CreateGoal::class)
        ->assertFormFieldExists('user_id', fn (Field $champ): bool => $champ->isLive())
        ->assertFormFieldExists('exercise_id', $releve)
        ->fillForm(['user_id' => $compteB->getKey()])
        ->assertFormFieldExists('exercise_id', $releve);

    expect($proposes)->toBe([
        [$catalogue->id => 'Rowing de la salle'],
        [$exerciceDeB->id => 'Fente de B', $catalogue->id => 'Rowing de la salle'],
    ]);
});

it('refuse au panneau l’exercice personnel d’un autre compte, à la création', function (): void {
    [, $compteB, $exerciceDeA] = exercicesDeDeuxComptesAuPanneau();

    Livewire::test(CreateGoal::class)
        ->fillForm([
            'user_id' => $compteB->getKey(),
            'title' => 'Sur l’exercice de A',
            'type' => 'weight',
            'exercise_id' => $exerciceDeA->getKey(),
            'target_value' => 100,
            'current_value' => 0,
            'start_value' => 60,
        ])
        ->call('create')
        ->assertHasFormErrors(['exercise_id' => 'in']);

    expect(Goal::query()->where('title', 'Sur l’exercice de A')->exists())->toBeFalse();
});

it('admet au panneau l’exercice du catalogue et celui du compte', function (int $rang): void {
    $exercices = exercicesDeDeuxComptesAuPanneau();
    $compteB = $exercices[1];
    $exercice = $exercices[$rang];

    Livewire::test(CreateGoal::class)
        ->fillForm([
            'user_id' => $compteB->getKey(),
            'title' => 'Sur un exercice permis',
            'type' => 'volume',
            'exercise_id' => $exercice->getKey(),
            'target_value' => 1000,
            'current_value' => 0,
            'start_value' => 600,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Goal::query()->where('title', 'Sur un exercice permis')->sole()->exercise_id)->toBe($exercice->getKey());
})->with([
    'l’exercice du compte' => [3],
    'l’exercice du catalogue' => [4],
]);

it('refuse au panneau l’exercice d’un autre compte à la modification, par la page et par la table', function (): void {
    [, $compteB, $exerciceDeA, $exerciceDeB] = exercicesDeDeuxComptesAuPanneau();
    $goal = Goal::factory()->create([
        'user_id' => $compteB->getKey(),
        'type' => 'weight',
        'exercise_id' => $exerciceDeB->getKey(),
        'start_value' => 60,
        'target_value' => 100,
    ]);

    Livewire::test(EditGoal::class, ['record' => $goal->getKey()])
        ->fillForm(['exercise_id' => $exerciceDeA->getKey()])
        ->call('save')
        ->assertHasFormErrors(['exercise_id' => 'in']);

    Livewire::test(ListGoals::class)
        ->callAction(TestAction::make('edit')->table($goal), ['exercise_id' => $exerciceDeA->getKey()])
        ->assertHasFormErrors(['exercise_id' => 'in']);

    expect($goal->refresh()->exercise_id)->toBe($exerciceDeB->getKey());
});

/*
 * Seul le compte change : l'objectif de A, sur l'exercice personnel de A,
 * donné à B, se poserait sur l'exercice d'un autre compte. Le même objectif
 * modifié sans changer de compte s'enregistre.
 */
it('refuse au panneau de donner à un autre compte un objectif posé sur un exercice personnel', function (): void {
    [$compteA, $compteB, $exerciceDeA] = exercicesDeDeuxComptesAuPanneau();
    $goal = Goal::factory()->create([
        'user_id' => $compteA->getKey(),
        'type' => 'weight',
        'exercise_id' => $exerciceDeA->getKey(),
        'title' => 'Objectif de A',
        'start_value' => 60,
        'target_value' => 100,
    ]);

    Livewire::test(EditGoal::class, ['record' => $goal->getKey()])
        ->fillForm(['user_id' => $compteB->getKey()])
        ->call('save')
        ->assertHasFormErrors(['exercise_id' => 'in']);

    Livewire::test(ListGoals::class)
        ->callAction(TestAction::make('edit')->table($goal), ['user_id' => $compteB->getKey()])
        ->assertHasFormErrors(['exercise_id' => 'in']);

    expect($goal->refresh()->user_id)->toBe($compteA->getKey());

    Livewire::test(EditGoal::class, ['record' => $goal->getKey()])
        ->fillForm(['title' => 'Objectif de A, renommé'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($goal->refresh()->title)->toBe('Objectif de A, renommé');
});

it("charge l'objectif existant dans le formulaire d'édition", function (): void {
    $owner = User::factory()->create();
    $exercise = Exercise::factory()->create();
    $goal = Goal::factory()->create([
        'user_id' => $owner->getKey(),
        'exercise_id' => $exercise->getKey(),
        'title' => 'Titre initial',
        'type' => 'volume',
        'target_value' => 12000,
        'current_value' => 3000,
        'start_value' => 1000,
        'measurement_type' => 'tour_de_taille',
    ]);

    Livewire::test(EditGoal::class, ['record' => $goal->getKey()])
        ->assertFormFieldExists('user_id')
        ->assertFormFieldExists('title')
        ->assertFormFieldExists('type')
        ->assertFormFieldExists('exercise_id')
        ->assertFormFieldExists('measurement_type')
        ->assertFormFieldExists('deadline')
        ->assertFormSet([
            'user_id' => (string) $owner->getKey(),
            'exercise_id' => (string) $exercise->getKey(),
            'title' => 'Titre initial',
            'type' => 'volume',
            'measurement_type' => 'tour_de_taille',
        ]);
});

/*
 * L'échéance est une colonne `DATE` : une date hors de sa plage partait
 * jusqu'à la base, ou jusqu'au formatage de Filament pour l'an 10000, et
 * l'enregistrement répondait 500 (#2071).
 */
it('refuse une date que la colonne de l’objectif ne tient pas', function (string $champ, string $date): void {
    $goal = Goal::factory()->create(['type' => 'frequency', 'completed_at' => null]);

    Livewire::test(EditGoal::class, ['record' => $goal->getKey()])
        ->fillForm([$champ => $date])
        ->call('save')
        ->assertHasFormErrors([$champ]);

    expect($goal->fresh()?->completed_at)->toBeNull();
})->with([
    'échéance en l’an 10000' => ['deadline', '+10000-01-01'],
]);

/*
 * La date d'atteinte ne se tape plus au panneau (#1513) : le champ est en
 * lecture seule, et ce qu'on y écrirait ne part pas. Une date hors de la plage
 * de sa colonne (un horodatage), que le panneau refusait (#2071), n'est donc ni
 * refusée ni enregistrée : l'objectif reste tel que son calcul le dit.
 */
it('ne refuse ni ne garde une date d’atteinte tapée hors de la plage de sa colonne', function (string $date): void {
    $goal = Goal::factory()->create(['type' => 'frequency', 'start_value' => 0, 'target_value' => 10, 'completed_at' => null]);

    Livewire::test(EditGoal::class, ['record' => $goal->getKey()])
        ->fillForm(['completed_at' => $date])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($goal->fresh()?->completed_at)->toBeNull();
})->with([
    'atteint après 2038' => ['2099-01-01 10:00:00'],
    'atteint avant 1970' => ['1960-01-01 10:00:00'],
]);

/*
 * L'avancement d'un objectif se calcule, il ne se tape pas (#1513).
 *
 * Le panneau laissait taper la valeur actuelle et la date d'atteinte, sans
 * recalculer la barre : un objectif créé ou modifié là gardait ce qui était
 * tapé jusqu'à la prochaine séance, série ou mesure du compte, et une date
 * d'atteinte tapée sur un objectif atteint restait pour de bon, le recalcul ne
 * réécrivant pas la date d'un objectif qui l'est encore. Les deux champs sont
 * en lecture seule à la modification, absents à la création, et
 * l'enregistrement recalcule l'objectif par son écrivain,
 * `GoalService::syncGoals()`, comme le fait chaque activité du compte.
 */

/**
 * Un compte qui a soulevé ce poids, une fois, sur un exercice du catalogue :
 * son record de charge sur cet exercice.
 *
 * @return array{0: User, 1: Exercise}
 */
function objectifsDuPanneauCompteQuiSouleve(float $poids): array
{
    $compte = User::factory()->create();
    $exercice = Exercise::factory()->create(['user_id' => null, 'type' => 'strength']);
    $seance = Workout::factory()->create(['user_id' => $compte->id, 'started_at' => now()->subDay(), 'ended_at' => now()->subDay()->addHour()]);
    $ligne = WorkoutLine::factory()->create(['workout_id' => $seance->id, 'exercise_id' => $exercice->id]);
    Set::factory()->create(['workout_line_id' => $ligne->id, 'weight' => $poids, 'reps' => 5, 'is_warmup' => false, 'is_completed' => true]);

    return [$compte, $exercice];
}

it('recalcule à la création au panneau la valeur actuelle, la barre et l’atteinte, sans garder ce qui est tapé', function (): void {
    [$compte, $exercice] = objectifsDuPanneauCompteQuiSouleve(80);

    Livewire::test(CreateGoal::class)
        ->assertFormFieldHidden('current_value')
        ->assertFormFieldHidden('completed_at')
        ->fillForm([
            'user_id' => $compte->getKey(),
            'title' => 'Cent kilos',
            'type' => 'weight',
            'exercise_id' => $exercice->getKey(),
            'start_value' => 60,
            'target_value' => 100,
            'current_value' => 999,
            'completed_at' => '2020-01-01 10:00:00',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $goal = Goal::query()->where('title', 'Cent kilos')->sole();

    expect($goal->current_value)->toBe(80.0)
        ->and((float) $goal->progress_pct)->toBe(50.0)
        ->and($goal->completed_at)->toBeNull();
});

it('recalcule l’objectif enregistré au panneau, par la page et par la table', function (string $chemin): void {
    [$compte, $exercice] = objectifsDuPanneauCompteQuiSouleve(80);
    $goal = Goal::factory()->create([
        'user_id' => $compte->id,
        'title' => 'Cent kilos',
        'type' => 'weight',
        'exercise_id' => $exercice->id,
        'start_value' => 60,
        'target_value' => 100,
    ]);

    // L'objectif tel qu'une valeur tapée au panneau l'a laissé.
    DB::table('goals')->where('id', $goal->id)->update(['current_value' => 999, 'progress_pct' => 3, 'completed_at' => null]);

    if ($chemin === 'page') {
        Livewire::test(EditGoal::class, ['record' => $goal->getKey()])
            ->assertFormFieldDisabled('current_value')
            ->assertFormFieldDisabled('completed_at')
            ->fillForm(['title' => 'Cent kilos, renommé'])
            ->call('save')
            ->assertHasNoFormErrors();
    } else {
        Livewire::test(ListGoals::class)
            ->callAction(TestAction::make('edit')->table($goal), ['title' => 'Cent kilos, renommé'])
            ->assertHasNoFormErrors();
    }

    $goal->refresh();

    expect($goal->title)->toBe('Cent kilos, renommé')
        ->and($goal->current_value)->toBe(80.0)
        ->and((float) $goal->progress_pct)->toBe(50.0)
        ->and($goal->completed_at)->toBeNull();
})->with([
    'par la page' => ['page'],
    'par l’action de la table' => ['table'],
]);

it('ne garde ni la valeur actuelle ni la date d’atteinte tapées à la modification au panneau', function (string $chemin): void {
    $this->freezeSecond();
    [$compte, $exercice] = objectifsDuPanneauCompteQuiSouleve(120);
    $goal = Goal::factory()->create([
        'user_id' => $compte->id,
        'title' => 'Cent kilos',
        'type' => 'weight',
        'exercise_id' => $exercice->id,
        'start_value' => 60,
        'target_value' => 100,
    ]);
    DB::table('goals')->where('id', $goal->id)->update(['current_value' => 120, 'progress_pct' => 100, 'completed_at' => null]);
    $tape = ['current_value' => 5, 'completed_at' => '2020-01-01 10:00:00'];

    if ($chemin === 'page') {
        Livewire::test(EditGoal::class, ['record' => $goal->getKey()])
            ->fillForm($tape)
            ->call('save')
            ->assertHasNoFormErrors();
    } else {
        Livewire::test(ListGoals::class)
            ->callAction(TestAction::make('edit')->table($goal), $tape)
            ->assertHasNoFormErrors();
    }

    $goal->refresh();

    expect($goal->current_value)->toBe(120.0)
        ->and((float) $goal->progress_pct)->toBe(100.0)
        ->and($goal->completed_at?->toDateTimeString())->toBe(now()->toDateTimeString());
})->with([
    'par la page' => ['page'],
    'par l’action de la table' => ['table'],
]);

/*
 * Le formulaire n'envoie pas les champs calculés, et l'écriture du panneau les
 * retire encore des données qu'elle reçoit (`GoalResource::CHAMPS_CALCULES`) :
 * des données qui passeraient outre le champ désactivé n'écrivent pas
 * l'avancement. Sans ce retrait, la date d'atteinte reçue restait sur un
 * objectif atteint, que l'écrivain ne redate pas.
 */
it('retire de l’écriture du panneau l’avancement reçu dans les données', function (): void {
    $this->freezeSecond();
    [$compte, $exercice] = objectifsDuPanneauCompteQuiSouleve(120);
    $goal = Goal::factory()->create([
        'user_id' => $compte->id,
        'title' => 'Cent kilos',
        'type' => 'weight',
        'exercise_id' => $exercice->id,
        'start_value' => 60,
        'target_value' => 100,
    ]);
    DB::table('goals')->where('id', $goal->id)->update(['current_value' => 120, 'progress_pct' => 100, 'completed_at' => null]);

    EditGoal::modifier($goal->refresh(), [
        'user_id' => $compte->id,
        'title' => 'Cent kilos',
        'type' => 'weight',
        'exercise_id' => $exercice->id,
        'start_value' => 60,
        'target_value' => 100,
        'current_value' => 5,
        'progress_pct' => 7,
        'completed_at' => '2020-01-01 10:00:00',
    ]);

    $goal->refresh();

    expect($goal->current_value)->toBe(120.0)
        ->and((float) $goal->progress_pct)->toBe(100.0)
        ->and($goal->completed_at?->toDateTimeString())->toBe(now()->toDateTimeString());
});

it('interdit la gestion des objectifs à un admin sans les permissions Goal', function (): void {
    $goal = Goal::factory()->create();

    $this->actingAs(FilamentAdminPanel::admin(['ViewAny:User']), 'admin');

    expect(GoalResource::canViewAny())->toBeFalse()
        ->and(GoalResource::canCreate())->toBeFalse()
        ->and(GoalResource::canEdit($goal))->toBeFalse()
        ->and(GoalResource::canDelete($goal))->toBeFalse();
});
