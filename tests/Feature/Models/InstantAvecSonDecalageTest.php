<?php

declare(strict_types=1);

use App\Models\Fast;
use App\Models\Workout;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/*
 * Le début et la fin d'une séance et d'un jeûne s'écrivent à l'heure murale
 * de l'application, avec le décalage de l'instant à côté (#2159).
 *
 * Le décalage ne fait que choisir entre les occurrences d'une heure murale
 * que le fuseau de l'application répète : il ne la déplace jamais. Une ligne
 * sans décalage, ou dont le décalage ne va pas avec son heure (une écriture
 * qui serait passée par le constructeur de requêtes, un fuseau d'application
 * changé depuis), se lit comme avant, comme le cast `datetime` la lisait.
 */

/**
 * Un instant écrit en UTC, dans le fuseau de l'application.
 */
function instantDecaleA(string $utc): Carbon
{
    return Carbon::parse($utc)->setTimezone(config()->string('app.timezone'));
}

it('écrit l’heure murale de l’application et le décalage de l’instant, et relit cet instant', function (string $utc, string $murale, int $decalage): void {
    $seance = Workout::factory()->create(['started_at' => instantDecaleA($utc), 'ended_at' => instantDecaleA($utc)]);
    $jeune = Fast::factory()->create(['start_time' => instantDecaleA($utc), 'end_time' => instantDecaleA($utc)]);

    $seance = Workout::query()->findOrFail($seance->id);
    $jeune = Fast::query()->findOrFail($jeune->id);

    $instant = Carbon::parse($utc)->toJSON();

    expect([$seance->getRawOriginal('started_at'), $seance->getRawOriginal('ended_at'), $jeune->getRawOriginal('start_time'), $jeune->getRawOriginal('end_time')])
        ->toBe([$murale, $murale, $murale, $murale]);
    expect([$seance->getRawOriginal('started_at_offset'), $seance->getRawOriginal('ended_at_offset'), $jeune->getRawOriginal('start_time_offset'), $jeune->getRawOriginal('end_time_offset')])
        ->toBe([$decalage, $decalage, $decalage, $decalage]);
    expect([$seance->started_at->toJSON(), $seance->ended_at?->toJSON(), $jeune->start_time->toJSON(), $jeune->end_time?->toJSON()])
        ->toBe([$instant, $instant, $instant, $instant]);
    expect($seance->started_at->getTimezone()->getName())->toBe(config()->string('app.timezone'));
})->with([
    'première occurrence de l’heure répétée' => ['2026-10-25T00:30:00Z', '2026-10-25 02:30:00', 120],
    'seconde occurrence de l’heure répétée' => ['2026-10-25T01:30:00Z', '2026-10-25 02:30:00', 60],
    'heure d’été' => ['2026-07-14T10:00:00Z', '2026-07-14 12:00:00', 120],
    'heure d’hiver' => ['2026-12-01T10:00:00Z', '2026-12-01 11:00:00', 60],
]);

it('écrit à l’heure murale de l’application un instant donné dans un autre fuseau', function (mixed $donne): void {
    $seance = Workout::factory()->create(['started_at' => $donne]);

    expect($seance->getRawOriginal('started_at'))->toBe('2026-10-25 02:30:00')
        ->and($seance->getRawOriginal('started_at_offset'))->toBe(120)
        ->and(Workout::query()->findOrFail($seance->id)->started_at->toJSON())->toBe('2026-10-25T00:30:00.000000Z');
})->with([
    'un Carbon en UTC' => [fn (): Carbon => Carbon::parse('2026-10-25T00:30:00Z')],
    'un DateTimeImmutable de Toronto' => [fn (): DateTimeImmutable => new DateTimeImmutable('2026-10-24 20:30:00', new DateTimeZone('America/Toronto'))],
    'une chaîne avec son décalage' => ['2026-10-25 02:30:00+02:00'],
    'une chaîne en UTC' => ['2026-10-25T00:30:00.000Z'],
    'un horodatage Unix' => [1_792_888_200],
]);

it('lit une heure sans décalage dans le fuseau de l’application, la seconde occurrence pour l’heure répétée', function (): void {
    $seance = Workout::factory()->create(['started_at' => '2026-10-25 02:30:00']);

    expect($seance->getRawOriginal('started_at_offset'))->toBe(60)
        ->and($seance->started_at->toJSON())->toBe('2026-10-25T01:30:00.000000Z');
});

it('efface le décalage d’une fin effacée', function (): void {
    $seance = Workout::factory()->create(['started_at' => instantDecaleA('2026-10-25T00:30:00Z'), 'ended_at' => instantDecaleA('2026-10-25T00:50:00Z')]);

    $seance->update(['ended_at' => null]);
    $seance = Workout::query()->findOrFail($seance->id);

    expect($seance->ended_at)->toBeNull()
        ->and($seance->getRawOriginal('ended_at_offset'))->toBeNull()
        ->and($seance->getRawOriginal('started_at_offset'))->toBe(120);
});

it('lit comme le cast datetime une ligne sans décalage, ou dont le décalage ne va pas avec son heure', function (?int $decalage, string $murale, string $instant): void {
    $seance = Workout::factory()->create(['started_at' => instantDecaleA('2026-10-25T00:30:00Z')]);

    DB::table('workouts')->where('id', $seance->id)->update(['started_at' => $murale, 'started_at_offset' => $decalage]);

    expect(Workout::query()->findOrFail($seance->id)->started_at->toJSON())->toBe($instant);
})->with([
    'sans décalage, dans l’heure répétée' => [null, '2026-10-25 02:30:00', '2026-10-25T01:30:00.000000Z'],
    'un décalage d’été sous une heure d’hiver' => [120, '2026-12-01 10:00:00', '2026-12-01T09:00:00.000000Z'],
    'un décalage d’hiver sous une heure d’été' => [60, '2026-07-14 12:00:00', '2026-07-14T10:00:00.000000Z'],
    'un décalage qu’aucun fuseau ne porte' => [5_000, '2026-10-25 02:30:00', '2026-10-25T01:30:00.000000Z'],
    'un décalage d’un autre fuseau' => [-240, '2026-10-25 02:30:00', '2026-10-25T01:30:00.000000Z'],
]);

it('ne laisse pas l’instant rendu changer le modèle', function (): void {
    $seance = Workout::factory()->create(['started_at' => instantDecaleA('2026-10-25T00:30:00Z')]);

    $seance->started_at->addHours(3);

    expect($seance->isDirty())->toBeFalse()
        ->and($seance->started_at->toJSON())->toBe('2026-10-25T00:30:00.000000Z');
});

it('tait le décalage dans ce que le modèle sert', function (): void {
    $seance = Workout::factory()->create(['started_at' => instantDecaleA('2026-10-25T00:30:00Z')]);
    $jeune = Fast::factory()->create(['start_time' => instantDecaleA('2026-10-25T00:30:00Z')]);

    expect($seance->toArray())->not->toHaveKeys(['started_at_offset', 'ended_at_offset'])
        ->toHaveKey('started_at', '2026-10-25T00:30:00.000000Z');
    expect($jeune->toArray())->not->toHaveKeys(['start_time_offset', 'end_time_offset'])
        ->toHaveKey('start_time', '2026-10-25T00:30:00.000000Z');
});

it('fabrique une séance et un jeûne d’avant le décalage, lus comme le cast datetime les lisait', function (): void {
    $seance = Workout::factory()->faite()->sansDecalage()->create(['started_at' => instantDecaleA('2026-10-25T00:30:00Z')]);
    $jeune = Fast::factory()->sansDecalage()->create(['start_time' => instantDecaleA('2026-10-25T00:30:00Z'), 'end_time' => instantDecaleA('2026-10-25T00:45:00Z')]);

    $seance = Workout::query()->findOrFail($seance->id);
    $jeune = Fast::query()->findOrFail($jeune->id);

    expect([$seance->getRawOriginal('started_at_offset'), $seance->getRawOriginal('ended_at_offset'), $jeune->getRawOriginal('start_time_offset'), $jeune->getRawOriginal('end_time_offset')])
        ->toBe([null, null, null, null]);
    expect([$seance->started_at->toJSON(), $seance->ended_at?->toJSON(), $jeune->start_time->toJSON(), $jeune->end_time?->toJSON()])
        ->toBe(['2026-10-25T01:30:00.000000Z', '2026-10-25T01:30:00.000000Z', '2026-10-25T01:30:00.000000Z', '2026-10-25T01:45:00.000000Z']);
});
