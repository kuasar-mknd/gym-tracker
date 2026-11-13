<?php

declare(strict_types=1);

use App\Jobs\SyncUserGoals;
use App\Models\Goal;
use App\Models\User;
use App\Services\GoalService;
use Illuminate\Support\Facades\Queue;

/*
 * Un recalcul déjà en vol a lu l'ancienne cible : une écriture de l'objectif
 * (contrôleur, panneau) qui arrive ensuite se faisait réécrire sa barre et son
 * atteinte d'avant. `Goal::booted()` met en file un recalcul après chaque
 * enregistrement et chaque suppression, qui relit l'objectif écrit.
 */

it('met un recalcul des objectifs en file à la création, à la modification et à la suppression d’un objectif', function (): void {
    $compte = User::factory()->create();
    Queue::fake();

    $objectif = Goal::factory()->create(['user_id' => $compte->getKey()]);
    Queue::assertPushed(SyncUserGoals::class, fn (SyncUserGoals $travail): bool => $travail->user->is($compte));
    Queue::assertPushed(SyncUserGoals::class, 1);

    Queue::fake();
    $objectif->update(['target_value' => 250]);
    Queue::assertPushed(SyncUserGoals::class, 1);

    Queue::fake();
    $objectif->delete();
    Queue::assertPushed(SyncUserGoals::class, 1);
});

it('ne met en file qu’un recalcul par enregistrement, et le recalcul n’en met aucun autre', function (): void {
    $compte = User::factory()->create();

    // Sous une file simulée, l'enregistrement met son recalcul en file sans le jouer : l'objectif garde sa barre fausse.
    Queue::fake();
    $objectif = Goal::factory()->create(['user_id' => $compte->getKey(), 'progress_pct' => 90, 'current_value' => 90]);
    expect($objectif->fresh()?->progress_pct)->toBe(90.0);

    Queue::fake();

    new SyncUserGoals($compte)->handle(app(GoalService::class));

    Queue::assertNothingPushed();
    expect($objectif->fresh()?->progress_pct)->not->toBe(90.0, 'le recalcul n’a rien écrit : le test ne prouverait rien');
});
