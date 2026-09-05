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

beforeEach(function (): void {
    $this->streakService = app(StreakService::class);
    // 🧪 Testing Pattern/Time: Use setTestNow for deterministic streak tests
    Carbon::setTestNow(Carbon::parse('2025-03-25 12:00:00'));
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('initializes streak to 1 on the first workout', function (): void {
    $user = User::factory()->create([
        'current_streak' => 0,
        'longest_streak' => 0,
        'last_workout_at' => null,
    ]);

    $workout = Workout::factory()->create([
        'user_id' => $user->id,
        'started_at' => Carbon::now()->subDays(1),
    ]);

    $this->streakService->updateStreak($user, $workout);

    $user->refresh();

    expect($user->current_streak)->toBe(1)
        ->and($user->longest_streak)->toBe(1)
        ->and(Carbon::parse($user->last_workout_at)->startOfDay()->equalTo(Carbon::parse($workout->started_at)->startOfDay()))->toBeTrue();
});

it('increments streak on consecutive workouts', function (): void {
    $user = User::factory()->create([
        'current_streak' => 1,
        'longest_streak' => 1,
        'last_workout_at' => Carbon::now()->subDays(1),
    ]);

    $workout = Workout::factory()->create([
        'user_id' => $user->id,
        'started_at' => Carbon::now(),
    ]);

    $this->streakService->updateStreak($user, $workout);

    $user->refresh();

    expect($user->current_streak)->toBe(2)
        ->and($user->longest_streak)->toBe(2)
        ->and(Carbon::parse($user->last_workout_at)->startOfDay()->equalTo(Carbon::parse($workout->started_at)->startOfDay()))->toBeTrue();
});

it('resets streak if more than one day passes', function (): void {
    $user = User::factory()->create([
        'current_streak' => 5,
        'longest_streak' => 5,
        'last_workout_at' => Carbon::now()->subDays(3),
    ]);

    $workout = Workout::factory()->create([
        'user_id' => $user->id,
        'started_at' => Carbon::now(),
    ]);

    $this->streakService->updateStreak($user, $workout);

    $user->refresh();

    expect($user->current_streak)->toBe(1)
        ->and($user->longest_streak)->toBe(5)
        ->and(Carbon::parse($user->last_workout_at)->startOfDay()->equalTo(Carbon::parse($workout->started_at)->startOfDay()))->toBeTrue();
});

it('does not increment streak on same day workouts', function (): void {
    $user = User::factory()->create([
        'current_streak' => 3,
        'longest_streak' => 3,
        'last_workout_at' => Carbon::now(),
    ]);

    $workout = Workout::factory()->create([
        'user_id' => $user->id,
        'started_at' => Carbon::now()->addHours(2),
    ]);

    $this->streakService->updateStreak($user, $workout);

    $user->refresh();

    expect($user->current_streak)->toBe(3)
        ->and($user->longest_streak)->toBe(3)
        ->and(Carbon::parse($user->last_workout_at)->startOfDay()->equalTo(Carbon::parse($workout->started_at)->startOfDay()))->toBeTrue();
});

it('updates longest streak if current streak surpasses it', function (): void {
    $user = User::factory()->create([
        'current_streak' => 5,
        'longest_streak' => 5,
        'last_workout_at' => Carbon::now()->subDays(1),
    ]);

    $workout = Workout::factory()->create([
        'user_id' => $user->id,
        'started_at' => Carbon::now(),
    ]);

    $this->streakService->updateStreak($user, $workout);

    $user->refresh();

    expect($user->current_streak)->toBe(6)
        ->and($user->longest_streak)->toBe(6);
});

it('updates streak correctly without passing workout parameter', function (): void {
    $user = User::factory()->create([
        'current_streak' => 1,
        'longest_streak' => 1,
        'last_workout_at' => Carbon::now()->subDays(1),
    ]);

    $workout = Workout::factory()->create([
        'user_id' => $user->id,
        'started_at' => Carbon::now(),
    ]);

    $this->streakService->updateStreak($user);

    $user->refresh();

    expect($user->current_streak)->toBe(2)
        ->and($user->longest_streak)->toBe(2)
        ->and(Carbon::parse($user->last_workout_at)->startOfDay()->equalTo(Carbon::parse($workout->started_at)->startOfDay()))->toBeTrue();
});

/**
 * Une seconde séance le même jour repousse l'heure, et l'écrit.
 *
 * Le test voisin compare `last_workout_at` au jour près (`startOfDay`) : il ne
 * peut donc pas voir si l'heure a bougé, ni même si quoi que ce soit a été
 * enregistré. Retirer purement et simplement le `$user->save()` de cette
 * branche le laissait vert — l'ancienne valeur, du même jour, satisfaisait
 * l'assertion.
 *
 * Ce que la branche fait vraiment : garder la séance la PLUS RÉCENTE de la
 * journée. C'est ce que lit la bannière « séance en cours » et ce qui décide du
 * rappel quotidien, donc l'heure compte, pas seulement la date.
 */
it('repousse l’heure de la dernière séance quand une seconde arrive le même jour', function (): void {
    $matin = Carbon::now()->startOfDay()->addHours(9);
    $soir = Carbon::now()->startOfDay()->addHours(19);

    $user = User::factory()->create([
        'current_streak' => 1,
        'longest_streak' => 1,
        'last_workout_at' => $matin,
    ]);

    $seconde = Workout::factory()->create([
        'user_id' => $user->id,
        'started_at' => $soir,
    ]);

    $this->streakService->updateStreak($user, $seconde);

    // Relu depuis la base : c'est l'écriture qui est en cause, pas la valeur
    // portée par l'objet en mémoire.
    $enBase = User::findOrFail($user->id);

    expect(Carbon::parse($enBase->last_workout_at)->equalTo($soir))->toBeTrue()
        ->and($enBase->current_streak)->toBe(1);
});

/**
 * `last_workout_at` ne recule pas quand on saisit une séance oubliée.
 *
 * La branche « même jour » protège déjà l'heure : elle n'écrit que si la nouvelle
 * séance est postérieure. La branche « autre jour », elle, écrasait sans
 * condition — une séance vieille de trois jours, saisie après coup, remplaçait la
 * date de la plus récente.
 *
 * La conséquence n'est pas cosmétique : `last_workout_at` est la seule mémoire
 * du service. Une fois reculé, l'écart calculé à la séance suivante part de la
 * mauvaise date, et une série pourtant continue se retrouve cassée.
 */
it('ne fait pas reculer la date de dernière séance quand une séance ancienne est saisie', function (): void {
    $user = User::factory()->create([
        'current_streak' => 1,
        'longest_streak' => 1,
        'last_workout_at' => null,
    ]);

    $recente = Workout::factory()->create([
        'user_id' => $user->id,
        'started_at' => Carbon::now()->startOfDay()->addHours(18),
    ]);

    // La séance oubliée, saisie après coup.
    Workout::factory()->create([
        'user_id' => $user->id,
        'started_at' => Carbon::now()->subDays(3)->startOfDay()->addHours(9),
    ]);

    $enBase = User::findOrFail($user->id);

    expect($enBase->last_workout_at)->not->toBeNull()
        ->and($enBase->last_workout_at->toDateTimeString())->toBe($recente->started_at->toDateTimeString());
});

/**
 * Une seconde séance du même jour, mais plus ancienne, ne fait rien reculer.
 *
 * C'est le pendant du test qui repousse l'heure : `! $user->last_workout_at`
 * garde l'écriture pour le seul cas où il n'y a rien à conserver. Retirer la
 * négation rendait la condition toujours vraie — l'heure reculait à chaque
 * séance saisie plus tôt dans la journée.
 */
it('ne fait pas reculer l’heure quand une séance plus ancienne du jour est saisie', function (): void {
    $soir = Carbon::now()->startOfDay()->addHours(20);
    $matin = Carbon::now()->startOfDay()->addHours(7);

    $user = User::factory()->create([
        'current_streak' => 1,
        'longest_streak' => 1,
        'last_workout_at' => $soir,
    ]);

    $matinale = Workout::factory()->create([
        'user_id' => $user->id,
        'started_at' => $matin,
    ]);

    // La branche appelle `save()` sans garde, en s'appuyant sur le fait qu'un
    // modele inchange n'emet aucune requete. C'est une premisse, pas une
    // evidence : elle est verifiee ici plutot que laissee implicite.
    $ecritures = [];

    DB::listen(function (QueryExecuted $requete) use (&$ecritures): void {
        if (str_starts_with(Str::lower($requete->sql), 'update `users`')) {
            $ecritures[] = $requete->sql;
        }
    });

    $this->streakService->updateStreak($user, $matinale);

    $enBase = User::findOrFail($user->id);

    expect($enBase->last_workout_at)->not->toBeNull()
        ->and($enBase->last_workout_at->toDateTimeString())->toBe($soir->toDateTimeString())
        ->and($ecritures)->toBe([]);
});

/**
 * Un recalcul sans séance, le même jour, ne touche à rien.
 *
 * Le `&&` de cette condition est ce qui empêche de lire `$workout->started_at`
 * quand il n'y a pas de séance. Le transformer en `||` faisait entrer dans la
 * branche avec `$workout` à null, et la lecture échouait — sur un chemin que
 * seul un appel sans séance emprunte, et qu'aucun test ne prenait le même jour.
 */
it('ne touche à rien quand on recalcule sans séance le même jour', function (): void {
    $user = User::factory()->create([
        'current_streak' => 1,
        'longest_streak' => 1,
        'last_workout_at' => null,
    ]);

    Workout::factory()->create([
        'user_id' => $user->id,
        'started_at' => Carbon::now()->startOfDay()->addHours(12),
    ]);

    $avant = User::findOrFail($user->id);

    $this->streakService->updateStreak($user->refresh());

    $apres = User::findOrFail($user->id);

    expect($apres->last_workout_at)->not->toBeNull()
        ->and($apres->last_workout_at->toDateTimeString())->toBe($avant->last_workout_at?->toDateTimeString())
        ->and($apres->current_streak)->toBe($avant->current_streak);
});

/**
 * Une séance antérieure ne casse pas la série en cours.
 *
 * La série de quatre est ici FAITE, et non posée sur les colonnes : un compteur
 * qu'aucun historique ne soutient n'est pas un état que l'application peut
 * atteindre, et le test se comparait alors à lui-même. Une séance vieille de
 * dix jours ne prolonge rien et ne casse rien — la suite qui se termine
 * aujourd'hui vaut toujours quatre.
 */
it('ne casse pas la série en cours quand une séance antérieure est saisie', function (): void {
    $user = User::factory()->create();

    foreach ([3, 2, 1, 0] as $recul) {
        $seance = Workout::factory()->create([
            'user_id' => $user->id,
            'started_at' => Carbon::now()->subDays($recul)->setTime(10, 0),
        ]);
        $this->streakService->updateStreak($user->refresh(), $seance);
    }

    expect(User::findOrFail($user->id)->current_streak)->toBe(4);

    $ancienne = Workout::factory()->create([
        'user_id' => $user->id,
        'started_at' => Carbon::now()->subDays(10)->setTime(10, 0),
    ]);

    $this->streakService->updateStreak($user->refresh(), $ancienne);

    $enBase = User::findOrFail($user->id);

    expect($enBase->current_streak)->toBe(4)
        ->and($enBase->longest_streak)->toBe(4);
});

/**
 * La séance traitée est celle qu'on passe, pas la plus récente en base.
 *
 * `resolveWorkoutDate` part de `$workout->started_at` et ne va chercher la plus
 * récente que faute de séance fournie. Le mutant qui supprime la partie gauche du
 * `??` interroge toujours la base — et la différence ne se voit que si la séance
 * traitée n'est PAS la plus récente, c'est-à-dire au moment précis où l'on saisit
 * une séance oubliée.
 *
 * Ici la séance comblante tombe la veille de la dernière enregistrée : elle
 * allonge la série. En prenant la plus récente à la place, l'écart devient trois
 * jours et la série est remise à 1.
 *
 * Les deux séances sont créées sans événement, sinon l'observateur appliquerait
 * lui-même la logique et le test ne contrôlerait plus l'état de départ.
 */
it('traite la séance fournie et non la plus récente en base', function (): void {
    $user = User::factory()->create([
        'current_streak' => 1,
        'longest_streak' => 1,
        'last_workout_at' => Carbon::now()->subDays(3),
    ]);

    Workout::withoutEvents(function () use ($user): void {
        Workout::factory()->create([
            'user_id' => $user->id,
            'started_at' => Carbon::now(),
        ]);
    });

    $comblante = Workout::withoutEvents(fn (): Workout => Workout::factory()->create([
        'user_id' => $user->id,
        'started_at' => Carbon::now()->subDays(2),
    ]));

    $this->streakService->updateStreak($user, $comblante);

    // Trois jours en arrière puis deux : un jour d'écart, la série s'allonge.
    expect(User::findOrFail($user->id)->current_streak)->toBe(2);
});

/**
 * Un compte sans aucune séance ne fait rien planter.
 *
 * C'est le seul cas où `resolveWorkoutDate` rend null : aucune séance fournie, et
 * `value()` qui ne trouve rien. Le `?->` qui suit était le dernier point non
 * couvert de la classe — le retirer transforme ce cas en erreur fatale, sur un
 * chemin qu'un recalcul déclenché avant la première séance emprunte tout à fait
 * normalement.
 */
it('ne fait rien pour un compte sans aucune séance', function (): void {
    $user = User::factory()->create([
        'current_streak' => 0,
        'longest_streak' => 0,
        'last_workout_at' => null,
    ]);

    $this->streakService->updateStreak($user);

    $enBase = User::findOrFail($user->id);

    expect($enBase->current_streak)->toBe(0)
        ->and($enBase->longest_streak)->toBe(0)
        ->and($enBase->last_workout_at)->toBeNull();
});

/**
 * Un recalcul sans séance, le même jour, ne retouche pas la date connue.
 *
 * `last_workout_at` est la seule mémoire du service, et elle ne recule pas :
 * `rememberIfMoreRecent()` le dit pour l'enregistrement d'une séance. Le
 * recalcul sans séance, lui, reprend la date de la plus récente en base telle
 * quelle — c'est légitime quand il y a quelque chose à revoir, mais le même
 * jour il n'y a rien à revoir, et le retour anticipé le dit.
 *
 * Sans lui, un compte dont la journée compte deux séances verrait sa date
 * ramenée à celle du MATIN à chaque recalcul, alors qu'il s'est entraîné le
 * soir.
 */
it('ne ramène pas la date au matin quand on recalcule sans séance le même jour', function (): void {
    $leSoir = Carbon::now()->startOfDay()->addHours(21);
    $leMatin = Carbon::now()->startOfDay()->addHours(6);

    $user = User::factory()->create([
        'current_streak' => 1,
        'longest_streak' => 1,
        'last_workout_at' => $leSoir,
    ]);

    Workout::factory()->create([
        'user_id' => $user->id,
        'started_at' => $leMatin,
    ]);

    expect($user->refresh()->last_workout_at?->toDateTimeString())->toBe($leSoir->toDateTimeString());

    $this->streakService->updateStreak($user->refresh());

    expect($user->refresh()->last_workout_at?->toDateTimeString())->toBe($leSoir->toDateTimeString())
        ->and($user->current_streak)->toBe(1);
});
