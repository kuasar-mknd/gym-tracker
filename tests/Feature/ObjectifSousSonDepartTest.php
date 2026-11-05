<?php

declare(strict_types=1);

use App\Enums\GoalType;
use App\Filament\Resources\Goals\Pages\CreateGoal;
use App\Filament\Resources\Goals\Pages\EditGoal;
use App\Filament\Resources\Goals\Pages\ListGoals;
use App\Models\BodyMeasurement;
use App\Models\Exercise;
use App\Models\Goal;
use App\Models\PersonalRecord;
use App\Models\Set;
use App\Models\User;
use App\Models\Workout;
use App\Models\WorkoutLine;
use App\Services\GoalService;
use Filament\Actions\Testing\TestAction;
use Filament\Forms\Components\TextInput;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\FilamentAdminPanel;

use function Pest\Laravel\actingAs;

/*
 * Une cible sous le départ ne se pose qu'à une mensuration (#2164).
 *
 * Le record, la meilleure séance et le nombre de séances ne font que monter
 * avec l'activité : une cible à atteindre en descendant n'y a pas de sens. La
 * barre comptait pourtant la descente, et l'état la montée : départ 100,
 * cible 80, un record de 70 donnait une barre pleine sans que l'objectif soit
 * atteint, aucun record un objectif atteint à la barre vide. La requête de
 * l'application et le panneau refusent donc, pour la charge, le volume et la
 * fréquence, une cible sous le départ, à la création comme à la modification ;
 * ils admettent l'égalité, et la mensuration, qui seule descend. Les deux
 * comparent par le même objet de règle, `CibleAuMoinsEgaleAuDepart`, en
 * nombres, pas en texte. Un objectif déjà en base sous son départ se lit,
 * lui, comme un objectif dont la cible égale le départ : barre pleine quand
 * il est atteint, vide sinon, par les deux chemins de recalcul.
 */

/**
 * Le corps qu'envoie le formulaire de l'application pour un objectif du type
 * demandé, avec ce que ce type exige : un exercice du catalogue pour la
 * charge et le volume, le poids de corps pour une mensuration.
 *
 * @return array<string, mixed>
 */
function sousSonDepartCorps(string $type, mixed $depart, mixed $cible): array
{
    return [
        'title' => 'Objectif posé',
        'type' => $type,
        'target_value' => $cible,
        'start_value' => $depart,
        ...match ($type) {
            'weight', 'volume' => ['exercise_id' => Exercise::factory()->create(['user_id' => null])->id],
            'measurement' => ['measurement_type' => 'weight'],
            default => [],
        },
    ];
}

/**
 * Un objectif du compte, du type demandé, parti de 10 vers 200 : un objectif
 * que la modification déplace.
 */
function sousSonDepartObjectifAModifier(User $user, string $type): Goal
{
    return Goal::factory()->create([
        'user_id' => $user->id,
        'title' => 'Objectif d’avant',
        'type' => $type,
        'start_value' => 10,
        'target_value' => 200,
        'measurement_type' => $type === 'measurement' ? 'weight' : null,
    ]);
}

/**
 * Envoie le corps d'un objectif du type demandé à la création, ou à la
 * modification d'un objectif du compte de ce type.
 *
 * @return TestResponse<Response>
 */
function sousSonDepartEnvoyer(string $route, User $user, string $type, mixed $depart, mixed $cible): TestResponse
{
    $corps = sousSonDepartCorps($type, $depart, $cible);

    if ($route === 'création') {
        return actingAs($user)->post(route('goals.store'), $corps);
    }

    $objectif = sousSonDepartObjectifAModifier($user, $type);

    return actingAs($user)->put(route('goals.update', ['goal' => $objectif->id]), $corps);
}

dataset('routes d’un objectif de l’application', [
    'à la création' => ['création'],
    'à la modification' => ['modification'],
]);

it('refuse une cible sous le départ d’une charge, d’un volume ou d’une fréquence', function (string $type, string $route): void {
    $user = User::factory()->create();

    sousSonDepartEnvoyer($route, $user, $type, 100, 80)
        ->assertSessionHasErrors(['target_value' => 'Le champ valeur cible doit être au moins égal à la valeur de départ.']);

    expect(Goal::query()->where('title', 'Objectif posé')->exists())->toBeFalse();
})->with([
    'charge' => ['weight'],
    'volume' => ['volume'],
    'fréquence' => ['frequency'],
])->with('routes d’un objectif de l’application');

it('admet une cible égale au départ ou au-dessus, et une mensuration qui descend', function (string $type, int $depart, int $cible, string $route): void {
    $user = User::factory()->create();

    sousSonDepartEnvoyer($route, $user, $type, $depart, $cible)
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('goals.index'));

    $objectif = Goal::query()->where('title', 'Objectif posé')->sole();

    expect($objectif->start_value)->toBe((float) $depart)
        ->and($objectif->target_value)->toBe((float) $cible);
})->with([
    'charge, cible égale au départ' => ['weight', 100, 100],
    'charge, cible au-dessus du départ' => ['weight', 100, 120],
    'volume, cible égale au départ' => ['volume', 5000, 5000],
    'fréquence, cible au-dessus du départ' => ['frequency', 10, 30],
    'mensuration, cible sous le départ' => ['measurement', 90, 80],
    'mensuration, cible égale au départ' => ['measurement', 90, 90],
])->with('routes d’un objectif de l’application');

/*
 * Un départ absent à la création vaut 0 (`GoalController::store()`) : la
 * cible ne se compare à rien, et toute cible permise passe.
 */
it('admet toute cible quand la création n’envoie pas de départ', function (string $type): void {
    $user = User::factory()->create();
    $corps = sousSonDepartCorps($type, null, 80);
    unset($corps['start_value']);

    actingAs($user)->post(route('goals.store'), $corps)
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('goals.index'));

    expect(Goal::query()->where('title', 'Objectif posé')->sole()->start_value)->toBe(0.0);
})->with([
    'charge' => ['weight'],
    'fréquence' => ['frequency'],
]);

/*
 * La comparaison se fait en nombres. Comparés comme des textes, « 100 »
 * passerait avant « 99.5 » : la cible de 100 sur un départ de 99,5 serait
 * refusée, et la cible de 99,5 sur un départ de 100 admise.
 */
it('compare en nombres une cible et un départ qui n’ont pas le même nombre de chiffres', function (string $route): void {
    $user = User::factory()->create();

    sousSonDepartEnvoyer($route, $user, 'weight', '99.5', '100')
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('goals.index'));

    expect(Goal::query()->where('title', 'Objectif posé')->sole()->start_value)->toBe(99.5);

    Goal::query()->where('user_id', $user->id)->delete();

    sousSonDepartEnvoyer($route, $user, 'weight', '100', '99.5')
        ->assertSessionHasErrors(['target_value']);

    expect(Goal::query()->where('title', 'Objectif posé')->exists())->toBeFalse();
})->with('routes d’un objectif de l’application');

/*
 * Le panneau ne traverse pas la requête de l'application : il pose la même
 * règle sur le même champ, par `->rule()`. La création, la page de
 * modification et l'action de la table partagent le formulaire.
 */
it('refuse au panneau une cible sous le départ d’une charge, d’un volume ou d’une fréquence', function (string $type): void {
    $this->actingAs(FilamentAdminPanel::admin(FilamentAdminPanel::crudPermissions('Goal')), 'admin');
    $owner = User::factory()->create();

    Livewire::test(CreateGoal::class)
        ->fillForm([
            'user_id' => $owner->getKey(),
            'title' => 'Objectif du panneau',
            'type' => $type,
            'exercise_id' => $type === 'frequency' ? null : Exercise::factory()->create(['user_id' => null])->getKey(),
            'target_value' => 80,
            'current_value' => 0,
            'start_value' => 100,
        ])
        ->call('create')
        ->assertHasFormErrors(['target_value']);

    expect(Goal::query()->where('title', 'Objectif du panneau')->exists())->toBeFalse();
})->with([
    'charge' => ['weight'],
    'volume' => ['volume'],
    'fréquence' => ['frequency'],
]);

it('admet au panneau une cible égale au départ ou au-dessus, et une mensuration qui descend', function (string $type, int $depart, int $cible): void {
    $this->actingAs(FilamentAdminPanel::admin(FilamentAdminPanel::crudPermissions('Goal')), 'admin');
    $owner = User::factory()->create();

    Livewire::test(CreateGoal::class)
        ->fillForm([
            'user_id' => $owner->getKey(),
            'title' => 'Objectif du panneau',
            'type' => $type,
            'exercise_id' => in_array($type, ['weight', 'volume'], true) ? Exercise::factory()->create(['user_id' => null])->getKey() : null,
            'measurement_type' => $type === 'measurement' ? 'weight' : null,
            'target_value' => $cible,
            'current_value' => 0,
            'start_value' => $depart,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Goal::query()->where('title', 'Objectif du panneau')->sole()->target_value)->toBe((float) $cible);
})->with([
    'charge, cible égale au départ' => ['weight', 100, 100],
    'volume, cible au-dessus du départ' => ['volume', 1000, 1200],
    'fréquence, cible égale au départ' => ['frequency', 10, 10],
    'mensuration, cible sous le départ' => ['measurement', 90, 80],
]);

/*
 * Un objectif déjà en base sous son départ demande d'être corrigé à sa
 * prochaine modification au panneau, par la page comme par la table ; corrigé,
 * il s'enregistre.
 */
it('demande au panneau de corriger un objectif déjà en base sous son départ', function (): void {
    $this->actingAs(FilamentAdminPanel::admin(FilamentAdminPanel::crudPermissions('Goal')), 'admin');
    $goal = Goal::factory()->create([
        'type' => 'weight',
        'title' => 'Sous son départ',
        'start_value' => 100,
        'target_value' => 80,
    ]);

    Livewire::test(EditGoal::class, ['record' => $goal->getKey()])
        ->fillForm(['title' => 'Toujours sous son départ'])
        ->call('save')
        ->assertHasFormErrors(['target_value']);

    Livewire::test(ListGoals::class)
        ->callAction(TestAction::make('edit')->table($goal), ['title' => 'Toujours sous son départ'])
        ->assertHasFormErrors(['target_value']);

    expect($goal->refresh()->title)->toBe('Sous son départ');

    Livewire::test(EditGoal::class, ['record' => $goal->getKey()])
        ->fillForm(['title' => 'Corrigé', 'target_value' => 100])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($goal->refresh()->title)->toBe('Corrigé');
});

/*
 * La borne suit le départ saisi dans le même formulaire : elle se juge à
 * l'envoi, sur l'état du formulaire. Un `minValue()` lu sur le départ aurait
 * aussi posé l'attribut `min` du champ, figé au rendu : abaisser le départ
 * puis la cible en dessous de l'ancien départ aurait bloqué dans le navigateur
 * une saisie valide.
 */
it('juge au panneau la cible sur le départ saisi, sans borne figée au rendu', function (): void {
    $this->actingAs(FilamentAdminPanel::admin(FilamentAdminPanel::crudPermissions('Goal')), 'admin');
    $goal = Goal::factory()->create(['type' => 'frequency', 'start_value' => 80, 'target_value' => 100]);

    Livewire::test(EditGoal::class, ['record' => $goal->getKey()])
        ->assertFormFieldExists('target_value', fn (TextInput $champ): bool => $champ->getMinValue() === null)
        ->fillForm(['start_value' => 50, 'target_value' => 60])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($goal->refresh()->target_value)->toBe(60.0)
        ->and($goal->start_value)->toBe(50.0);
});

/**
 * Pose la source que suit l'objectif : un record, une séance, une pesée, ou
 * rien.
 */
function sousSonDepartPoserLaSource(string $source, User $user, Exercise $exercice): void
{
    if (preg_match('/^record de (\d+)$/', $source, $record) === 1) {
        PersonalRecord::factory()->create([
            'user_id' => $user->id,
            'exercise_id' => $exercice->id,
            'type' => 'max_weight',
            'value' => (float) $record[1],
        ]);

        return;
    }

    if (preg_match('/^séance de (\d+) kg × 10$/', $source, $seance) === 1) {
        $workout = Workout::factory()->create(['user_id' => $user->id]);
        $ligne = WorkoutLine::factory()->create(['workout_id' => $workout->id, 'exercise_id' => $exercice->id]);
        Set::factory()->create(['workout_line_id' => $ligne->id, 'weight' => (float) $seance[1], 'reps' => 10, 'is_warmup' => false]);

        return;
    }

    if (preg_match('/^pesée de (\d+)$/', $source, $pesee) === 1) {
        BodyMeasurement::factory()->create(['user_id' => $user->id, 'weight' => (float) $pesee[1], 'measured_at' => '2026-06-15']);
    }
}

/*
 * Un objectif déjà en base sous son départ, hors mensuration : sa barre suit
 * son état, pleine quand il est atteint, vide sinon, comme sous une cible
 * égale au départ. `isGoalCriteriaMet()` ne change pas : l'objectif est
 * atteint quand sa valeur atteint la cible. L'objectif est posé tel que
 * l'ancien recalcul a pu le laisser, barre pleine et non atteint. La
 * mensuration descend, elle, et sa barre compte la descente.
 */
it('lit un objectif déjà en base sous son départ comme une cible égale au départ, par les deux chemins', function (GoalType $type, int $depart, int $cible, string $source, float $valeur, float $barre, bool $atteint, string $chemin): void {
    $user = User::factory()->create();
    $exercice = Exercise::factory()->create(['user_id' => null]);
    sousSonDepartPoserLaSource($source, $user, $exercice);

    $goal = Goal::factory()->create([
        'user_id' => $user->id,
        'type' => $type,
        'exercise_id' => in_array($type, [GoalType::Weight, GoalType::Volume], true) ? $exercice->id : null,
        'measurement_type' => $type === GoalType::Measurement ? 'weight' : null,
        'start_value' => $depart,
        'target_value' => $cible,
        'current_value' => 0,
        'progress_pct' => 100,
        'completed_at' => null,
    ]);

    if ($chemin === 'lot') {
        app(GoalService::class)->syncGoals($user);
        $goal->refresh();
    } else {
        app(GoalService::class)->updateGoalProgress($goal);
    }

    expect($goal->current_value)->toBe($valeur)
        ->and((float) $goal->progress_pct)->toBe($barre)
        ->and($goal->completed_at !== null)->toBe($atteint);
})->with([
    'charge, aucun record : au départ, atteint' => [GoalType::Weight, 100, 80, 'rien', 100.0, 100.0, true],
    'charge, record de 70 : sous la cible' => [GoalType::Weight, 100, 80, 'record de 70', 70.0, 0.0, false],
    'charge, record de 80 : à la cible' => [GoalType::Weight, 100, 80, 'record de 80', 80.0, 100.0, true],
    'charge, record de 90 : entre la cible et le départ' => [GoalType::Weight, 100, 80, 'record de 90', 90.0, 100.0, true],
    'volume, meilleure séance de 900 : au-dessus de la cible' => [GoalType::Volume, 1000, 800, 'séance de 90 kg × 10', 900.0, 100.0, true],
    'volume, meilleure séance de 700 : sous la cible' => [GoalType::Volume, 1000, 800, 'séance de 70 kg × 10', 700.0, 0.0, false],
    'fréquence, aucune séance faite' => [GoalType::Frequency, 10, 8, 'rien', 0.0, 0.0, false],
    'mensuration qui descend : la barre compte la descente' => [GoalType::Measurement, 90, 80, 'pesée de 85', 85.0, 50.0, false],
])->with([
    'par le lot' => ['lot'],
    'par le chemin d’un seul objectif' => ['seul'],
]);
