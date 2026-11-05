<?php

declare(strict_types=1);

use App\Filament\Resources\Workouts\Pages\EditWorkout;
use App\Models\Exercise;
use App\Models\Set;
use App\Models\User;
use App\Models\Workout;
use App\Notifications\AchievementUnlocked;
use App\Services\StreakService;
use App\Support\HeureDeLAppareil;
use Database\Seeders\AchievementSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia;
use Livewire\Livewire;
use Tests\Support\FilamentAdminPanel;

use function Pest\Laravel\actingAs;

/*
 * La série de jours (`users.current_streak` et `longest_streak`, la série de
 * l'accueil) et le succès de type `streak` (« Série de 3 ») ne comptent qu'un
 * jour qui a au moins une séance FAITE : terminée, avec au moins une série
 * cochée, la définition des succès de séances et des objectifs de fréquence
 * (`User::seancesFaites()`, #2110). Le jour reste celui du début de la séance,
 * dans le fuseau de l'application (#2171).
 *
 * Les deux comptaient toute séance du compte, dès son démarrage : démarrer une
 * séance trois jours de suite, puis la terminer sans rien soulever, débloquait
 * « Série de 3 », notification comprise, et la série de l'accueil avançait au
 * simple clic sur « Démarrer ».
 *
 * Chaque cas passe par les routes de la page (démarrer, ajouter un exercice et
 * une série, terminer, supprimer), par celles de la file hors ligne ou par le
 * panneau, la file étant synchrone sous les tests ; et chacun mêle aux séances
 * faites des séances vides, en cours ou sans série cochée, que l'ancien calcul
 * comptait.
 */

beforeEach(function (): void {
    $this->seed(AchievementSeeder::class);
    Notification::fake();
});

afterEach(function (): void {
    Carbon::setTestNow();
});

/**
 * Arrête l'horloge à cet instant, à Paris.
 */
function serieDeJoursLe(string $instant): void
{
    Carbon::setTestNow(Carbon::parse($instant));
}

/**
 * Démarre une séance par la page, et la rend.
 */
function serieDeJoursDemarrer(User $compte): Workout
{
    actingAs($compte)->post(route('workouts.store'))->assertRedirect();

    return Workout::query()->where('user_id', $compte->id)->latest('id')->firstOrFail();
}

/**
 * Termine la séance par la page.
 */
function serieDeJoursTerminer(User $compte, Workout $seance): void
{
    actingAs($compte)
        ->patch(route('workouts.update', $seance), ['is_finished' => true])
        ->assertRedirect(route('dashboard'));
}

/**
 * Ajoute à la séance un exercice et une série, cochée ou non, par l'API de la
 * page, et rend la série. Soixante kilos pour huit répétitions : aucun seuil de
 * charge ni de volume cumulé du catalogue n'est atteint.
 */
function serieDeJoursAjouterUneSerie(User $compte, Workout $seance, bool $cochee): Set
{
    $exercice = Exercise::factory()->create(['user_id' => $compte->id, 'type' => 'strength']);

    $ligne = actingAs($compte)
        ->postJson(route('api.v1.workout-lines.store'), ['workout_id' => $seance->id, 'exercise_id' => $exercice->id])
        ->assertCreated();

    $serie = actingAs($compte)
        ->postJson(route('api.v1.sets.store'), [
            'workout_line_id' => $ligne->json('data.id'),
            'weight' => 60,
            'reps' => 8,
            'is_completed' => $cochee,
        ])
        ->assertCreated();

    return Set::query()->whereKey($serie->json('data.id'))->firstOrFail();
}

/**
 * Démarre, coche une série et termine : une séance faite.
 */
function serieDeJoursFaireUneSeance(User $compte): Workout
{
    $seance = serieDeJoursDemarrer($compte);
    serieDeJoursAjouterUneSerie($compte, $seance, cochee: true);
    serieDeJoursTerminer($compte, $seance);

    return $seance;
}

/**
 * Démarre puis termine une séance sans rien y ajouter : une séance vide.
 */
function serieDeJoursFaireUneSeanceVide(User $compte): Workout
{
    $seance = serieDeJoursDemarrer($compte);
    serieDeJoursTerminer($compte, $seance);

    return $seance;
}

/**
 * Exige que l'accueil affiche cette série en cours.
 *
 * Le compte est relu : la requête de l'accueil lit le compte que la garde
 * tient, et l'instance du test garde les colonnes de sa création.
 */
function serieDeJoursAfficheeVaut(User $compte, int $attendue): void
{
    actingAs(User::query()->findOrFail($compte->id))
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('Dashboard')
            ->where('auth.user.current_streak', $attendue));
}

/**
 * La série stockée : la dernière séance faite, la série en cours, la plus longue.
 *
 * @return array{0: string|null, 1: int, 2: int}
 */
function serieDeJoursStockee(User $compte): array
{
    $enBase = User::query()->findOrFail($compte->id);

    return [$enBase->last_workout_at?->toDateTimeString(), $enBase->current_streak, $enBase->longest_streak];
}

/**
 * Les succès de type `streak` du compte, par leur nom court.
 *
 * @return list<string>
 */
function serieDeJoursSuccesDeSerie(User $compte): array
{
    /** @var list<string> $slugs */
    $slugs = $compte->achievements()->where('type', 'streak')->orderBy('slug')->pluck('slug')->all();

    return $slugs;
}

/**
 * Les succès de type `streak` que le compte s'est vu annoncer.
 *
 * @return list<string>
 */
function serieDeJoursAnnonces(User $compte): array
{
    $slugs = [];

    foreach (Notification::sent($compte, AchievementUnlocked::class) as $annonce) {
        if ($annonce instanceof AchievementUnlocked && $annonce->achievement->type === 'streak') {
            $slugs[] = $annonce->achievement->slug;
        }
    }

    sort($slugs);

    return $slugs;
}

it('ne fait avancer ni la série ni « Série de 3 » pour trois séances démarrées puis terminées sans série, trois jours de suite', function (): void {
    $compte = User::factory()->create();

    foreach (['2026-10-05', '2026-10-06', '2026-10-07'] as $jour) {
        serieDeJoursLe("{$jour} 18:00:00");
        $seance = serieDeJoursDemarrer($compte);

        // Au simple clic sur « Démarrer », la série de l'accueil avançait.
        serieDeJoursAfficheeVaut($compte, 0);

        serieDeJoursTerminer($compte, $seance);
        serieDeJoursAfficheeVaut($compte, 0);
    }

    expect(serieDeJoursStockee($compte))->toBe([null, 0, 0])
        ->and(serieDeJoursSuccesDeSerie($compte))->toBe([])
        ->and(serieDeJoursAnnonces($compte))->toBe([]);
});

it('ne compte ni une séance terminée dont la série n’est pas cochée, ni une série cochée dans une séance en cours, jusqu’à sa fin', function (): void {
    $compte = User::factory()->create();

    serieDeJoursLe('2026-10-05 18:00:00');
    $nonCochee = serieDeJoursDemarrer($compte);
    serieDeJoursAjouterUneSerie($compte, $nonCochee, cochee: false);
    serieDeJoursTerminer($compte, $nonCochee);

    serieDeJoursLe('2026-10-06 18:00:00');
    $enCours = serieDeJoursDemarrer($compte);
    serieDeJoursAjouterUneSerie($compte, $enCours, cochee: true);

    serieDeJoursAfficheeVaut($compte, 0);
    expect(serieDeJoursStockee($compte))->toBe([null, 0, 0]);

    // Terminée, elle devient faite : la série se met à jour à cet instant.
    serieDeJoursLe('2026-10-06 19:00:00');
    serieDeJoursTerminer($compte, $enCours);

    serieDeJoursAfficheeVaut($compte, 1);
    expect(serieDeJoursStockee($compte))->toBe(['2026-10-06 18:00:00', 1, 1]);
});

it('débloque « Série de 3 », notification comprise, à la troisième journée faite d’affilée, une séance vide avant n’y comptant pas', function (): void {
    $compte = User::factory()->create();

    serieDeJoursLe('2026-10-04 18:00:00');
    serieDeJoursFaireUneSeanceVide($compte);

    serieDeJoursLe('2026-10-05 18:00:00');
    serieDeJoursFaireUneSeance($compte);

    serieDeJoursLe('2026-10-06 18:00:00');
    serieDeJoursFaireUneSeance($compte);

    serieDeJoursAfficheeVaut($compte, 2);
    expect(serieDeJoursSuccesDeSerie($compte))->toBe([])
        ->and(serieDeJoursAnnonces($compte))->toBe([]);

    serieDeJoursLe('2026-10-07 18:00:00');
    serieDeJoursFaireUneSeance($compte);

    serieDeJoursAfficheeVaut($compte, 3);
    expect(serieDeJoursStockee($compte))->toBe(['2026-10-07 18:00:00', 3, 3])
        ->and(serieDeJoursSuccesDeSerie($compte))->toBe(['streak-3'])
        ->and(serieDeJoursAnnonces($compte))->toBe(['streak-3']);
});

it('compte un jour une fois, et retient l’heure de sa dernière séance faite, pas celle d’une séance vide', function (): void {
    $compte = User::factory()->create();

    serieDeJoursLe('2026-10-05 09:00:00');
    serieDeJoursFaireUneSeance($compte);

    serieDeJoursLe('2026-10-05 19:00:00');
    serieDeJoursFaireUneSeanceVide($compte);

    expect(serieDeJoursStockee($compte))->toBe(['2026-10-05 09:00:00', 1, 1]);

    serieDeJoursLe('2026-10-06 07:00:00');
    serieDeJoursFaireUneSeance($compte);

    serieDeJoursLe('2026-10-06 20:00:00');
    serieDeJoursFaireUneSeance($compte);

    serieDeJoursAfficheeVaut($compte, 2);
    expect(serieDeJoursStockee($compte))->toBe(['2026-10-06 20:00:00', 2, 2]);
});

it('casse la série sur un jour qui n’a qu’une séance vide', function (): void {
    $compte = User::factory()->create();

    serieDeJoursLe('2026-10-05 18:00:00');
    serieDeJoursFaireUneSeance($compte);

    serieDeJoursLe('2026-10-06 18:00:00');
    serieDeJoursFaireUneSeanceVide($compte);

    serieDeJoursLe('2026-10-07 18:00:00');
    serieDeJoursFaireUneSeance($compte);

    serieDeJoursAfficheeVaut($compte, 1);
    expect(serieDeJoursStockee($compte))->toBe(['2026-10-07 18:00:00', 1, 1]);
});

it('fait redescendre la série, et la plus longue, quand la séance faite d’un jour est supprimée', function (): void {
    $compte = User::factory()->create();

    serieDeJoursLe('2026-10-05 18:00:00');
    serieDeJoursFaireUneSeance($compte);

    serieDeJoursLe('2026-10-06 09:00:00');
    $faiteDuMilieu = serieDeJoursFaireUneSeance($compte);

    serieDeJoursLe('2026-10-06 19:00:00');
    serieDeJoursFaireUneSeanceVide($compte);

    serieDeJoursLe('2026-10-07 18:00:00');
    serieDeJoursFaireUneSeance($compte);

    expect(serieDeJoursStockee($compte))->toBe(['2026-10-07 18:00:00', 3, 3]);

    actingAs($compte)->delete(route('workouts.destroy', $faiteDuMilieu))->assertRedirect();

    // Le 6 n'a plus que sa séance vide : la suite est coupée.
    serieDeJoursAfficheeVaut($compte, 1);
    expect(serieDeJoursStockee($compte))->toBe(['2026-10-07 18:00:00', 1, 1])
        // Un succès se mérite une fois : il n'est pas retiré.
        ->and(serieDeJoursSuccesDeSerie($compte))->toBe(['streak-3']);
});

/*
 * L'application refuse de modifier une séance terminée (403) : décocher sa
 * série, la supprimer ou retirer son exercice passe par un autre écrivain,
 * qui écrit par les modèles. La série se recalcule par chacun.
 */
it('fait redescendre la série quand la seule série cochée d’une séance terminée cesse de compter', function (Closure $retirer): void {
    $compte = User::factory()->create();

    serieDeJoursLe('2026-10-05 18:00:00');
    serieDeJoursFaireUneSeance($compte);

    serieDeJoursLe('2026-10-06 18:00:00');
    $seance = serieDeJoursDemarrer($compte);
    $cochee = serieDeJoursAjouterUneSerie($compte, $seance, cochee: true);
    serieDeJoursTerminer($compte, $seance);

    serieDeJoursLe('2026-10-07 18:00:00');
    serieDeJoursFaireUneSeance($compte);

    expect(serieDeJoursStockee($compte))->toBe(['2026-10-07 18:00:00', 3, 3]);

    actingAs($compte)
        ->patchJson(route('api.v1.sets.update', $cochee), ['is_completed' => false])
        ->assertForbidden();

    expect(serieDeJoursStockee($compte))->toBe(['2026-10-07 18:00:00', 3, 3]);

    $retirer($cochee);

    serieDeJoursAfficheeVaut($compte, 1);
    expect(serieDeJoursStockee($compte))->toBe(['2026-10-07 18:00:00', 1, 1]);
})->with([
    'décochée' => [fn (Set $serie): bool => $serie->update(['is_completed' => false])],
    'supprimée' => [fn (Set $serie): ?bool => $serie->delete()],
    'avec son exercice' => [fn (Set $serie): ?bool => $serie->workoutLine->delete()],
]);

it('compte la séance de minuit, terminée par la file hors ligne, au jour où elle a commencé', function (): void {
    $compte = User::factory()->create();

    serieDeJoursLe('2026-10-03 18:00:00');
    serieDeJoursFaireUneSeanceVide($compte);

    serieDeJoursLe('2026-10-04 18:00:00');
    serieDeJoursFaireUneSeance($compte);

    /*
     * Démarrée à 23 h 50 le 5, terminée à 0 h 40 le 6, sans réseau : la file
     * rejoue le tout le 6 à 8 h, l'heure de l'appareil au départ dans son
     * en-tête (à Paris, UTC+2 en octobre avant le 25).
     */
    serieDeJoursLe('2026-10-06 08:00:00');
    $depart = [HeureDeLAppareil::ENTETE => '2026-10-06T06:00:00.000Z'];

    $demarrage = actingAs($compte)
        ->postJson(route('api.v1.workouts.store'), ['started_at' => '2026-10-05T21:50:00Z'], [...$depart, 'Idempotency-Key' => '2171a5e0-0000-4000-8000-000000000001'])
        ->assertCreated();
    $seance = Workout::query()->whereKey($demarrage->json('data.id'))->firstOrFail();
    serieDeJoursAjouterUneSerie($compte, $seance, cochee: true);

    actingAs($compte)
        ->patchJson(route('api.v1.workouts.finish'), ['workout_id' => $seance->id, 'ended_at' => '2026-10-05T22:40:00Z'], $depart)
        ->assertOk();

    $seance->refresh();

    expect($seance->started_at->format('Y-m-d H:i:s'))->toBe('2026-10-05 23:50:00')
        ->and($seance->ended_at?->format('Y-m-d H:i:s'))->toBe('2026-10-06 00:40:00')
        ->and(serieDeJoursStockee($compte))->toBe(['2026-10-05 23:50:00', 2, 2]);

    serieDeJoursAfficheeVaut($compte, 2);
});

it('suit la fin posée puis retirée par le panneau', function (): void {
    $compte = User::factory()->create();

    serieDeJoursLe('2026-10-05 18:00:00');
    serieDeJoursFaireUneSeance($compte);

    serieDeJoursLe('2026-10-06 18:00:00');
    $enCours = serieDeJoursDemarrer($compte);
    serieDeJoursAjouterUneSerie($compte, $enCours, cochee: true);

    expect(serieDeJoursStockee($compte))->toBe(['2026-10-05 18:00:00', 1, 1]);

    actingAs(FilamentAdminPanel::admin(FilamentAdminPanel::crudPermissions('Workout')), 'admin');

    Livewire::test(EditWorkout::class, ['record' => $enCours->getKey()])
        ->fillForm(['ended_at' => '2026-10-06 19:00:00'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(serieDeJoursStockee($compte))->toBe(['2026-10-06 18:00:00', 2, 2]);

    // Rouverte : elle n'est plus faite.
    Livewire::test(EditWorkout::class, ['record' => $enCours->getKey()])
        ->fillForm(['ended_at' => null])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(serieDeJoursStockee($compte))->toBe(['2026-10-05 18:00:00', 1, 1]);
});

it('ne touche jamais la série d’un autre compte', function (): void {
    $compte = User::factory()->create();
    $autre = User::factory()->create();

    serieDeJoursLe('2026-10-05 18:00:00');
    serieDeJoursFaireUneSeance($autre);
    serieDeJoursFaireUneSeance($compte);

    serieDeJoursLe('2026-10-06 18:00:00');
    serieDeJoursFaireUneSeanceVide($autre);
    $seance = serieDeJoursFaireUneSeance($compte);

    $avant = serieDeJoursStockee($autre);

    actingAs($compte)->delete(route('workouts.destroy', $seance))->assertRedirect();
    serieDeJoursFaireUneSeance($compte);

    expect($avant)->toBe(['2026-10-05 18:00:00', 1, 1])
        ->and(serieDeJoursStockee($autre))->toBe($avant)
        ->and(serieDeJoursStockee($compte))->toBe(['2026-10-06 18:00:00', 2, 2]);
});

describe('le contrôle nocturne', function (): void {
    it('donne au compte la série que l’écrivain lui a donnée, et ne corrige rien', function (): void {
        $compte = User::factory()->create();

        serieDeJoursLe('2026-10-03 18:00:00');
        serieDeJoursFaireUneSeanceVide($compte);

        serieDeJoursLe('2026-10-04 18:00:00');
        serieDeJoursFaireUneSeance($compte);

        serieDeJoursLe('2026-10-05 09:00:00');
        serieDeJoursFaireUneSeance($compte);

        serieDeJoursLe('2026-10-05 19:00:00');
        serieDeJoursFaireUneSeanceVide($compte);

        serieDeJoursLe('2026-10-06 18:00:00');
        $nonCochee = serieDeJoursDemarrer($compte);
        serieDeJoursAjouterUneSerie($compte, $nonCochee, cochee: false);
        serieDeJoursTerminer($compte, $nonCochee);

        serieDeJoursLe('2026-10-07 18:00:00');
        $enCours = serieDeJoursDemarrer($compte);
        serieDeJoursAjouterUneSerie($compte, $enCours, cochee: true);

        $reconstruite = app(StreakService::class)->serieDepuisLesFaits(User::query()->findOrFail($compte->id));

        expect(serieDeJoursStockee($compte))->toBe(['2026-10-05 09:00:00', 2, 2])
            ->and([$reconstruite['derniere'], $reconstruite['enCours'], $reconstruite['plusLongue']])->toBe(serieDeJoursStockee($compte));

        $this->artisan('app:verify-data-coherence')
            ->assertExitCode(0)
            ->expectsOutputToContain('OK date de dernière séance')
            ->expectsOutputToContain('OK série de jours')
            ->expectsOutputToContain('Aucun écart');

        expect(serieDeJoursStockee($compte))->toBe(['2026-10-05 09:00:00', 2, 2]);
    });

    it('signale, puis répare, la série qu’un compte tenait de séances vides', function (): void {
        $compte = User::factory()->create();

        // Ce que l'ancien écrivain laissait : trois séances vides, trois jours.
        Workout::withoutEvents(function () use ($compte): void {
            foreach (['2026-10-05', '2026-10-06', '2026-10-07'] as $jour) {
                Workout::factory()->create([
                    'user_id' => $compte->id,
                    'started_at' => Carbon::parse("{$jour} 18:00:00"),
                    'ended_at' => Carbon::parse("{$jour} 19:00:00"),
                ]);
            }
        });
        $compte->forceFill(['last_workout_at' => '2026-10-07 18:00:00', 'current_streak' => 3, 'longest_streak' => 3])->save();

        $this->artisan('app:verify-data-coherence')
            ->assertExitCode(1)
            ->expectsOutputToContain("utilisateur {$compte->id} : stocké 2026-10-07 18:00:00, dernière séance aucune")
            ->expectsOutputToContain("utilisateur {$compte->id} : stocké 3 jour(s) en cours et 3 au plus long, ses séances en donnent 0 et 0");

        $this->artisan('app:verify-data-coherence', ['--repair' => true]);

        expect(serieDeJoursStockee($compte))->toBe([null, 0, 0]);

        $this->artisan('app:verify-data-coherence')
            ->assertExitCode(0)
            ->expectsOutputToContain('Aucun écart');
    });
});
