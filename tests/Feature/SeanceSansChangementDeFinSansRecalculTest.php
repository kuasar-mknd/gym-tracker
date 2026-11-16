<?php

declare(strict_types=1);

use App\Enums\GoalPeriod;
use App\Jobs\SyncUserAchievements;
use App\Jobs\SyncUserGoals;
use App\Models\Goal;
use App\Models\Set;
use App\Models\User;
use App\Models\Workout;
use App\Models\WorkoutLine;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;

/*
 * Succès et objectifs ne lisent que les séances faites (`User::seancesFaites()`,
 * #2110) : leur appartenance et leur début. Une séance qu'on enregistre sans
 * toucher à sa fin, ni au début d'une séance terminée, ne change rien à ce
 * qu'ils comptent : rien à mettre en file (#2351).
 */
function seanceEnregistreeAvec(User $compte, bool $terminee): Workout
{
    $debut = Carbon::parse('2026-11-11 18:00:00');

    return Workout::withoutEvents(fn (): Workout => Workout::factory()->create([
        'user_id' => $compte->id,
        'started_at' => $debut,
        'ended_at' => $terminee ? $debut->copy()->addHour() : null,
    ]));
}

it('ne met rien en file quand on démarre une séance', function (): void {
    $compte = User::factory()->create();
    Queue::fake();

    Workout::factory()->create(['user_id' => $compte->id, 'ended_at' => null]);

    Queue::assertNotPushed(SyncUserAchievements::class);
    Queue::assertNotPushed(SyncUserGoals::class);
});

it('ne met rien en file quand on renomme ou annote une séance en cours', function (): void {
    $seance = seanceEnregistreeAvec(User::factory()->create(), false);
    Queue::fake();

    $seance->update(['name' => 'Jambes', 'notes' => 'Lourd']);

    Queue::assertNotPushed(SyncUserAchievements::class);
    Queue::assertNotPushed(SyncUserGoals::class);
});

it('ne met rien en file quand on déplace le début d’une séance en cours', function (): void {
    $seance = seanceEnregistreeAvec(User::factory()->create(), false);
    Queue::fake();

    $seance->update(['started_at' => Carbon::parse('2026-11-10 09:00:00')]);

    Queue::assertNotPushed(SyncUserAchievements::class);
    Queue::assertNotPushed(SyncUserGoals::class);
});

it('ne met rien en file quand on renomme une séance terminée', function (): void {
    $seance = seanceEnregistreeAvec(User::factory()->create(), true);
    Queue::fake();

    $seance->update(['name' => 'Dos', 'notes' => 'Bien']);

    Queue::assertNotPushed(SyncUserAchievements::class);
    Queue::assertNotPushed(SyncUserGoals::class);
});

it('met les recalculs en file quand on termine une séance', function (): void {
    $seance = seanceEnregistreeAvec(User::factory()->create(), false);
    Queue::fake();

    $seance->update(['ended_at' => Carbon::parse('2026-11-11 19:00:00')]);

    Queue::assertPushed(SyncUserAchievements::class);
    Queue::assertPushed(SyncUserGoals::class);
});

it('met les recalculs en file quand on rouvre une séance terminée', function (): void {
    $seance = seanceEnregistreeAvec(User::factory()->create(), true);
    Queue::fake();

    $seance->update(['ended_at' => null]);

    Queue::assertPushed(SyncUserAchievements::class);
    Queue::assertPushed(SyncUserGoals::class);
});

it('met les recalculs en file quand on déplace le début d’une séance terminée', function (): void {
    $seance = seanceEnregistreeAvec(User::factory()->create(), true);
    Queue::fake();

    $seance->update(['started_at' => Carbon::parse('2026-11-03 18:00:00')]);

    Queue::assertPushed(SyncUserAchievements::class);
    Queue::assertPushed(SyncUserGoals::class);
});

it('ne met rien en file pour une séance née terminée mais vide, et la première série cochée le fait', function (): void {
    $compte = User::factory()->create();
    Queue::fake();

    $seance = Workout::factory()->create([
        'user_id' => $compte->id,
        'started_at' => Carbon::parse('2026-11-11 18:00:00'),
        'ended_at' => Carbon::parse('2026-11-11 19:00:00'),
    ]);

    // Sans série cochée, elle n'est pas faite : rien à compter encore.
    Queue::assertNotPushed(SyncUserAchievements::class);
    Queue::assertNotPushed(SyncUserGoals::class);

    Set::factory()->create([
        'workout_line_id' => WorkoutLine::factory()->create(['workout_id' => $seance->id])->id,
        'is_completed' => true,
    ]);

    Queue::assertPushed(SyncUserAchievements::class);
    Queue::assertPushed(SyncUserGoals::class);
});

it('met les recalculs en file quand on corrige une série d’une séance terminée', function (): void {
    $seance = Workout::factory()->faite()->create(['user_id' => User::factory()->create()->id]);
    $serie = Set::query()->where('workout_line_id', WorkoutLine::query()->where('workout_id', $seance->id)->value('id'))->firstOrFail();
    Queue::fake();

    $serie->update(['weight' => 100]);

    Queue::assertPushed(SyncUserAchievements::class);
    Queue::assertPushed(SyncUserGoals::class);
});

it('garde l’objectif hebdomadaire juste : terminée elle compte, déplacée d’une semaine elle ne compte plus', function (): void {
    Carbon::setTestNow('2026-11-11 20:00:00');
    $compte = User::factory()->create();
    $objectif = Goal::factory()->create([
        'user_id' => $compte->id,
        'type' => 'frequency',
        'period' => GoalPeriod::Week,
        'target_value' => 3,
        'start_value' => 0,
        'current_value' => 0,
    ]);
    $seance = Workout::factory()->faite()->create([
        'user_id' => $compte->id,
        'started_at' => Carbon::parse('2026-11-11 18:00:00'),
        'ended_at' => null,
    ]);
    $seance->update(['ended_at' => null]);

    // Série cochée, séance en cours : rien n'est encore fait.
    expect($objectif->refresh()->current_value)->toBe(0.0);

    $seance->update(['name' => 'Pectoraux']);
    expect($objectif->refresh()->current_value)->toBe(0.0);

    $seance->update(['ended_at' => Carbon::parse('2026-11-11 19:00:00')]);
    expect($objectif->refresh()->current_value)->toBe(1.0);

    $seance->update(['name' => 'Pectoraux, lourd']);
    expect($objectif->refresh()->current_value)->toBe(1.0);

    $seance->update(['started_at' => Carbon::parse('2026-11-03 18:00:00'), 'ended_at' => Carbon::parse('2026-11-03 19:00:00')]);
    expect($objectif->refresh()->current_value)->toBe(0.0);

    Carbon::setTestNow();
});
