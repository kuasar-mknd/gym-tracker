<?php

declare(strict_types=1);

use App\Actions\Habits\ToggleHabitAction;
use App\Models\Habit;
use App\Models\HabitLog;
use App\Models\User;

/**
 * La regle de validation est `['required', 'date']`, pas un format fixe.
 *
 * `whereDate()` comparait la partie date et tolerait donc n'importe quelle
 * forme ; il interdisait aussi l'index UNIQUE `(habit_id, date)`. Comparer la
 * colonne nue rend l'index utilisable mais exige que la valeur soit normalisee
 * — sans quoi un horodatage complet ne retrouverait jamais son pointage.
 */
it('retrouve le pointage du jour meme quand la date porte une heure', function (): void {
    $user = User::factory()->create();
    $habit = Habit::factory()->create(['user_id' => $user->id]);

    HabitLog::factory()->create(['habit_id' => $habit->id, 'date' => '2026-08-27']);

    app(ToggleHabitAction::class)->execute($habit, '2026-08-27 14:32:00');

    expect(HabitLog::where('habit_id', $habit->id)->count())->toBe(0);
});

it('cree le pointage a la bonne date depuis un horodatage', function (): void {
    $user = User::factory()->create();
    $habit = Habit::factory()->create(['user_id' => $user->id]);

    app(ToggleHabitAction::class)->execute($habit, '2026-08-27 14:32:00');

    expect(HabitLog::where('habit_id', $habit->id)->first()?->date?->toDateString())->toBe('2026-08-27');
});

it('bascule dans les deux sens sur une date simple', function (): void {
    $user = User::factory()->create();
    $habit = Habit::factory()->create(['user_id' => $user->id]);
    $action = app(ToggleHabitAction::class);

    $action->execute($habit, '2026-08-27');
    expect(HabitLog::where('habit_id', $habit->id)->count())->toBe(1);

    $action->execute($habit, '2026-08-27');
    expect(HabitLog::where('habit_id', $habit->id)->count())->toBe(0);
});
