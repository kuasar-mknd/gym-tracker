<?php

declare(strict_types=1);

use App\Enums\GoalType;
use App\Jobs\SyncUserGoals;
use App\Models\Goal;
use App\Models\User;
use App\Models\Workout;
use App\Services\GoalService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;

/*
 * Le recalcul des objectifs ne crée jamais de ligne (#1985).
 *
 * `syncGoals()` lit les objectifs du compte, les recalcule, puis écrit ceux qui
 * ont changé. L'écriture était un upsert portant toutes les colonnes : sous
 * MySQL, un objectif supprimé entre la lecture et l'écriture était réinséré,
 * avec son ancien identifiant et ses anciennes valeurs. Elle ne fait plus que
 * mettre à jour des lignes existantes, en une seule instruction.
 */

/**
 * Un objectif de fréquence sur le compte.
 *
 * @param  array<string, mixed>  $attributs
 */
function recalculObjectifDeFrequence(User $user, array $attributs = []): Goal
{
    return Goal::factory()->create([
        'user_id' => $user->id,
        'type' => GoalType::Frequency,
        'exercise_id' => null,
        'start_value' => 0,
        'current_value' => 0,
        'progress_pct' => 0,
        'target_value' => 10,
        'completed_at' => null,
        ...$attributs,
    ]);
}

/**
 * Les instructions modifiantes émises pendant le geste.
 *
 * @return list<string>
 */
function recalculEcrituresPendant(callable $geste): array
{
    $ecritures = [];

    DB::listen(function (QueryExecuted $requete) use (&$ecritures): void {
        if (preg_match('/^\s*(insert|update|delete|replace)\b/i', $requete->sql) === 1) {
            $ecritures[] = $requete->sql;
        }
    });

    $geste();

    DB::getEventDispatcher()->forget(QueryExecuted::class);

    return $ecritures;
}

it('ne fait pas réapparaître un objectif supprimé pendant le recalcul', function (): void {
    $user = User::factory()->create();
    Workout::factory()->create(['user_id' => $user->id]);

    // Créé après la séance : le recalcul qui suit le fera passer de 0 à 1.
    $objectif = recalculObjectifDeFrequence($user);

    /*
     * La suppression tombe juste après la lecture des objectifs par le
     * recalcul, comme celle d'un utilisateur qui supprime l'objectif pendant
     * que le travail tourne pour son compte.
     */
    $supprime = false;
    DB::listen(function (QueryExecuted $requete) use ($objectif, &$supprime): void {
        if ($supprime || ! str_starts_with($requete->sql, 'select * from `goals`')) {
            return;
        }

        $supprime = true;
        $objectif->delete();
    });

    new SyncUserGoals($user)->handle(app(GoalService::class));

    DB::getEventDispatcher()->forget(QueryExecuted::class);

    expect($supprime)->toBeTrue()
        ->and(Goal::query()->whereKey($objectif->id)->exists())->toBeFalse();
});

it('écrit en une seule mise à jour les valeurs propres à chaque objectif modifié', function (): void {
    $this->freezeTime();

    $user = User::factory()->create();
    $autre = User::factory()->create();
    Workout::factory()->count(3)->create(['user_id' => $user->id]);

    $hier = now()->subDay();
    $atteint = recalculObjectifDeFrequence($user, ['target_value' => 2]);
    $rouvert = recalculObjectifDeFrequence($user, ['current_value' => 10, 'progress_pct' => 100, 'target_value' => 10, 'completed_at' => $hier]);
    $inchange = recalculObjectifDeFrequence($user, ['current_value' => 3, 'progress_pct' => 50, 'target_value' => 6]);
    $dAutrui = recalculObjectifDeFrequence($autre, ['current_value' => 7, 'progress_pct' => 70]);

    $creation = $inchange->refresh()->updated_at?->toDateTimeString();

    $this->travel(1)->hours();

    $ecritures = recalculEcrituresPendant(fn () => app(GoalService::class)->syncGoals($user));

    expect($ecritures)->toHaveCount(1)
        ->and($ecritures[0])->toStartWith('update');

    $atteint->refresh();
    $rouvert->refresh();
    $inchange->refresh();
    $dAutrui->refresh();

    expect($atteint->current_value)->toBe(3.0)
        ->and($atteint->progress_pct)->toBe(100.0)
        ->and($atteint->completed_at?->toDateTimeString())->toBe(now()->toDateTimeString())
        ->and($atteint->updated_at?->toDateTimeString())->toBe(now()->toDateTimeString())
        ->and($rouvert->current_value)->toBe(3.0)
        ->and($rouvert->progress_pct)->toBe(30.0)
        ->and($rouvert->completed_at)->toBeNull()
        ->and($rouvert->updated_at?->toDateTimeString())->toBe(now()->toDateTimeString())
        ->and($inchange->current_value)->toBe(3.0)
        ->and($inchange->updated_at?->toDateTimeString())->toBe($creation)
        ->and($dAutrui->current_value)->toBe(7.0)
        ->and($dAutrui->progress_pct)->toBe(70.0);
});
