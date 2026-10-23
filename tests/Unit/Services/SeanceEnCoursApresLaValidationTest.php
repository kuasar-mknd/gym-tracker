<?php

declare(strict_types=1);

use App\Actions\Workouts\CreateWorkoutLineAction;
use App\Actions\Workouts\DemarrerUneSeanceAction;
use App\Actions\Workouts\TerminerLaSeanceAction;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Exercise;
use App\Models\User;
use App\Models\Workout;
use App\Models\WorkoutLine;
use App\Models\WorkoutTemplate;
use App\Models\WorkoutTemplateLine;
use App\Models\WorkoutTemplateSet;
use App\Services\ActiveWorkoutService;
use Illuminate\Database\Connection;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/*
 * La séance en cours qu'une autre requête lit pendant une écriture ne survit
 * pas à sa validation (#2108).
 *
 * `ActiveWorkoutService` garde deux heures la séance en cours du compte, que
 * chaque page partage (`auth.user.active_workout`). Les écouteurs de `Workout`
 * et de `WorkoutLine` l'oubliaient au moment de l'écriture, donc DANS la
 * transaction de l'écrivain : une requête du même compte servie avant la
 * validation (un autre onglet, un autre appareil, le préchauffage des pages)
 * lisait encore l'état d'avant, le remettait en cache, et plus rien ne
 * l'oubliait ensuite. Le lancement d'un modèle le faisait à chaque fois : sa
 * transaction insère les séries après la séance et ses lignes. Une
 * transaction annulée laissait de même ce qu'une lecture faite en son sein
 * avait vu : une séance qui n'existe pas.
 *
 * Ces tests valident pour de bon, d'où `DatabaseTruncation`, hors de
 * tests/Feature : sous `RefreshDatabase`, la transaction qui enveloppe chaque
 * test cacherait tout à la seconde connexion, et les rappels d'après la
 * validation y partent dès que seule cette transaction est ouverte. La seconde
 * connexion (`lecteur_concurrent`) ne voit que ce qui est validé, comme la
 * requête d'un autre processus. Le fichier vide ses tables après chaque test :
 * les tests de `RefreshDatabase` qui suivent dans le même processus partent
 * d'une base vide.
 */

uses(TestCase::class, DatabaseTruncation::class);

beforeEach(function (): void {
    config(['database.connections.lecteur_concurrent' => config('database.connections.'.config()->string('database.default'))]);
});

afterEach(function (): void {
    DB::purge('lecteur_concurrent');
    $this->truncateTablesForAllConnections();
});

/**
 * La séance en cours du compte telle qu'une autre requête la lit à cet
 * instant : par la seconde connexion, qui ne voit que ce qui est validé, et
 * par le cache commun, où elle la remet.
 */
function seanceApresValidationLueAilleurs(int $idDuCompte): ?Workout
{
    return app(ActiveWorkoutService::class)->for(User::on('lecteur_concurrent')->findOrFail($idDuCompte));
}

/**
 * La séance en cours que partage la page suivante du compte, demandée par une
 * visite d'Inertia : la page seule, sans le document que rendrait Vite.
 *
 * @return array<string, mixed>|null
 */
function seanceApresValidationPartagee(User $compte): ?array
{
    $partagee = test()->actingAs($compte)
        ->withHeaders([
            'X-Inertia' => 'true',
            'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(request()),
            'X-Requested-With' => 'XMLHttpRequest',
        ])
        ->get(route('dashboard'))
        ->assertOk()
        ->assertHeader('X-Inertia', 'true')
        ->json('props.auth.user.active_workout');

    assert($partagee === null || is_array($partagee));

    /** @var array<string, mixed>|null $partagee */
    return $partagee;
}

/**
 * Un modèle de deux exercices, de deux et une séries.
 */
function seanceApresValidationModele(User $compte): WorkoutTemplate
{
    $modele = WorkoutTemplate::factory()->create(['user_id' => $compte->id, 'name' => 'Haut du corps']);

    foreach ([0 => [0, 1], 1 => [0]] as $rang => $rangsDesSeries) {
        $ligne = WorkoutTemplateLine::factory()->create([
            'workout_template_id' => $modele->id,
            'exercise_id' => Exercise::factory()->create(['user_id' => $compte->id])->id,
            'order' => $rang,
        ]);

        foreach ($rangsDesSeries as $serie) {
            WorkoutTemplateSet::factory()->create(['workout_template_line_id' => $ligne->id, 'reps' => 8, 'weight' => 60, 'order' => $serie]);
        }
    }

    return $modele;
}

it('partage la séance lancée d’un modèle quand une autre requête l’a lue avant la validation', function (): void {
    $compte = User::factory()->create();
    $modele = seanceApresValidationModele($compte);
    expect(seanceApresValidationLueAilleurs($compte->id))->toBeNull();

    $lecture = new ArrayObject(['niveau' => null, 'seance' => false]);
    DB::beforeExecuting(function (string $requete, array $liaisons, Connection $connexion) use ($compte, $lecture): void {
        if ($lecture['niveau'] !== null || ! str_starts_with($requete, 'insert into `sets`')) {
            return;
        }

        $lecture['niveau'] = $connexion->transactionLevel();
        $lecture['seance'] = seanceApresValidationLueAilleurs($compte->id);
    });

    $seance = app(DemarrerUneSeanceAction::class)->execute($compte, null, $modele);

    // La lecture a bien eu lieu dans la fenêtre : pendant l'insertion des séries, avant la validation.
    expect($lecture['niveau'])->toBe(1)
        ->and($lecture['seance'])->toBeNull()
        ->and(Workout::query()->where('user_id', $compte->id)->whereNull('ended_at')->value('id'))->toBe($seance->id);

    $enCours = app(ActiveWorkoutService::class)->for($compte);

    expect($enCours?->id)->toBe($seance->id)
        ->and($enCours?->workout_lines_count)->toBe(2);

    expect(seanceApresValidationPartagee($compte))->toMatchArray(['id' => $seance->id, 'workout_lines_count' => 2]);
});

/*
 * Chaque écouteur, seul : un écrivain qui écrit en transaction, puis fait
 * autre chose avant de valider (le panneau quand il enveloppe ses actions, une
 * action à venir). La lecture concurrente tombe entre l'écriture et la
 * validation, et voit encore la séance ouverte avec son unique exercice.
 * Chaque cas ne déclenche qu'un écouteur : `Workout::saved`, `Workout::deleted`,
 * `WorkoutLine::saved`, `WorkoutLine::deleted`.
 */
it('oublie la séance en cours après la validation de l’écriture, pas avant', function (Closure $ecrire, ?int $exercicesAttendus): void {
    $compte = User::factory()->create();
    $seance = Workout::factory()->create(['user_id' => $compte->id, 'ended_at' => null]);
    $ligne = WorkoutLine::factory()->create(['workout_id' => $seance->id, 'exercise_id' => Exercise::factory()->create(['user_id' => $compte->id])->id]);
    expect(seanceApresValidationLueAilleurs($compte->id)?->workout_lines_count)->toBe(1);

    $luePendant = DB::transaction(function () use ($ecrire, $seance, $ligne, $compte): ?Workout {
        $ecrire($seance, $ligne, $compte);

        return seanceApresValidationLueAilleurs($compte->id);
    });

    expect($luePendant?->id)->toBe($seance->id)
        ->and($luePendant?->workout_lines_count)->toBe(1);

    $enCours = app(ActiveWorkoutService::class)->for($compte);

    if ($exercicesAttendus === null) {
        expect($enCours)->toBeNull();
        expect(seanceApresValidationPartagee($compte))->toBeNull();

        return;
    }

    expect($enCours?->id)->toBe($seance->id)
        ->and($enCours?->workout_lines_count)->toBe($exercicesAttendus);
    expect(seanceApresValidationPartagee($compte))->toMatchArray(['id' => $seance->id, 'workout_lines_count' => $exercicesAttendus]);
})->with([
    'la fin de la séance' => [
        fn (Workout $seance): Workout => app(TerminerLaSeanceAction::class)->execute($seance, null),
        null,
    ],
    'la suppression de la séance' => [
        fn (Workout $seance): ?bool => $seance->delete(),
        null,
    ],
    'l’ajout d’un exercice' => [
        fn (Workout $seance, WorkoutLine $ligne, User $compte): WorkoutLine => app(CreateWorkoutLineAction::class)
            ->execute($seance, ['exercise_id' => Exercise::factory()->create(['user_id' => $compte->id])->id]),
        2,
    ],
    'le retrait d’un exercice' => [
        fn (Workout $seance, WorkoutLine $ligne): ?bool => $ligne->delete(),
        0,
    ],
]);

/*
 * Une annulation oublie aussi l'entrée. Une lecture faite dans la transaction
 * même, sur sa connexion (un écouteur, une action qui relit la séance en cours
 * avant de valider), voit la séance que la transaction n'a pas validée, et la
 * remet en cache : après l'annulation, chaque page partagerait deux heures une
 * séance en cours qui n'existe pas.
 */
it('oublie la séance qu’une transaction annulée a lue avant de s’annuler', function (): void {
    $compte = User::factory()->create();
    $lueDansLaTransaction = new ArrayObject(['seance' => null]);

    expect(fn (): mixed => DB::transaction(function () use ($compte, $lueDansLaTransaction): void {
        Workout::factory()->create(['user_id' => $compte->id, 'ended_at' => null]);
        $lueDansLaTransaction['seance'] = app(ActiveWorkoutService::class)->for($compte);

        throw new RuntimeException('Transaction annulée par le test.');
    }))->toThrow(new RuntimeException('Transaction annulée par le test.'));

    expect($lueDansLaTransaction['seance'])->toBeInstanceOf(Workout::class)
        ->and(Workout::query()->where('user_id', $compte->id)->exists())->toBeFalse()
        ->and(app(ActiveWorkoutService::class)->for($compte))->toBeNull();
    expect(seanceApresValidationPartagee($compte))->toBeNull();
});

/*
 * Hors transaction, l'oubli part aussitôt, comme avant : la création d'une
 * séance vide n'a pas de transaction (`DemarrerUneSeanceAction`), et la fin
 * non plus (`TerminerLaSeanceAction`).
 */
it('partage aussitôt la séance vide qui vient de démarrer', function (): void {
    $compte = User::factory()->create();
    expect(app(ActiveWorkoutService::class)->for($compte))->toBeNull();

    $seance = app(DemarrerUneSeanceAction::class)->execute($compte, null);

    expect(app(ActiveWorkoutService::class)->for($compte)?->id)->toBe($seance->id);
    expect(seanceApresValidationPartagee($compte))->toMatchArray(['id' => $seance->id]);
});

it('ne partage plus la séance qui vient de se terminer', function (): void {
    $compte = User::factory()->create();
    $seance = Workout::factory()->create(['user_id' => $compte->id, 'ended_at' => null]);
    expect(app(ActiveWorkoutService::class)->for($compte)?->id)->toBe($seance->id);

    app(TerminerLaSeanceAction::class)->execute($seance, null);

    expect(app(ActiveWorkoutService::class)->for($compte))->toBeNull();
    expect(seanceApresValidationPartagee($compte))->toBeNull();
});
