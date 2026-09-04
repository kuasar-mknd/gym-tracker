<?php

declare(strict_types=1);

use App\Actions\Workouts\CreateSetAction;
use App\Models\Set;
use App\Models\User;
use App\Models\Workout;
use App\Models\WorkoutLine;
use Illuminate\Support\Facades\Cache;

it('creates a set and clears volume stats', function (): void {
    $user = User::factory()->create();
    $workout = Workout::factory()->create(['user_id' => $user->id]);
    $workoutLine = WorkoutLine::factory()->create(['workout_id' => $workout->id]);

    $data = [
        'workout_line_id' => 999, // Should be ignored
        'weight' => 100.5,
        'reps' => 10,
        'is_warmup' => false,
        'is_completed' => true,
    ];

    Cache::spy();

    $action = app(CreateSetAction::class);
    $set = $action->execute($user, $workoutLine, $data);

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

    Cache::shouldHaveReceived('increment')
        ->with("stats-version.seances.{$user->id}")
        ->once();
    // L'assertion sur `stats.monthly_volume_comparison` est partie : cette
    // entree n'est jamais ecrite, donc l'oublier ne prouvait rien (#1502).
});

/*
 * Sept mutants sur vingt-et-un survivaient a ce fichier. Ce qu'ils laissaient
 * passer, dans l'ordre du code :
 *
 *  - lignes 38-39, la sortie anticipee sur cle d'idempotence : la supprimer, ou
 *    rendre son `instanceof` toujours faux, ne se voit dans AUCUNE valeur
 *    rendue. Le rejeu retente l'ecriture, l'index unique la refuse, le
 *    rattrapage relit la meme serie et la rend. Le resultat est identique — le
 *    cout ne l'est pas, et c'est la seule chose qui distingue les deux ;
 *  - ligne 92, la borne de la cle : la faire passer de `<= 64` a `< 64` ou a
 *    `<= 65`, ou cesser d'ecarter la chaine vide. La colonne fait 64
 *    caracteres, donc 64 doit passer et 65 doit etre refuse ; et une cle vide
 *    doit valoir « pas de cle », faute de quoi deux series sans rapport
 *    entreraient en collision sur l'index unique et la seconde rendrait la
 *    premiere.
 */

use Illuminate\Support\Facades\DB;

/**
 * @return array{0: User, 1: WorkoutLine}
 */
function ligneNeuvePourSerie(): array
{
    $user = User::factory()->create();
    $workout = Workout::factory()->create(['user_id' => $user->id]);

    return [$user, WorkoutLine::factory()->create(['workout_id' => $workout->id])];
}

it('rend la serie deja ecrite sans retenter l ecriture quand la cle est rejouee', function (): void {
    [$user, $workoutLine] = ligneNeuvePourSerie();

    $donnees = [
        'weight' => 100.5,
        'reps' => 10,
        'idempotency_key' => 'tentative-42',
    ];

    $action = app(CreateSetAction::class);
    $premiere = $action->execute($user, $workoutLine, $donnees);

    $requetes = 0;
    DB::listen(function () use (&$requetes): void {
        $requetes++;
    });

    $rejeu = $action->execute($user, $workoutLine, $donnees);

    // Releve immediatement : les assertions qui suivent interrogent la base
    // elles aussi, et gonfleraient le compte qu'on cherche a mesurer.
    $requetesDuRejeu = $requetes;

    expect($rejeu->id)->toBe($premiere->id);
    $this->assertDatabaseCount('sets', 1);

    // Une requete, et une seule : la recherche par cle. Sans la sortie
    // anticipee, l'action tente l'insertion, l'index unique la refuse, et il
    // faut une seconde lecture pour retrouver la serie — le resultat rendu est
    // le meme, le compte ne l'est pas. C'est la seule assertion qui separe les
    // deux chemins.
    expect($requetesDuRejeu)->toBe(1);
});

it('accepte une cle de soixante-quatre caracteres et refuse la soixante-cinquieme', function (): void {
    $soixanteQuatre = str_repeat('a', 64);
    $soixanteCinq = str_repeat('a', 65);

    // 64 est la largeur de la colonne `sets.idempotency_key`, pas un chiffre
    // rond choisi au hasard : la borne doit tomber exactement la.
    expect(CreateSetAction::idempotencyKey(['idempotency_key' => $soixanteQuatre]))->toBe($soixanteQuatre);
    expect(CreateSetAction::idempotencyKey(['idempotency_key' => $soixanteCinq]))->toBeNull();
});

it('traite une cle absente, vide ou non textuelle comme une absence de cle', function (): void {
    expect(CreateSetAction::idempotencyKey([]))->toBeNull();
    expect(CreateSetAction::idempotencyKey(['idempotency_key' => '']))->toBeNull();
    expect(CreateSetAction::idempotencyKey(['idempotency_key' => 42]))->toBeNull();
});

it('n enregistre pas une cle vide, qui ferait collisionner deux series distinctes', function (): void {
    [$user, $workoutLine] = ligneNeuvePourSerie();

    $action = app(CreateSetAction::class);

    $premiere = $action->execute($user, $workoutLine, ['weight' => 50.0, 'reps' => 10, 'idempotency_key' => '']);
    $seconde = $action->execute($user, $workoutLine, ['weight' => 60.0, 'reps' => 8, 'idempotency_key' => '']);

    // Deux series bien distinctes : si la chaine vide etait retenue comme cle,
    // l'index unique (workout_line_id, idempotency_key) refuserait la seconde
    // et l'action rendrait la premiere a sa place.
    expect($seconde->id)->not->toBe($premiere->id);
    expect($premiere->idempotency_key)->toBeNull();
    expect($seconde->idempotency_key)->toBeNull();
    $this->assertDatabaseCount('sets', 2);
});

/*
 * Une série naît en dernier : la première prend le rang 0, la suivante le
 * rang max + 1, et un rang fourni explicitement est conservé.
 */
it('range chaque nouvelle série après la dernière, sauf rang explicite', function (): void {
    [$user, $workoutLine] = ligneNeuvePourSerie();
    $action = app(CreateSetAction::class);

    $premiere = $action->execute($user, $workoutLine, ['weight' => 50, 'reps' => 5]);
    $seconde = $action->execute($user, $workoutLine, ['weight' => 60, 'reps' => 5]);
    $explicite = $action->execute($user, $workoutLine, ['weight' => 70, 'reps' => 5, 'order' => 7]);

    expect($premiere->order)->toBe(0)
        ->and($seconde->order)->toBe(1)
        ->and($explicite->order)->toBe(7);
});
