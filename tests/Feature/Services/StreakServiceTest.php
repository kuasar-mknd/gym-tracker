<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Workout;
use App\Services\StreakService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

covers(StreakService::class);

/*
 * La série de jours n'a plus qu'un écrivain, la reconstruction depuis les
 * séances faites (#2171). Ces cas passaient par l'avance d'un cran
 * (`updateStreak()`), retirée : chacun pose désormais des séances faites,
 * terminées avec une série cochée, que les écouteurs des séries comptent en
 * reconstruisant la série, et affirme ce qu'il affirmait.
 */

beforeEach(function (): void {
    $this->streakService = app(StreakService::class);
    // 🧪 Testing Pattern/Time: Use setTestNow for deterministic streak tests
    Carbon::setTestNow(Carbon::parse('2025-03-25 12:00:00'));
});

afterEach(function (): void {
    Carbon::setTestNow();
});

/**
 * Une séance faite qui commence à cet instant, écrite par les modèles : sa
 * série cochée, écrite après sa fin, reconstruit la série du compte.
 */
function streakFaiteLe(User $user, Carbon $debut): Workout
{
    return Workout::factory()->faite()->create(['user_id' => $user->id, 'started_at' => $debut]);
}

/**
 * Les écritures de `users` émises pendant l'action.
 *
 * @return list<string>
 */
function streakEcrituresDuComptePendant(callable $action): array
{
    $ecritures = [];

    DB::listen(function (QueryExecuted $requete) use (&$ecritures): void {
        if (str_starts_with(Str::lower($requete->sql), 'update `users`')) {
            $ecritures[] = $requete->sql;
        }
    });

    $action();

    DB::getEventDispatcher()->forget(QueryExecuted::class);

    return $ecritures;
}

it('initializes streak to 1 on the first workout', function (): void {
    $user = User::factory()->create();

    $workout = streakFaiteLe($user, Carbon::now()->subDays(1));

    $user->refresh();

    expect($user->current_streak)->toBe(1)
        ->and($user->longest_streak)->toBe(1)
        ->and(Carbon::parse($user->last_workout_at)->startOfDay()->equalTo(Carbon::parse($workout->started_at)->startOfDay()))->toBeTrue();
});

it('increments streak on consecutive workouts', function (): void {
    $user = User::factory()->create();

    streakFaiteLe($user, Carbon::now()->subDays(1));
    $workout = streakFaiteLe($user, Carbon::now());

    $user->refresh();

    expect($user->current_streak)->toBe(2)
        ->and($user->longest_streak)->toBe(2)
        ->and(Carbon::parse($user->last_workout_at)->startOfDay()->equalTo(Carbon::parse($workout->started_at)->startOfDay()))->toBeTrue();
});

it('resets streak if more than one day passes', function (): void {
    $user = User::factory()->create();

    foreach ([7, 6, 5, 4, 3] as $recul) {
        streakFaiteLe($user, Carbon::now()->subDays($recul));
    }

    $workout = streakFaiteLe($user, Carbon::now());

    $user->refresh();

    expect($user->current_streak)->toBe(1)
        ->and($user->longest_streak)->toBe(5)
        ->and(Carbon::parse($user->last_workout_at)->startOfDay()->equalTo(Carbon::parse($workout->started_at)->startOfDay()))->toBeTrue();
});

it('does not increment streak on same day workouts', function (): void {
    $user = User::factory()->create();

    foreach ([2, 1, 0] as $recul) {
        streakFaiteLe($user, Carbon::now()->subDays($recul));
    }

    $workout = streakFaiteLe($user, Carbon::now()->addHours(2));

    $user->refresh();

    expect($user->current_streak)->toBe(3)
        ->and($user->longest_streak)->toBe(3)
        ->and(Carbon::parse($user->last_workout_at)->startOfDay()->equalTo(Carbon::parse($workout->started_at)->startOfDay()))->toBeTrue();
});

it('updates longest streak if current streak surpasses it', function (): void {
    $user = User::factory()->create();

    // Une ancienne suite de cinq jours, puis une nouvelle qui la dépasse.
    foreach ([20, 19, 18, 17, 16] as $recul) {
        streakFaiteLe($user, Carbon::now()->subDays($recul));
    }

    foreach ([5, 4, 3, 2, 1] as $recul) {
        streakFaiteLe($user, Carbon::now()->subDays($recul));
    }

    expect($user->refresh()->current_streak)->toBe(5)
        ->and($user->longest_streak)->toBe(5);

    streakFaiteLe($user, Carbon::now());

    $user->refresh();

    expect($user->current_streak)->toBe(6)
        ->and($user->longest_streak)->toBe(6);
});

/**
 * La série se lit dans les séances faites, jamais dans les colonnes stockées :
 * une reconstruction ne part pas de la valeur qu'elle remplace.
 */
it('reconstruit la série depuis les séances faites, sans partir des colonnes stockées', function (): void {
    $user = User::factory()->create([
        'current_streak' => 1,
        'longest_streak' => 1,
        'last_workout_at' => Carbon::now()->subDays(1),
    ]);

    $workout = Workout::withoutEvents(function () use ($user): Workout {
        streakFaiteLe($user, Carbon::now()->subDays(1));

        return streakFaiteLe($user, Carbon::now());
    });

    $this->streakService->recalculerDepuisLesFaits($user);

    $user->refresh();

    expect($user->current_streak)->toBe(2)
        ->and($user->longest_streak)->toBe(2)
        ->and(Carbon::parse($user->last_workout_at)->startOfDay()->equalTo(Carbon::parse($workout->started_at)->startOfDay()))->toBeTrue();
});

/**
 * Une seconde séance faite le même jour repousse l'heure, et l'écrit.
 *
 * Comparer `last_workout_at` au jour près ne verrait pas si l'heure a bougé,
 * ni même si quoi que ce soit a été enregistré. La date garde la séance faite
 * la PLUS RÉCENTE de la journée.
 */
it('repousse l’heure de la dernière séance quand une seconde arrive le même jour', function (): void {
    $matin = Carbon::now()->startOfDay()->addHours(9);
    $soir = Carbon::now()->startOfDay()->addHours(19);

    $user = User::factory()->create();

    streakFaiteLe($user, $matin);
    streakFaiteLe($user, $soir);

    // Relu depuis la base : c'est l'écriture qui est en cause, pas la valeur
    // portée par l'objet en mémoire.
    $enBase = User::findOrFail($user->id);

    expect(Carbon::parse($enBase->last_workout_at)->equalTo($soir))->toBeTrue()
        ->and($enBase->current_streak)->toBe(1);
});

/**
 * `last_workout_at` ne recule pas quand on saisit une séance oubliée.
 *
 * C'est la seule mémoire de la péremption de la série
 * (`currentStreakFor()`) : une date reculée annoncerait cassée une série
 * pourtant vivante.
 */
it('ne fait pas reculer la date de dernière séance quand une séance ancienne est saisie', function (): void {
    $user = User::factory()->create();

    $recente = streakFaiteLe($user, Carbon::now()->startOfDay()->addHours(18));

    // La séance oubliée, saisie après coup.
    streakFaiteLe($user, Carbon::now()->subDays(3)->startOfDay()->addHours(9));

    $enBase = User::findOrFail($user->id);

    expect($enBase->last_workout_at)->not->toBeNull()
        ->and($enBase->last_workout_at->toDateTimeString())->toBe($recente->started_at->toDateTimeString());
});

/**
 * Une seconde séance du même jour, mais plus ancienne, ne fait rien reculer,
 * et n'écrit rien : la série reconstruite est celle qui est stockée, et
 * `save()` sur un compte inchangé n'émet aucune requête.
 */
it('ne fait pas reculer l’heure quand une séance plus ancienne du jour est saisie', function (): void {
    $soir = Carbon::now()->startOfDay()->addHours(20);
    $matin = Carbon::now()->startOfDay()->addHours(7);

    $user = User::factory()->create();
    streakFaiteLe($user, $soir);

    $ecritures = streakEcrituresDuComptePendant(fn (): Workout => streakFaiteLe($user, $matin));

    $enBase = User::findOrFail($user->id);

    expect($enBase->last_workout_at)->not->toBeNull()
        ->and($enBase->last_workout_at->toDateTimeString())->toBe($soir->toDateTimeString())
        ->and($ecritures)->toBe([]);
});

/**
 * Une reconstruction qui retrouve la série stockée ne touche à rien.
 */
it('n’écrit rien quand la reconstruction retrouve la série stockée', function (): void {
    $user = User::factory()->create();

    streakFaiteLe($user, Carbon::now()->startOfDay()->addHours(12));

    $avant = User::findOrFail($user->id);

    $ecritures = streakEcrituresDuComptePendant(fn () => $this->streakService->recalculerDepuisLesFaits($user->refresh()));

    $apres = User::findOrFail($user->id);

    expect($apres->last_workout_at)->not->toBeNull()
        ->and($apres->last_workout_at->toDateTimeString())->toBe($avant->last_workout_at?->toDateTimeString())
        ->and($apres->current_streak)->toBe($avant->current_streak)
        ->and($ecritures)->toBe([]);
});

/**
 * Une séance antérieure ne casse pas la série en cours. Une séance vieille de
 * dix jours ne prolonge rien et ne casse rien : la suite qui se termine
 * aujourd'hui vaut toujours quatre.
 */
it('ne casse pas la série en cours quand une séance antérieure est saisie', function (): void {
    $user = User::factory()->create();

    foreach ([3, 2, 1, 0] as $recul) {
        streakFaiteLe($user, Carbon::now()->subDays($recul)->setTime(10, 0));
    }

    expect(User::findOrFail($user->id)->current_streak)->toBe(4);

    streakFaiteLe($user, Carbon::now()->subDays(10)->setTime(10, 0));

    $enBase = User::findOrFail($user->id);

    expect($enBase->current_streak)->toBe(4)
        ->and($enBase->longest_streak)->toBe(4);
});

/**
 * La série se reconstruit sur le compte relu, pas sur celui qu'une séance
 * garde depuis son chargement.
 *
 * La séance d'aujourd'hui a chargé son compte quand la série valait un jour ;
 * celle d'hier l'a portée à deux, par une autre instance du compte, puis a
 * disparu sans écouteur. Reconstruite sur le compte que la séance gardait, la
 * série d'un jour ne différait de rien et ne s'écrivait pas : la base gardait
 * deux jours.
 */
it('reconstruit la série sur le compte relu, pas sur celui que la séance gardait', function (): void {
    $user = User::factory()->create();

    $aujourdHui = streakFaiteLe($user, Carbon::now()->setTime(10, 0));
    $aujourdHui->load('user');

    $hier = streakFaiteLe($user, Carbon::now()->subDay()->setTime(10, 0));
    expect(User::findOrFail($user->id)->current_streak)->toBe(2);

    DB::table('workouts')->where('id', $hier->id)->delete();

    $this->streakService->recalculerPourLaSeance($aujourdHui);

    $enBase = User::findOrFail($user->id);

    expect([$enBase->last_workout_at?->toDateTimeString(), $enBase->current_streak, $enBase->longest_streak])
        ->toBe([$aujourdHui->started_at->toDateTimeString(), 1, 1]);
});

/**
 * Un compte sans aucune séance faite a une série nulle : ni séance, ni séance
 * vide ou en cours.
 */
it('ne fait rien pour un compte sans aucune séance', function (): void {
    $user = User::factory()->create();

    $this->streakService->recalculerDepuisLesFaits($user);

    Workout::factory()->create(['user_id' => $user->id, 'started_at' => Carbon::now()->subDay(), 'ended_at' => Carbon::now()->subDay()->addHour()]);
    Workout::factory()->create(['user_id' => $user->id, 'started_at' => Carbon::now(), 'ended_at' => null]);

    $this->streakService->recalculerDepuisLesFaits($user->refresh());

    $enBase = User::findOrFail($user->id);

    expect($enBase->current_streak)->toBe(0)
        ->and($enBase->longest_streak)->toBe(0)
        ->and($enBase->last_workout_at)->toBeNull();
});

/**
 * Une séance dont le compte a disparu ne fait rien reconstruire : la
 * suppression d'un compte emporte ses séances en base, et une instance gardée
 * en mémoire ne trouve plus personne.
 */
it('ne reconstruit rien pour une séance dont le compte a disparu', function (): void {
    $user = User::factory()->create();
    $seance = streakFaiteLe($user, Carbon::now());

    DB::table('users')->where('id', $user->id)->delete();

    $ecritures = streakEcrituresDuComptePendant(fn () => $this->streakService->recalculerPourLaSeance($seance));

    expect($ecritures)->toBe([])
        ->and($seance->relationLoaded('user'))->toBeTrue()
        ->and($seance->user)->toBeNull();
});

/**
 * La date de la dernière séance faite est la plus récente du jour, même
 * quand une reconstruction repart des séances.
 */
it('garde l’heure de la dernière séance faite du jour à la reconstruction', function (): void {
    $leSoir = Carbon::now()->startOfDay()->addHours(21);
    $leMatin = Carbon::now()->startOfDay()->addHours(6);

    $user = User::factory()->create();

    streakFaiteLe($user, $leSoir);
    streakFaiteLe($user, $leMatin);

    expect($user->refresh()->last_workout_at?->toDateTimeString())->toBe($leSoir->toDateTimeString());

    $this->streakService->recalculerDepuisLesFaits($user->refresh());

    expect($user->refresh()->last_workout_at?->toDateTimeString())->toBe($leSoir->toDateTimeString())
        ->and($user->current_streak)->toBe(1);
});
