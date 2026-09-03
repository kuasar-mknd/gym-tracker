<?php

declare(strict_types=1);

use App\Actions\Workouts\StoreSetAction;
use App\Models\Set;
use App\Models\User;
use App\Models\Workout;
use App\Models\WorkoutLine;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Log;

it('stores a set for a workout line successfully', function (): void {
    $user = User::factory()->create();
    $workout = Workout::factory()->create(['user_id' => $user->id]);
    $workoutLine = WorkoutLine::factory()->create(['workout_id' => $workout->id]);

    $data = [
        'workout_line_id' => $workoutLine->id,
        'weight' => 100.5,
        'reps' => 10,
        'is_warmup' => false,
        'is_completed' => true,
    ];

    $action = app(StoreSetAction::class);
    $set = $action->execute($user, $data);

    expect($set)->toBeInstanceOf(Set::class)
        ->and($set->workout_line_id)->toBe($workoutLine->id)
        ->and($set->weight)->toBe(100.5)
        ->and($set->reps)->toBe(10)
        ->and($set->is_warmup)->toBeFalse()
        ->and($set->is_completed)->toBeTrue();

    $this->assertDatabaseHas('sets', [
        'id' => $set->id,
        'workout_line_id' => $workoutLine->id,
        'weight' => 100.5,
        'reps' => 10,
        'is_warmup' => false,
        'is_completed' => true,
    ]);
});

it('throws ModelNotFoundException and logs error if workout line does not exist', function (): void {
    $user = User::factory()->create();

    $data = [
        'workout_line_id' => 99999,
        'weight' => 100.5,
        'reps' => 10,
    ];

    Log::shouldReceive('error')->once();

    $action = app(StoreSetAction::class);

    expect(fn () => $action->execute($user, $data))
        ->toThrow(ModelNotFoundException::class);
});

it('throws AuthorizationException and logs error if user is not authorized', function (): void {
    $user1 = User::factory()->create();
    $user2 = User::factory()->create();
    $workout = Workout::factory()->create(['user_id' => $user1->id]);
    $workoutLine = WorkoutLine::factory()->create(['workout_id' => $workout->id]);

    $data = [
        'workout_line_id' => $workoutLine->id,
        'weight' => 100.5,
        'reps' => 10,
    ];

    Log::shouldReceive('error')->once();

    $action = app(StoreSetAction::class);

    expect(fn () => $action->execute($user2, $data))
        ->toThrow(AuthorizationException::class);
});

it('throws AuthorizationException and logs error if workout is ended', function (): void {
    $user = User::factory()->create();
    $workout = Workout::factory()->create([
        'user_id' => $user->id,
        'ended_at' => now(),
    ]);
    $workoutLine = WorkoutLine::factory()->create(['workout_id' => $workout->id]);

    $data = [
        'workout_line_id' => $workoutLine->id,
        'weight' => 100.5,
        'reps' => 10,
    ];

    Log::shouldReceive('error')->once();

    $action = app(StoreSetAction::class);

    expect(fn () => $action->execute($user, $data))
        ->toThrow(AuthorizationException::class);
});

/*
 * Le contenu du journal d'erreur etait execute sans jamais etre lu.
 *
 * Les trois tests ci-dessus se contentent de `Log::shouldReceive('error')->once()`,
 * qui accepte n'importe quel contexte : les quatre entrees du tableau — le
 * message de l'exception, la pile, l'utilisateur, la charge utile — pouvaient
 * disparaitre une par une sans qu'une seule assertion bronche. C'est exactement
 * ce que la mutation rapportait : quatre `RemoveArrayItem` survivants sur les
 * lignes 36 a 39.
 *
 * Ce que chacune laissait passer, concretement : un incident se serait
 * diagnostique sans savoir ce que l'exception disait, sans la pile pour situer
 * l'appel, sans l'identifiant de l'utilisateur touche, ou sans la charge utile
 * qui a declenche l'echec. Le journal aurait dit qu'il y a eu un probleme, et
 * rien de plus.
 */
it('journalise le message, la pile, l utilisateur et la charge utile, et rien d autre', function (): void {
    $proprietaire = User::factory()->create();
    $intrus = User::factory()->create();
    $workout = Workout::factory()->create(['user_id' => $proprietaire->id]);
    $workoutLine = WorkoutLine::factory()->create(['workout_id' => $workout->id]);

    // Valeurs posees et non tirees : la charge utile est comparee a l'identique
    // plus bas, donc elle ne peut pas dependre de ce que la fabrique a produit.
    $donnees = [
        'workout_line_id' => $workoutLine->id,
        'weight' => 100.5,
        'reps' => 10,
    ];

    $intitule = '';
    /** @var array<string, mixed> $contexte */
    $contexte = [];

    Log::shouldReceive('error')
        ->once()
        ->withArgs(
            /**
             * @param  array<string, mixed>  $context
             */
            function (string $message, array $context) use (&$intitule, &$contexte): bool {
                $intitule = $message;
                $contexte = $context;

                return true;
            }
        );

    expect(fn () => app(StoreSetAction::class)->execute($intrus, $donnees))
        ->toThrow(AuthorizationException::class);

    expect($intitule)->toBe('Failed to create set in API:');

    // La liste exacte des cles, et dans l'ordre : une entree ajoutee par
    // megarde est un journal qui grossit sans qu'on le decide. La pile et la
    // charge utile n'y sont plus : l'exception est relancee, et c'est le
    // gestionnaire qui la rapporte, pile comprise.
    expect(array_keys($contexte))->toBe(['error', 'user_id']);

    // Et leur contenu, parce qu'une cle presente mais vide ne diagnostique rien.
    expect($contexte['error'])->toBe('This action is unauthorized.');
    expect($contexte['user_id'])->toBe($intrus->id);
});
