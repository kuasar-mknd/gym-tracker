<?php

declare(strict_types=1);

use App\Models\Fast;
use App\Models\Workout;
use Illuminate\Database\Eloquent\MissingAttributeException;
use Illuminate\Database\Eloquent\Model;
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

/**
 * Une séance de la première occurrence, de 00:30Z à 00:50Z, relue avec ces
 * seules colonnes.
 *
 * @param  list<string>  $colonnes
 */
function instantDecaleSeanceLue(array $colonnes): Workout
{
    $seance = Workout::factory()->create(['started_at' => instantDecaleA('2026-10-25T00:30:00Z'), 'ended_at' => instantDecaleA('2026-10-25T00:50:00Z')]);

    return Workout::query()->select($colonnes)->findOrFail($seance->id);
}

/**
 * Un jeûne de la première occurrence, de 00:30Z à 00:50Z, relu avec ces
 * seules colonnes.
 *
 * @param  list<string>  $colonnes
 */
function instantDecaleJeuneLu(array $colonnes): Fast
{
    $jeune = Fast::factory()->create(['start_time' => instantDecaleA('2026-10-25T00:30:00Z'), 'end_time' => instantDecaleA('2026-10-25T00:50:00Z')]);

    return Fast::query()->select($colonnes)->findOrFail($jeune->id);
}

/*
 * Hors production, Eloquent est en mode strict (`AppServiceProvider`) : une
 * colonne casté en `datetime` qu'une lecture n'a pas choisie levait
 * `MissingAttributeException`. Il ne le fait que pour un cast primitif ou une
 * énumération ; le cast le refait lui-même, pour l'instant comme pour son
 * décalage. Sans lui, un instant oublié dans un `select` se lisait nul, et un
 * décalage oublié relisait sans bruit la seconde occurrence, une heure trop
 * tard : le piège qu'il a fallu corriger dans quatre lectures.
 */
it('lève sous le mode strict quand une lecture n’a pas choisi l’instant ou son décalage', function (Closure $lire, string $attribut, string $manquante): void {
    $lu = $lire();

    expect(Model::preventsAccessingMissingAttributes())->toBeTrue()
        ->and(fn (): mixed => $lu->getAttribute($attribut))->toThrow(MissingAttributeException::class, "[{$manquante}]");
})->with([
    'le décalage du début d’une séance' => [fn (): Workout => instantDecaleSeanceLue(['id', 'started_at']), 'started_at', 'started_at_offset'],
    'le début d’une séance' => [fn (): Workout => instantDecaleSeanceLue(['id', 'name']), 'started_at', 'started_at'],
    'la fin d’une séance' => [fn (): Workout => instantDecaleSeanceLue(['id', 'name', 'started_at', 'started_at_offset']), 'ended_at', 'ended_at'],
    'le décalage de la fin d’une séance' => [fn (): Workout => instantDecaleSeanceLue(['id', 'ended_at']), 'ended_at', 'ended_at_offset'],
    'le décalage du début d’un jeûne' => [fn (): Fast => instantDecaleJeuneLu(['id', 'start_time']), 'start_time', 'start_time_offset'],
    'la fin d’un jeûne' => [fn (): Fast => instantDecaleJeuneLu(['id', 'start_time', 'start_time_offset']), 'end_time', 'end_time'],
]);

it('lève aussi pour un décalage non choisi à côté d’un instant nul', function (): void {
    $seance = Workout::factory()->create(['started_at' => instantDecaleA('2026-10-25T00:30:00Z'), 'ended_at' => null]);
    $lue = Workout::query()->select(['id', 'ended_at'])->findOrFail($seance->id);

    expect(fn (): mixed => $lue->ended_at)->toThrow(MissingAttributeException::class, '[ended_at_offset]');
});

it('ne lève ni pour une lecture qui a tout choisi, ni pour un modèle qui vient d’être créé ou n’est pas enregistré', function (): void {
    $lue = instantDecaleSeanceLue(['id', 'started_at', 'started_at_offset', 'ended_at', 'ended_at_offset']);
    $creee = Workout::factory()->create(['started_at' => instantDecaleA('2026-10-25T00:30:00Z')]);
    $nouvelle = new Workout();

    expect($lue->started_at->toJSON())->toBe('2026-10-25T00:30:00.000000Z')
        ->and($lue->ended_at?->toJSON())->toBe('2026-10-25T00:50:00.000000Z')
        ->and(array_key_exists('ended_at', $creee->getAttributes()))->toBeFalse()
        ->and($creee->ended_at)->toBeNull()
        ->and($nouvelle->started_at)->toBeNull();
});

it('relit sans lever, hors du mode strict, l’heure seule d’un décalage non choisi, comme en production', function (): void {
    Model::preventAccessingMissingAttributes(false);
    $lue = instantDecaleSeanceLue(['id', 'started_at']);

    expect($lue->started_at->toJSON())->toBe('2026-10-25T01:30:00.000000Z')
        ->and($lue->ended_at)->toBeNull();
});

it('lève pour un pluck() ou un value() Eloquent de l’instant seul, que toBase() lit sans cast', function (): void {
    $seance = Workout::factory()->create(['started_at' => instantDecaleA('2026-10-25T00:30:00Z')]);
    $requete = fn (): \Illuminate\Database\Eloquent\Builder => Workout::query()->whereKey($seance->id);

    expect(fn (): mixed => $requete()->pluck('started_at'))->toThrow(MissingAttributeException::class, '[started_at_offset]')
        ->and(fn (): mixed => $requete()->value('started_at'))->toThrow(MissingAttributeException::class, '[started_at_offset]')
        ->and($requete()->toBase()->pluck('started_at')->all())->toBe(['2026-10-25 02:30:00']);
});
