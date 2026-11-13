<?php

declare(strict_types=1);

use App\Enums\GoalPeriod;
use App\Jobs\SyncUserGoals;
use App\Models\Goal;
use App\Models\Set;
use App\Models\User;
use App\Models\Workout;
use App\Models\WorkoutLine;
use App\Services\GoalService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Support\HorlogeQuiPasseMinuit;

use function Pest\Laravel\actingAs;

/*
 * « N séances par semaine » : un objectif de fréquence dont la période est la
 * semaine du calendrier, du lundi 00:00 au dimanche 23:59:59, à l'heure de
 * l'application (Paris). Il compte les séances faites (`User::seancesFaites()`)
 * dont le DÉBUT tombe dans la semaine, et repart de zéro chaque lundi.
 *
 * Les semaines se posent à des dates fixes sous une horloge arrêtée : le
 * 11/11/2026 est un mercredi, le 25/10/2026 le dimanche du passage à l'heure
 * d'hiver, le 29/03/2026 celui du passage à l'heure d'été.
 */

/**
 * Une séance faite (terminée, une série cochée) qui commence à l'instant donné,
 * ou une séance qui ne l'est pas (`$faite` faux : aucune série cochée).
 */
function hebdoSeance(User $compte, string $debut, bool $faite = true): void
{
    $debut = Carbon::parse($debut);

    Workout::withoutEvents(function () use ($compte, $debut, $faite): void {
        $seance = Workout::factory()->create([
            'user_id' => $compte->id,
            'started_at' => $debut,
            'ended_at' => $debut->copy()->addHour(),
        ]);

        Set::factory()->create([
            'workout_line_id' => WorkoutLine::factory()->create(['workout_id' => $seance->id])->id,
            'is_completed' => $faite,
        ]);
    });
}

function hebdoObjectif(User $compte, int $cible = 3, GoalPeriod $periode = GoalPeriod::Week): Goal
{
    return Goal::factory()->create([
        'user_id' => $compte->id,
        'type' => 'frequency',
        'period' => $periode,
        'target_value' => $cible,
        'start_value' => 0,
        'current_value' => 0,
    ]);
}

/**
 * Recalcule le compte par le lot, puis relit le même objectif seul : les deux
 * chemins doivent répondre pareil.
 *
 * @return array{lot: float, seul: float}
 */
function hebdoValeurs(User $compte, Goal $objectif): array
{
    app(GoalService::class)->syncGoals($compte);
    $lot = $objectif->refresh()->current_value;

    $seul = Goal::query()->findOrFail($objectif->id);
    app(GoalService::class)->updateGoalProgress($seul);

    return ['lot' => $lot, 'seul' => $seul->current_value];
}

afterEach(fn () => Carbon::setTestNow());

it('compte les séances faites du lundi au dimanche, et seulement celles-là', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-11-11 12:00:00'));
    $compte = User::factory()->create();
    $objectif = hebdoObjectif($compte);

    hebdoSeance($compte, '2026-11-08 23:30:00'); // dimanche d'avant : hors semaine
    hebdoSeance($compte, '2026-11-09 00:00:00'); // lundi à minuit : dedans
    hebdoSeance($compte, '2026-11-12 18:00:00'); // jeudi, après maintenant : dedans
    hebdoSeance($compte, '2026-11-15 23:59:59'); // dimanche : dedans
    hebdoSeance($compte, '2026-11-16 00:00:00'); // lundi suivant : hors semaine
    hebdoSeance($compte, '2026-11-10 08:00:00', faite: false); // aucune série cochée

    expect(hebdoValeurs($compte, $objectif))->toBe(['lot' => 3.0, 'seul' => 3.0]);
});

it('ne compte que les séances du compte', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-11-11 12:00:00'));
    $compte = User::factory()->create();
    $objectif = hebdoObjectif($compte);

    hebdoSeance($compte, '2026-11-10 08:00:00');
    hebdoSeance(User::factory()->create(), '2026-11-10 09:00:00');

    expect(hebdoValeurs($compte, $objectif))->toBe(['lot' => 1.0, 'seul' => 1.0]);
});

it('juge le dimanche 23:30 et le lundi 00:30 à leur propre semaine', function (): void {
    $compte = User::factory()->create();
    $objectif = hebdoObjectif($compte, cible: 1);
    hebdoSeance($compte, '2026-11-15 22:00:00'); // dimanche soir

    Carbon::setTestNow(Carbon::parse('2026-11-15 23:30:00'));
    expect(hebdoValeurs($compte, $objectif))->toBe(['lot' => 1.0, 'seul' => 1.0])
        ->and($objectif->refresh()->completed_at)->not->toBeNull();

    Carbon::setTestNow(Carbon::parse('2026-11-16 00:30:00'));
    expect(hebdoValeurs($compte, $objectif))->toBe(['lot' => 0.0, 'seul' => 0.0])
        ->and($objectif->refresh()->completed_at)->toBeNull()
        ->and($objectif->progress_pct)->toBe(0.0);
});

it('garde la semaine de 169 heures du passage à l\'heure d\'hiver entière', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-10-25 12:00:00'));
    $compte = User::factory()->create();
    $objectif = hebdoObjectif($compte, cible: 5);

    hebdoSeance($compte, '2026-10-18 23:59:59'); // semaine d'avant
    hebdoSeance($compte, '2026-10-19 00:00:00');
    hebdoSeance($compte, '2026-10-25 23:59:59'); // le dernier instant, après l'heure répétée
    hebdoSeance($compte, '2026-10-26 00:00:00'); // semaine suivante

    expect(hebdoValeurs($compte, $objectif))->toBe(['lot' => 2.0, 'seul' => 2.0]);
});

it('garde la semaine de 167 heures du passage à l\'heure d\'été entière', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-03-29 12:00:00'));
    $compte = User::factory()->create();
    $objectif = hebdoObjectif($compte, cible: 5);

    hebdoSeance($compte, '2026-03-22 23:59:59');
    hebdoSeance($compte, '2026-03-23 00:00:00');
    hebdoSeance($compte, '2026-03-29 23:59:59');
    hebdoSeance($compte, '2026-03-30 00:00:00');

    expect(hebdoValeurs($compte, $objectif))->toBe(['lot' => 2.0, 'seul' => 2.0]);
});

it('remplit la barre à 100 % sans la dépasser, et marque l\'objectif atteint', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-11-11 12:00:00'));
    $compte = User::factory()->create();
    $objectif = hebdoObjectif($compte, cible: 2);

    hebdoSeance($compte, '2026-11-09 08:00:00');
    app(GoalService::class)->syncGoals($compte);
    expect($objectif->refresh()->progress_pct)->toBe(50.0)
        ->and($objectif->completed_at)->toBeNull();

    hebdoSeance($compte, '2026-11-10 08:00:00');
    hebdoSeance($compte, '2026-11-11 08:00:00');
    app(GoalService::class)->syncGoals($compte);

    expect($objectif->refresh()->progress_pct)->toBe(100.0)
        ->and($objectif->current_value)->toBe(3.0)
        ->and($objectif->completed_at?->toIso8601String())->toBe(now()->toIso8601String());
});

it('lit zéro comme départ, quoi que le champ porte', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-11-11 12:00:00'));
    $compte = User::factory()->create();
    $objectif = hebdoObjectif($compte, cible: 4);
    $objectif->forceFill(['start_value' => 2.0])->save();

    hebdoSeance($compte, '2026-11-10 08:00:00');
    app(GoalService::class)->syncGoals($compte);

    // Un départ de 2 aurait rendu 0 % : une séance sur quatre fait 25 %.
    expect($objectif->refresh()->progress_pct)->toBe(25.0);
});

it('rouvre l\'objectif quand la semaine tourne, et le referme sur une séance du lundi', function (): void {
    $compte = User::factory()->create();
    $objectif = hebdoObjectif($compte, cible: 1);
    hebdoSeance($compte, '2026-11-10 08:00:00');

    Carbon::setTestNow(Carbon::parse('2026-11-13 12:00:00'));
    app(GoalService::class)->syncGoals($compte);
    expect($objectif->refresh()->completed_at)->not->toBeNull();

    // Le lundi, avant que rien n'ait rouvert l'objectif, une séance rend le
    // critère rempli : la date d'atteinte de la semaine passée ne survit pas.
    Carbon::setTestNow(Carbon::parse('2026-11-16 09:00:00'));
    hebdoSeance($compte, '2026-11-16 08:00:00');
    app(GoalService::class)->syncGoals($compte);

    expect($objectif->refresh()->completed_at?->toIso8601String())->toBe('2026-11-16T09:00:00+01:00');
});

it('lit l\'horloge une seule fois : minuit qui passe ne change ni la semaine ni la date d\'atteinte', function (): void {
    $compte = User::factory()->create();
    $objectif = hebdoObjectif($compte, cible: 1);
    hebdoSeance($compte, '2026-11-15 22:00:00');

    HorlogeQuiPasseMinuit::apres(1, '2026-11-15 23:59:59', '2026-11-16 00:00:01');
    app(GoalService::class)->syncGoals($compte);

    expect($objectif->refresh()->current_value)->toBe(1.0)
        ->and($objectif->completed_at?->toIso8601String())->toBe('2026-11-15T23:59:59+01:00');
});

it('laisse un objectif « au total » compter toutes les séances', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-11-11 12:00:00'));
    $compte = User::factory()->create();
    $objectif = hebdoObjectif($compte, cible: 10, periode: GoalPeriod::Total);

    hebdoSeance($compte, '2026-09-01 08:00:00');
    hebdoSeance($compte, '2026-11-10 08:00:00');

    expect(hebdoValeurs($compte, $objectif))->toBe(['lot' => 2.0, 'seul' => 2.0]);
});

it('compte chaque objectif sur sa période quand un compte porte les deux', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-11-11 12:00:00'));
    $compte = User::factory()->create();
    $semaine = hebdoObjectif($compte, cible: 3);
    $total = hebdoObjectif($compte, cible: 10, periode: GoalPeriod::Total);

    hebdoSeance($compte, '2026-09-01 08:00:00');
    hebdoSeance($compte, '2026-11-10 08:00:00');
    app(GoalService::class)->syncGoals($compte);

    expect($semaine->refresh()->current_value)->toBe(1.0)
        ->and($total->refresh()->current_value)->toBe(2.0);
});

it('ne prend la période que d\'un objectif de fréquence', function (): void {
    $objectif = Goal::factory()->create(['type' => 'weight', 'period' => 'week']);

    expect($objectif->estHebdomadaire())->toBeFalse()
        ->and(hebdoObjectif(User::factory()->create())->estHebdomadaire())->toBeTrue()
        ->and(new Goal()->period)->toBe(GoalPeriod::Total);
});

describe('la création et la modification', function (): void {
    /**
     * @param  array<string, mixed>  $surcharge
     * @return array<string, mixed>
     */
    function hebdoCorps(array $surcharge = []): array
    {
        return [
            'title' => '3 séances par semaine',
            'type' => 'frequency',
            'period' => 'week',
            'target_value' => 3,
            'start_value' => '',
            ...$surcharge,
        ];
    }

    it('crée l\'objectif avec sa période et un départ à zéro', function (): void {
        Carbon::setTestNow(Carbon::parse('2026-11-11 12:00:00'));
        $compte = User::factory()->create();
        hebdoSeance($compte, '2026-11-10 08:00:00');

        actingAs($compte)->post(route('goals.store'), hebdoCorps(['start_value' => 5]))
            ->assertRedirect(route('goals.index'));

        $objectif = $compte->goals()->firstOrFail();
        expect($objectif->period)->toBe(GoalPeriod::Week)
            ->and($objectif->start_value)->toBe(0.0)
            ->and($objectif->current_value)->toBe(1.0);
    });

    it('prend « au total » quand la période est absente', function (): void {
        $compte = User::factory()->create();
        $corps = hebdoCorps();
        unset($corps['period']);

        actingAs($compte)->post(route('goals.store'), $corps)->assertRedirect();

        expect($compte->goals()->firstOrFail()->period)->toBe(GoalPeriod::Total);
    });

    it('refuse une période inconnue', function (): void {
        actingAs(User::factory()->create())
            ->post(route('goals.store'), hebdoCorps(['period' => 'month']))
            ->assertSessionHasErrors('period');
    });

    it('exige un nombre entier de séances, d\'une à quatorze', function (mixed $cible, bool $accepte): void {
        $reponse = actingAs(User::factory()->create())
            ->post(route('goals.store'), hebdoCorps(['target_value' => $cible]));

        $accepte ? $reponse->assertSessionHasNoErrors() : $reponse->assertSessionHasErrors('target_value');
    })->with([
        'zéro' => [0, false],
        'une demi-séance' => [1.5, false],
        'une' => [1, true],
        'quatorze' => [14, true],
        'quinze' => [15, false],
    ]);

    it('laisse un objectif « au total » prendre une cible décimale', function (): void {
        actingAs(User::factory()->create())
            ->post(route('goals.store'), hebdoCorps(['period' => 'total', 'target_value' => 100.5]))
            ->assertSessionHasNoErrors();
    });

    it('ramène à « au total » la période d\'un objectif qui n\'est pas de fréquence', function (): void {
        $compte = User::factory()->create();
        $exercice = App\Models\Exercise::factory()->create();

        actingAs($compte)->post(route('goals.store'), [
            'title' => 'Développé 100 kg',
            'type' => 'weight',
            'period' => 'week',
            'target_value' => 100,
            'exercise_id' => $exercice->id,
        ])->assertSessionHasNoErrors();

        expect($compte->goals()->firstOrFail()->period)->toBe(GoalPeriod::Total);
    });

    it('garde la période quand la modification ne l\'envoie pas, et la ramène quand le type change', function (): void {
        $compte = User::factory()->create();
        $objectif = hebdoObjectif($compte);
        $exercice = App\Models\Exercise::factory()->create();

        $corps = hebdoCorps(['target_value' => 4]);
        unset($corps['period']);
        actingAs($compte)->put(route('goals.update', $objectif), $corps)->assertSessionHasNoErrors();
        expect($objectif->refresh()->period)->toBe(GoalPeriod::Week)
            ->and($objectif->target_value)->toBe(4.0);

        actingAs($compte)->put(route('goals.update', $objectif), [
            'title' => 'Développé 100 kg',
            'type' => 'weight',
            'period' => 'week',
            'target_value' => 100,
            'exercise_id' => $exercice->id,
        ])->assertSessionHasNoErrors();
        expect($objectif->refresh()->period)->toBe(GoalPeriod::Total);
    });

    it('passe la période à la page de modification et à la liste', function (): void {
        $compte = User::factory()->create();
        $objectif = hebdoObjectif($compte);

        actingAs($compte)->get(route('goals.edit', $objectif))
            ->assertInertia(fn ($page) => $page->where('goal.period', 'week'));
        actingAs($compte)->get(route('goals.index'))
            ->assertInertia(fn ($page) => $page->where('goals.0.period', 'week'));
    });
});

describe('le renouvellement quotidien', function (): void {
    it('relance le recalcul des seuls comptes qui gardent la semaine passée', function (): void {
        Queue::fake();
        Carbon::setTestNow(Carbon::parse('2026-11-11 12:00:00'));
        $avant = Carbon::parse('2026-11-08 10:00:00');
        $garde = User::factory()->create();
        $avance = User::factory()->create();
        $atteint = User::factory()->create();
        $vide = User::factory()->create();
        $total = User::factory()->create();
        $recalcule = User::factory()->create();
        $autreCasse = User::factory()->create();

        Goal::factory()->create(['user_id' => $garde->id, 'type' => 'frequency', 'period' => 'week', 'current_value' => 2, 'updated_at' => $avant]);
        Goal::factory()->create(['user_id' => $avance->id, 'type' => 'frequency', 'period' => 'week', 'current_value' => 1, 'updated_at' => $avant]);
        Goal::factory()->create(['user_id' => $avance->id, 'type' => 'frequency', 'period' => 'week', 'current_value' => 4, 'updated_at' => $avant]);
        Goal::factory()->completed()->create(['user_id' => $atteint->id, 'type' => 'frequency', 'period' => 'week', 'current_value' => 0, 'target_value' => 0, 'updated_at' => $avant]);
        Goal::factory()->create(['user_id' => $vide->id, 'type' => 'frequency', 'period' => 'week', 'current_value' => 0, 'completed_at' => null, 'updated_at' => $avant]);
        Goal::factory()->create(['user_id' => $total->id, 'type' => 'frequency', 'period' => 'total', 'current_value' => 7, 'updated_at' => $avant]);
        // Déjà recalculé depuis le lundi : rien à rouvrir.
        Goal::factory()->create(['user_id' => $recalcule->id, 'type' => 'frequency', 'period' => 'week', 'current_value' => 2, 'updated_at' => Carbon::parse('2026-11-10 08:00:00')]);
        // Une période écrite autrement que le cast ne la lit pas n'est pas « week ».
        $casse = Goal::factory()->create(['user_id' => $autreCasse->id, 'type' => 'frequency', 'period' => 'week', 'current_value' => 2, 'updated_at' => $avant]);
        DB::table('goals')->where('id', $casse->id)->update(['period' => 'WEEK ']);

        expect(Artisan::call('objectifs:renouveler-la-semaine'))->toBe(0);

        Queue::assertPushed(SyncUserGoals::class, 3);
        Queue::assertPushed(SyncUserGoals::class, fn (SyncUserGoals $job): bool => $job->user->is($garde));
        Queue::assertPushed(SyncUserGoals::class, fn (SyncUserGoals $job): bool => $job->user->is($avance));
        Queue::assertPushed(SyncUserGoals::class, fn (SyncUserGoals $job): bool => $job->user->is($atteint));
    });

    it('est planifiée chaque jour à 00:20, pour rattraper un lundi manqué', function (): void {
        $evenement = collect(app(Illuminate\Console\Scheduling\Schedule::class)->events())
            ->first(fn ($event): bool => str_contains((string) $event->command, 'objectifs:renouveler-la-semaine'));

        expect($evenement?->expression)->toBe('20 0 * * *');
    });
});
