<?php

declare(strict_types=1);

use App\Models\Exercise;
use App\Models\Set;
use App\Models\User;
use App\Models\Workout;
use App\Models\WorkoutLine;
use Illuminate\Support\Facades\Exceptions;
use Inertia\Testing\AssertableInertia as Assert;

use function Pest\Laravel\actingAs;

/**
 * A create the server accepted and wrote, whose response never reached the
 * client, is indistinguishable from one that never arrived — both surface as a
 * network error. SyncService queues it and replays it later, and without a key
 * naming the attempt, the replay makes a second row.
 *
 * Workout 8 in the owner's own database is the shape of it: five lines stamped
 * within two seconds of each other by a queue flush, two of them the same
 * exercise.
 */
describe('idempotent creates', function (): void {
    beforeEach(function (): void {
        $this->user = User::factory()->create();
        $this->workout = Workout::factory()->create(['user_id' => $this->user->id, 'ended_at' => null]);
        $this->exercise = Exercise::factory()->create();
    });

    it('creates one line however many times the attempt is replayed', function (): void {
        $payload = ['workout_id' => $this->workout->id, 'exercise_id' => $this->exercise->id];
        $headers = ['Idempotency-Key' => 'attempt-line-1'];

        $first = $this->actingAs($this->user)->postJson(route('api.v1.workout-lines.store'), $payload, $headers);
        $second = $this->actingAs($this->user)->postJson(route('api.v1.workout-lines.store'), $payload, $headers);
        $third = $this->actingAs($this->user)->postJson(route('api.v1.workout-lines.store'), $payload, $headers);

        $first->assertSuccessful();
        $second->assertSuccessful();
        $third->assertSuccessful();

        expect($second->json('data.id'))->toBe($first->json('data.id'))
            ->and($third->json('data.id'))->toBe($first->json('data.id'))
            ->and(WorkoutLine::where('workout_id', $this->workout->id)->count())->toBe(1);
    });

    it('creates one set however many times the attempt is replayed', function (): void {
        $line = WorkoutLine::factory()->create([
            'workout_id' => $this->workout->id,
            'exercise_id' => $this->exercise->id,
        ]);
        $payload = ['workout_line_id' => $line->id, 'weight' => 80, 'reps' => 8];
        $headers = ['Idempotency-Key' => 'attempt-set-1'];

        $first = $this->actingAs($this->user)->postJson(route('api.v1.sets.store'), $payload, $headers);
        $second = $this->actingAs($this->user)->postJson(route('api.v1.sets.store'), $payload, $headers);

        $first->assertSuccessful();
        $second->assertSuccessful();

        expect($second->json('data.id'))->toBe($first->json('data.id'))
            ->and($line->sets()->count())->toBe(1);
    });

    it('still creates a distinct row for a genuinely distinct attempt', function (): void {
        $line = WorkoutLine::factory()->create([
            'workout_id' => $this->workout->id,
            'exercise_id' => $this->exercise->id,
        ]);
        $payload = ['workout_line_id' => $line->id, 'weight' => 80, 'reps' => 8];

        $this->actingAs($this->user)->postJson(route('api.v1.sets.store'), $payload, ['Idempotency-Key' => 'a'])
            ->assertSuccessful();
        $this->actingAs($this->user)->postJson(route('api.v1.sets.store'), $payload, ['Idempotency-Key' => 'b'])
            ->assertSuccessful();

        expect($line->sets()->count())->toBe(2);
    });

    /**
     * Three identical sets in a row is an ordinary thing to log. Without a key
     * the server has no way to tell them apart, and must not try.
     */
    it('leaves an unkeyed create alone', function (): void {
        $line = WorkoutLine::factory()->create([
            'workout_id' => $this->workout->id,
            'exercise_id' => $this->exercise->id,
        ]);
        $payload = ['workout_line_id' => $line->id, 'weight' => 80, 'reps' => 8];

        $this->actingAs($this->user)->postJson(route('api.v1.sets.store'), $payload)->assertSuccessful();
        $this->actingAs($this->user)->postJson(route('api.v1.sets.store'), $payload)->assertSuccessful();

        expect($line->sets()->count())->toBe(2);
    });

    /**
     * The key travels in a client-controlled header. A lookup searching on it
     * globally would hand one user's row to whoever replayed their key, so the
     * lookup is scoped to the already-authorised parent.
     */
    it('never returns another user\'s row to whoever replays their key', function (): void {
        $stranger = User::factory()->create();
        $strangerWorkout = Workout::factory()->create(['user_id' => $stranger->id, 'ended_at' => null]);
        $strangerLine = WorkoutLine::factory()->create([
            'workout_id' => $strangerWorkout->id,
            'exercise_id' => $this->exercise->id,
        ]);

        $this->actingAs($stranger)->postJson(
            route('api.v1.sets.store'),
            ['workout_line_id' => $strangerLine->id, 'weight' => 200, 'reps' => 1],
            ['Idempotency-Key' => 'stolen-key'],
        )->assertSuccessful();

        $mine = WorkoutLine::factory()->create([
            'workout_id' => $this->workout->id,
            'exercise_id' => $this->exercise->id,
        ]);

        $response = $this->actingAs($this->user)->postJson(
            route('api.v1.sets.store'),
            ['workout_line_id' => $mine->id, 'weight' => 80, 'reps' => 8],
            ['Idempotency-Key' => 'stolen-key'],
        );

        // The stranger's set must neither come back nor be disturbed.
        expect((float) $response->json('data.weight'))->toBe(80.0)
            ->and(Set::where('weight', 200)->whereBelongsTo($strangerLine, 'workoutLine')->count())->toBe(1);
    });

    it('does not expose the key in the API response', function (): void {
        $line = WorkoutLine::factory()->create([
            'workout_id' => $this->workout->id,
            'exercise_id' => $this->exercise->id,
        ]);

        $response = $this->actingAs($this->user)->postJson(
            route('api.v1.sets.store'),
            ['workout_line_id' => $line->id, 'weight' => 80, 'reps' => 8],
            ['Idempotency-Key' => 'attempt-hidden'],
        );

        expect($response->json('data'))->not->toHaveKey('idempotency_key');
    });

    /**
     * La page de séance reconnaît à cette clé une création arrivée au serveur
     * dont la réponse s'est perdue, et qui attend encore dans sa file (#2048) :
     * sans elle, la série rechargée se montrait deux fois, la sienne et celle
     * de la file, et retirer l'une supprimait l'autre au vidage. La page ne
     * montre que la séance du compte : la clé n'y sort pas de chez lui.
     */
    it('rend à la page de séance la clé de chaque ligne et de chaque série créées', function (): void {
        $compte = User::factory()->create();
        $seance = Workout::factory()->create(['user_id' => $compte->id, 'ended_at' => null]);
        $exercice = Exercise::factory()->create();

        $this->actingAs($compte)->postJson(
            route('api.v1.workout-lines.store'),
            ['workout_id' => $seance->id, 'exercise_id' => $exercice->id],
            ['Idempotency-Key' => 'tentative-de-la-ligne'],
        )->assertSuccessful();
        $ligne = WorkoutLine::query()->where('workout_id', $seance->id)->sole();

        $this->actingAs($compte)->postJson(
            route('api.v1.sets.store'),
            ['workout_line_id' => $ligne->id, 'weight' => 82.5, 'reps' => 7],
            ['Idempotency-Key' => 'tentative-de-la-serie'],
        )->assertSuccessful();

        $this->actingAs($compte)
            ->get(route('workouts.show', $seance))
            ->assertOk()
            ->assertInertia(fn (Assert $page): Assert => $page
                ->component('Workouts/Show')
                ->where('workout.workout_lines.0.idempotency_key', 'tentative-de-la-ligne')
                ->where('workout.workout_lines.0.sets.0.idempotency_key', 'tentative-de-la-serie'));
    });
});

/*
 * Un démarrage par la page rejoué sous sa clé (un double appui, un rejeu de la
 * file hors ligne, #2049) mène à la même séance : une seule ligne, la même
 * redirection. Sans clé, le démarrage suivant renvoie vers la séance en cours,
 * comme avant.
 */
it('ne démarre qu’une séance quand la page rejoue le démarrage sous sa clé', function (): void {
    $compte = User::factory()->create();
    $cle = '5f1e7a2c-2049-4d3b-8c1a-0000000000a1';

    $premier = actingAs($compte)->post(route('workouts.store'), [], ['Idempotency-Key' => $cle]);
    $seance = Workout::query()->where('user_id', $compte->id)->sole();
    $rejeu = actingAs($compte)->post(route('workouts.store'), [], ['Idempotency-Key' => $cle]);

    $premier->assertRedirect(route('workouts.show', $seance));
    $rejeu->assertRedirect(route('workouts.show', $seance));

    expect($seance->idempotency_key)->toBe($cle)
        ->and(Workout::query()->where('user_id', $compte->id)->count())->toBe(1);
});

/*
 * La page de séance porte la clé de la séance : elle reconnaît ainsi une
 * séance démarrée sans réponse, qui attend encore dans sa file (#2049). Elle
 * ne montre que la séance du compte.
 */
it('rend à la page de séance la clé de son démarrage', function (): void {
    $compte = User::factory()->create();
    $cle = '5f1e7a2c-2049-4d3b-8c1a-0000000000a2';

    actingAs($compte)->post(route('workouts.store'), [], ['Idempotency-Key' => $cle]);
    $seance = Workout::query()->where('user_id', $compte->id)->sole();

    actingAs($compte)
        ->get(route('workouts.show', $seance))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page
            ->component('Workouts/Show')
            ->where('workout.idempotency_key', $cle));
});

/**
 * La clé arrive dans un en-tête que rien ne valide : un octet hors UTF-8 (un
 * « é » qu'un navigateur envoie en latin-1) allait jusqu'à la colonne, que
 * MySQL refuse, et la création répondait 500, que la file hors ligne réessaie
 * sans fin (#2071). Une clé qui n'est pas de l'ASCII imprimable est ignorée,
 * comme une clé trop longue : la création a lieu, sans clé.
 */
it('crée sans clé quand la clé n’est pas de l’ASCII imprimable', function (string $cle): void {
    Exceptions::fake();
    $compte = User::factory()->create();
    $seance = Workout::factory()->create(['user_id' => $compte->id, 'ended_at' => null]);
    $exercice = Exercise::factory()->create();
    $ligne = WorkoutLine::factory()->create(['workout_id' => $seance->id, 'exercise_id' => $exercice->id]);

    actingAs($compte)
        ->postJson(route('api.v1.sets.store'), ['workout_line_id' => $ligne->id, 'weight' => 80, 'reps' => 8], ['Idempotency-Key' => $cle])
        ->assertCreated();
    actingAs($compte)
        ->postJson(route('api.v1.workout-lines.store'), ['workout_id' => $seance->id, 'exercise_id' => $exercice->id], ['Idempotency-Key' => $cle])
        ->assertCreated();

    expect($ligne->sets()->sole()->idempotency_key)->toBeNull()
        ->and(WorkoutLine::query()->whereKeyNot($ligne->id)->where('workout_id', $seance->id)->sole()->idempotency_key)->toBeNull();
    Exceptions::assertNothingReported();
})->with([
    'octets hors UTF-8' => ["\xFF\xFE"],
    'é en latin-1' => ["cl\xE9"],
    'é en UTF-8' => ['clé'],
    'espace' => ['une cle'],
    'trop longue' => [str_repeat('a', 65)],
]);

/**
 * Crée par l'API, sous cette clé, une ligne dans la séance et une série dans
 * la ligne, comme la file hors ligne, et rend leurs identifiants.
 *
 * @return array{ligne: mixed, serie: mixed}
 */
function idempotenceCreerSousLaCle(User $compte, Workout $seance, WorkoutLine $ligne, string $cle): array
{
    return [
        'ligne' => actingAs($compte)
            ->postJson(route('api.v1.workout-lines.store'), ['workout_id' => $seance->id, 'exercise_id' => $ligne->exercise_id], ['Idempotency-Key' => $cle])
            ->assertSuccessful()
            ->json('data.id'),
        'serie' => actingAs($compte)
            ->postJson(route('api.v1.sets.store'), ['workout_line_id' => $ligne->id, 'weight' => 80, 'reps' => 8], ['Idempotency-Key' => $cle])
            ->assertSuccessful()
            ->json('data.id'),
    ];
}

/*
 * Deux clés qui ne diffèrent que par la casse nomment deux tentatives
 * (#2097) : la file hors ligne qui les envoie reçoit deux lignes et deux
 * séries, et chaque rejeu reçoit les siennes. Sous `utf8mb4_unicode_ci`, la
 * seconde recevait la ligne et la série de la première.
 */
it('crée deux lignes et deux séries pour deux clés qui ne diffèrent que par la casse', function (string $cle, string $autreCle): void {
    $compte = User::factory()->create();
    $seance = Workout::factory()->create(['user_id' => $compte->id, 'ended_at' => null]);
    $ligne = WorkoutLine::factory()->create(['workout_id' => $seance->id, 'exercise_id' => Exercise::factory()->create()->id]);

    $premieres = idempotenceCreerSousLaCle($compte, $seance, $ligne, $cle);
    $secondes = idempotenceCreerSousLaCle($compte, $seance, $ligne, $autreCle);

    expect($secondes['ligne'])->not->toBe($premieres['ligne'])
        ->and($secondes['serie'])->not->toBe($premieres['serie'])
        ->and(idempotenceCreerSousLaCle($compte, $seance, $ligne, $cle))->toBe($premieres)
        ->and(idempotenceCreerSousLaCle($compte, $seance, $ligne, $autreCle))->toBe($secondes)
        ->and(WorkoutLine::query()->whereKey($secondes['ligne'])->value('idempotency_key'))->toBe($autreCle)
        ->and(Set::query()->whereKey($secondes['serie'])->value('idempotency_key'))->toBe($autreCle)
        ->and(WorkoutLine::query()->where('workout_id', $seance->id)->count())->toBe(3)
        ->and($ligne->sets()->count())->toBe(2);
})->with([
    'minuscules puis capitales' => ['tentative-a', 'TENTATIVE-A'],
    'un UUID puis le même en capitales' => ['5f1e7a2c-2097-4d3b-8c1a-00000000000a', '5F1E7A2C-2097-4D3B-8C1A-00000000000A'],
]);

/*
 * Une espace finale ne fait pas non plus une seule tentative de deux, mais ce
 * n'est pas la colonne qui l'écarte : `ascii_bin`, comme
 * `utf8mb4_unicode_ci`, tient une espace finale pour insignifiante (PAD
 * SPACE). Le motif de `CreateSetAction::idempotencyKey()` refuse l'espace :
 * la clé qui en porte une est ignorée, et la création a lieu sans clé
 * (#2097). Un motif qui admettrait l'espace rendrait à la seconde clé la
 * ligne et la série de la première.
 */
it('crée deux lignes et deux séries pour deux clés qui ne diffèrent que par une espace finale', function (): void {
    $compte = User::factory()->create();
    $seance = Workout::factory()->create(['user_id' => $compte->id, 'ended_at' => null]);
    $ligne = WorkoutLine::factory()->create(['workout_id' => $seance->id, 'exercise_id' => Exercise::factory()->create()->id]);

    $premieres = idempotenceCreerSousLaCle($compte, $seance, $ligne, 'tentative-a');
    $secondes = idempotenceCreerSousLaCle($compte, $seance, $ligne, 'tentative-a ');

    expect($secondes['ligne'])->not->toBe($premieres['ligne'])
        ->and($secondes['serie'])->not->toBe($premieres['serie'])
        ->and(WorkoutLine::query()->whereKey($secondes['ligne'])->value('idempotency_key'))->toBeNull()
        ->and(Set::query()->whereKey($secondes['serie'])->value('idempotency_key'))->toBeNull()
        ->and(idempotenceCreerSousLaCle($compte, $seance, $ligne, 'tentative-a'))->toBe($premieres);
});
