<?php

declare(strict_types=1);

use App\Models\Exercise;
use App\Models\Set;
use App\Models\User;
use App\Models\Workout;
use App\Models\WorkoutLine;
use App\Services\PersonalRecordService;
use App\Support\VersionDeCache;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/*
 * La version des records que lit la page Records se renouvelle après la
 * validation de la transaction de l'écrivain, ou après son annulation, jamais
 * avant (#2434, #2108).
 *
 * Renouvelée dans la transaction, une lecture servie avant la validation
 * remettrait en cache l'ancien état sous la version neuve, pour trente minutes.
 * Annulée, la transaction laisse ce qu'une lecture faite en son sein avait vu :
 * la version se renouvelle aussi.
 *
 * Ces tests valident pour de bon, d'où `DatabaseTruncation`, hors de
 * tests/Feature (`.ai/rules/tests.md`) : sous `RefreshDatabase`, la transaction
 * du test serait prise pour la racine et le rappel partirait aussitôt.
 */

uses(TestCase::class, DatabaseTruncation::class);

afterEach(function (): void {
    $this->truncateTablesForAllConnections();
});

/**
 * @return array{0: User, 1: Set}
 */
function uneSerieQuiBatUnRecord(): array
{
    $compte = User::factory()->create();
    $exercice = Exercise::factory()->create(['user_id' => $compte->id, 'type' => 'strength']);
    $seance = Workout::factory()->create(['user_id' => $compte->id, 'ended_at' => now()]);
    $ligne = WorkoutLine::factory()->create(['workout_id' => $seance->id, 'exercise_id' => $exercice->id]);
    $serie = Set::factory()->createQuietly(['workout_line_id' => $ligne->id, 'weight' => 100, 'reps' => 5, 'is_completed' => true, 'is_warmup' => false]);

    return [$compte, $serie];
}

it('renouvelle la version des records après la validation de l’écrivain, pas dans sa transaction', function (): void {
    [$compte, $serie] = uneSerieQuiBatUnRecord();
    $cle = "stats-version.records.{$compte->id}";
    $avant = VersionDeCache::lire($cle);
    $pendant = null;

    DB::transaction(function () use ($serie, $compte, $cle, &$pendant): void {
        app(PersonalRecordService::class)->synchroniserLesRecordsDeLaSerie($serie, $compte);

        $pendant = VersionDeCache::lire($cle);
    });

    expect($pendant)->toBe($avant)
        ->and(VersionDeCache::lire($cle))->not->toBe($avant);
});

it('renouvelle la version des records quand la transaction de l’écrivain s’annule', function (): void {
    [$compte, $serie] = uneSerieQuiBatUnRecord();
    $cle = "stats-version.records.{$compte->id}";
    $avant = VersionDeCache::lire($cle);

    expect(fn (): mixed => DB::transaction(function () use ($serie, $compte): void {
        app(PersonalRecordService::class)->synchroniserLesRecordsDeLaSerie($serie, $compte);

        throw new RuntimeException('Transaction annulée par le test.');
    }))->toThrow(new RuntimeException('Transaction annulée par le test.'));

    expect(VersionDeCache::lire($cle))->not->toBe($avant);
});
