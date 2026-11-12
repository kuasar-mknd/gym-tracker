<?php

declare(strict_types=1);

use App\Models\Exercise;
use App\Models\PersonalRecord;
use App\Models\Set;
use App\Models\User;
use App\Models\Workout;
use App\Models\WorkoutLine;
use App\Services\PersonalRecordService;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;

/**
 * @return array{0: User, 1: Exercise, 2: WorkoutLine}
 */
function recordsEgaliteScene(): array
{
    $user = User::factory()->create();
    $exercice = Exercise::factory()->create(['user_id' => $user->id, 'type' => 'strength']);
    $seance = Workout::factory()->create(['user_id' => $user->id, 'started_at' => now()->subHour()]);
    $ligne = WorkoutLine::factory()->create(['workout_id' => $seance->id, 'exercise_id' => $exercice->id]);

    return [$user->refresh(), $exercice, $ligne];
}

/**
 * Combien de fois la section des records de l'exercice a dû attendre son
 * verrou, tenu par le test pendant `$action`.
 */
function recordsEgaliteAttentes(User $user, Exercise $exercice, Closure $action): int
{
    $verrou = Cache::lock("records:{$user->id}:{$exercice->id}", 60);
    expect($verrou->get())->toBeTrue();

    $attentes = 0;
    Sleep::fake();
    Sleep::whenFakingSleep(function () use (&$attentes, $verrou): void {
        $attentes++;
        $verrou->release();
    });

    try {
        $action();
    } finally {
        $verrou->release();
    }

    return $attentes;
}

it('ne déplace pas un record de volume qu’une série égale seulement', function (float $poids, int $repetitions): void {
    [$user, , $ligne] = recordsEgaliteScene();
    $service = app(PersonalRecordService::class);

    $premiere = Set::factory()->createQuietly(['workout_line_id' => $ligne->id, 'weight' => $poids, 'reps' => $repetitions, 'is_warmup' => false, 'is_completed' => true]);
    $service->synchroniserLesRecordsDeLaSerie($premiere, $user);

    $passe = now()->subDays(3)->startOfSecond();
    PersonalRecord::query()->where('user_id', $user->id)->update(['achieved_at' => $passe]);

    $seconde = Set::factory()->createQuietly(['workout_line_id' => $ligne->id, 'weight' => $poids, 'reps' => $repetitions, 'is_warmup' => false, 'is_completed' => true]);
    $service->synchroniserLesRecordsDeLaSerie($seconde, $user);

    $record = PersonalRecord::query()->where('user_id', $user->id)->where('type', 'max_volume_set')->firstOrFail();

    expect($record->set_id)->toBe($premiere->id)
        ->and($record->achieved_at->equalTo($passe))->toBeTrue();
})->with([
    '1,1 kg × 3' => [1.1, 3],
    '20,1 kg × 3' => [20.1, 3],
]);

it('ne prend pas le verrou des records pour une série qui vient d’être créée', function (): void {
    [$user, $exercice, $ligne] = recordsEgaliteScene();

    $attentes = recordsEgaliteAttentes($user, $exercice, fn () => Set::factory()->create(['workout_line_id' => $ligne->id, 'weight' => 0, 'reps' => 0, 'is_warmup' => false, 'is_completed' => false]));

    expect($attentes)->toBe(0);
});

it('ne prend pas le verrou des records quand ni le poids, ni les répétitions, ni les cases n’ont changé', function (): void {
    [$user, $exercice, $ligne] = recordsEgaliteScene();
    $serie = Set::factory()->create(['workout_line_id' => $ligne->id, 'weight' => 0, 'reps' => 0, 'is_warmup' => false, 'is_completed' => false]);

    $attentes = recordsEgaliteAttentes($user, $exercice, fn () => $serie->update(['order' => 7]));

    expect($attentes)->toBe(0);
});

it('prend le verrou des records quand une des quatre colonnes change', function (string $colonne, mixed $valeur): void {
    [$user, $exercice, $ligne] = recordsEgaliteScene();
    $serie = Set::factory()->create(['workout_line_id' => $ligne->id, 'weight' => 0, 'reps' => 0, 'is_warmup' => false, 'is_completed' => false]);

    $attentes = recordsEgaliteAttentes($user, $exercice, fn () => $serie->update([$colonne => $valeur]));

    expect($attentes)->toBeGreaterThan(0);
})->with([
    'répétitions' => ['reps', 5],
    'poids' => ['weight', 50],
    'validée' => ['is_completed', true],
    'échauffement' => ['is_warmup', true],
]);

/**
 * @param  array<string, float>  $valeurs  La valeur stockée de chaque type.
 */
function recordsEgaliteSansSerie(User $user, Exercise $exercice, array $valeurs): void
{
    foreach ($valeurs as $type => $valeur) {
        PersonalRecord::query()->create(['user_id' => $user->id, 'exercise_id' => $exercice->id, 'type' => $type, 'value' => $valeur, 'achieved_at' => now()->subDays(3)]);
    }
}

it('n’annonce pas un record que la reconstruction retrouve à la même valeur', function (): void {
    Queue::fake();
    [$user, $exercice, $ligne] = recordsEgaliteScene();
    $user->notificationPreferences()->create(['type' => 'personal_record', 'is_enabled' => true]);
    Set::factory()->createQuietly(['workout_line_id' => $ligne->id, 'weight' => 1.1, 'reps' => 3, 'is_warmup' => false, 'is_completed' => true]);
    recordsEgaliteSansSerie($user, $exercice, ['max_weight' => 1.1, 'max_1rm' => 1.21, 'max_volume_set' => 3.3]);

    app(PersonalRecordService::class)->recompute($user, $exercice->id);

    Queue::assertNotPushed(SendQueuedNotifications::class);
});

it('annonce le record que la reconstruction donne à une série au-dessus de sa valeur d’avant', function (): void {
    Queue::fake();
    [$user, $exercice, $ligne] = recordsEgaliteScene();
    $user->notificationPreferences()->create(['type' => 'personal_record', 'is_enabled' => true]);
    Set::factory()->createQuietly(['workout_line_id' => $ligne->id, 'weight' => 3.33, 'reps' => 1, 'is_warmup' => false, 'is_completed' => true]);
    recordsEgaliteSansSerie($user, $exercice, ['max_weight' => 3.3, 'max_1rm' => 3.3, 'max_volume_set' => 3.3]);

    app(PersonalRecordService::class)->recompute($user, $exercice->id);

    Queue::assertPushed(SendQueuedNotifications::class, 3);
});

it('compare les volumes au centième : battu de peu, jamais abaissé', function (float $stocke, float $volume, bool $battu): void {
    [$user, $exercice, $ligne] = recordsEgaliteScene();
    recordsEgaliteSansSerie($user, $exercice, ['max_volume_set' => $stocke]);
    $serie = Set::factory()->createQuietly(['workout_line_id' => $ligne->id, 'weight' => $volume, 'reps' => 1, 'is_warmup' => false, 'is_completed' => true]);

    app(PersonalRecordService::class)->synchroniserLesRecordsDeLaSerie($serie, $user);

    $record = PersonalRecord::query()->where('user_id', $user->id)->where('type', 'max_volume_set')->firstOrFail();

    expect((float) $record->value)->toBe($battu ? $volume : $stocke)
        ->and($record->set_id)->toBe($battu ? $serie->id : null);
})->with([
    '3,30 battu par 3,33' => [3.30, 3.33, true],
    '3,30 battu par 3,31' => [3.30, 3.31, true],
    '3,34 non battu par 3,31' => [3.34, 3.31, false],
    '3,34 non battu par 3,33' => [3.34, 3.33, false],
]);
