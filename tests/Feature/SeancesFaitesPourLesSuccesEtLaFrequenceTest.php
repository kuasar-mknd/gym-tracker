<?php

declare(strict_types=1);

use App\Models\Exercise;
use App\Models\Goal;
use App\Models\User;
use App\Models\Workout;
use App\Notifications\AchievementUnlocked;
use Database\Seeders\AchievementSeeder;
use Illuminate\Support\Facades\Notification;

use function Pest\Laravel\actingAs;

/*
 * Une séance ne compte, pour les succès de séances (« Première Séance »,
 * « Guerrier de la Semaine », « Roi de la Constance ») et pour les objectifs
 * de fréquence, que si elle est FAITE : terminée, avec au moins une série
 * cochée (#2110).
 *
 * Les deux comptaient toutes les séances du compte, et `Workout::saved`
 * relance leurs recalculs dès la création : démarrer une séance débloquait
 * aussitôt « Première Séance », notification comprise, avant toute série, et
 * un succès ne se retire jamais. Trois séances démarrées puis terminées sans
 * rien soulever débloquaient « Guerrier de la Semaine » ; un objectif de
 * fréquence avançait de même.
 *
 * Chaque cas passe par les routes que la page emprunte : démarrer
 * (`workouts.store`), ajouter un exercice et une série par l'API, terminer
 * (`workouts.update`, `is_finished`), supprimer (`workouts.destroy`). Les
 * recalculs tournent dans la requête, la file étant synchrone sous les tests.
 */

beforeEach(function (): void {
    $this->seed(AchievementSeeder::class);
    Notification::fake();
});

/**
 * Démarre une séance par la page, et la rend.
 */
function seancesFaitesDemarrer(User $compte): Workout
{
    actingAs($compte)->post(route('workouts.store'))->assertRedirect();

    return Workout::query()->where('user_id', $compte->id)->latest('id')->firstOrFail();
}

/**
 * Termine la séance par la page.
 */
function seancesFaitesTerminer(User $compte, Workout $seance): void
{
    actingAs($compte)
        ->patch(route('workouts.update', $seance), ['is_finished' => true])
        ->assertRedirect(route('dashboard'));
}

/**
 * Ajoute à la séance un exercice et une série, cochée ou non, par l'API de
 * la page. Soixante kilos pour huit répétitions : ni record de charge, ni
 * volume cumulé, ni série de jours n'atteignent un seuil du catalogue, seuls
 * les succès de séances peuvent se débloquer.
 */
function seancesFaitesAjouterUneSerie(User $compte, Workout $seance, bool $cochee): void
{
    $exercice = Exercise::factory()->create(['user_id' => $compte->id, 'type' => 'strength']);

    $ligne = actingAs($compte)
        ->postJson(route('api.v1.workout-lines.store'), ['workout_id' => $seance->id, 'exercise_id' => $exercice->id])
        ->assertCreated();

    actingAs($compte)
        ->postJson(route('api.v1.sets.store'), [
            'workout_line_id' => $ligne->json('data.id'),
            'weight' => 60,
            'reps' => 8,
            'is_completed' => $cochee,
        ])
        ->assertCreated();
}

/**
 * Démarre, coche une série et termine : une séance faite.
 */
function seancesFaitesFaireUneSeance(User $compte): Workout
{
    $seance = seancesFaitesDemarrer($compte);
    seancesFaitesAjouterUneSerie($compte, $seance, cochee: true);
    seancesFaitesTerminer($compte, $seance);

    return $seance;
}

/**
 * Les succès débloqués du compte, par leur nom court.
 *
 * @return list<string>
 */
function seancesFaitesSuccesDuCompte(User $compte): array
{
    /** @var list<string> $slugs */
    $slugs = $compte->achievements()->orderBy('slug')->pluck('slug')->all();

    return $slugs;
}

/**
 * Les succès que le compte s'est vu annoncer, par leur nom court.
 *
 * @return list<string>
 */
function seancesFaitesSuccesAnnonces(User $compte): array
{
    $slugs = [];

    foreach (Notification::sent($compte, AchievementUnlocked::class) as $annonce) {
        if ($annonce instanceof AchievementUnlocked) {
            $slugs[] = $annonce->achievement->slug;
        }
    }

    sort($slugs);

    return $slugs;
}

it('ne débloque rien au démarrage d’une séance, ni après sa suppression', function (): void {
    $compte = User::factory()->create();

    foreach (range(1, 3) as $essai) {
        $seance = seancesFaitesDemarrer($compte);

        expect($seance->ended_at)->toBeNull()
            ->and(seancesFaitesSuccesDuCompte($compte))->toBe([], "démarrage {$essai}");

        actingAs($compte)->delete(route('workouts.destroy', $seance))->assertRedirect();
    }

    expect(Workout::query()->where('user_id', $compte->id)->count())->toBe(0)
        ->and(seancesFaitesSuccesDuCompte($compte))->toBe([])
        ->and(seancesFaitesSuccesAnnonces($compte))->toBe([]);
});

it('ne compte pas trois séances terminées sans aucune série', function (): void {
    $compte = User::factory()->create();

    foreach (range(1, 3) as $_) {
        seancesFaitesTerminer($compte, seancesFaitesDemarrer($compte));
    }

    expect(Workout::query()->where('user_id', $compte->id)->whereNotNull('ended_at')->count())->toBe(3)
        ->and(seancesFaitesSuccesDuCompte($compte))->toBe([])
        ->and(seancesFaitesSuccesAnnonces($compte))->toBe([]);
});

it('ne compte pas une séance terminée dont la série n’a pas été cochée', function (): void {
    $compte = User::factory()->create();

    $seance = seancesFaitesDemarrer($compte);
    seancesFaitesAjouterUneSerie($compte, $seance, cochee: false);
    seancesFaitesTerminer($compte, $seance);

    expect(seancesFaitesSuccesDuCompte($compte))->toBe([])
        ->and(seancesFaitesSuccesAnnonces($compte))->toBe([]);
});

it('débloque « Première Séance » à la fin de la séance faite, pas avant', function (): void {
    $compte = User::factory()->create();

    $seance = seancesFaitesDemarrer($compte);
    seancesFaitesAjouterUneSerie($compte, $seance, cochee: true);

    // Une série cochée dans une séance encore en cours : rien encore.
    expect(seancesFaitesSuccesDuCompte($compte))->toBe([])
        ->and(seancesFaitesSuccesAnnonces($compte))->toBe([]);

    seancesFaitesTerminer($compte, $seance);

    expect(seancesFaitesSuccesDuCompte($compte))->toBe(['first-workout'])
        ->and(seancesFaitesSuccesAnnonces($compte))->toBe(['first-workout']);
});

it('débloque « Guerrier de la Semaine » à la troisième séance faite, les séances vides n’y comptant pas', function (): void {
    $compte = User::factory()->create();

    seancesFaitesFaireUneSeance($compte);
    seancesFaitesFaireUneSeance($compte);
    seancesFaitesTerminer($compte, seancesFaitesDemarrer($compte));

    expect(seancesFaitesSuccesDuCompte($compte))->toBe(['first-workout']);

    seancesFaitesFaireUneSeance($compte);

    expect(seancesFaitesSuccesDuCompte($compte))->toBe(['first-workout', 'week-warrior'])
        ->and(seancesFaitesSuccesAnnonces($compte))->toBe(['first-workout', 'week-warrior']);
});

it('fait avancer un objectif de fréquence par les seules séances faites', function (): void {
    $compte = User::factory()->create();

    actingAs($compte)
        ->post(route('goals.store'), [
            'title' => 'Deux séances',
            'type' => 'frequency',
            'target_value' => 2,
            'start_value' => 0,
            'deadline' => now()->addMonth()->toDateString(),
        ])
        ->assertRedirect();

    $objectif = Goal::query()->where('user_id', $compte->id)->sole();

    $vide = seancesFaitesDemarrer($compte);
    expect((float) $objectif->refresh()->current_value)->toBe(0.0);

    seancesFaitesTerminer($compte, $vide);
    expect((float) $objectif->refresh()->current_value)->toBe(0.0);

    $nonCochee = seancesFaitesDemarrer($compte);
    seancesFaitesAjouterUneSerie($compte, $nonCochee, cochee: false);
    seancesFaitesTerminer($compte, $nonCochee);
    expect((float) $objectif->refresh()->current_value)->toBe(0.0);

    seancesFaitesFaireUneSeance($compte);
    expect((float) $objectif->refresh()->current_value)->toBe(1.0)
        ->and($objectif->completed_at)->toBeNull();

    seancesFaitesFaireUneSeance($compte);
    expect((float) $objectif->refresh()->current_value)->toBe(2.0)
        ->and((float) $objectif->progress_pct)->toBe(100.0)
        ->and($objectif->completed_at)->not->toBeNull();

    // Démarrer une séance de plus ne le fait pas avancer.
    seancesFaitesDemarrer($compte);
    expect((float) $objectif->refresh()->current_value)->toBe(2.0);
});

it('compte les séances faites de la même façon à la création d’un objectif de fréquence', function (): void {
    $compte = User::factory()->create();

    seancesFaitesFaireUneSeance($compte);
    seancesFaitesTerminer($compte, seancesFaitesDemarrer($compte));
    seancesFaitesDemarrer($compte);

    // La création calcule l'avancement par le chemin d'un seul objectif
    // (`GoalService::updateGoalProgress()`), pas par le lot.
    actingAs($compte)
        ->post(route('goals.store'), [
            'title' => 'Cinq séances',
            'type' => 'frequency',
            'target_value' => 5,
            'start_value' => 0,
            'deadline' => now()->addMonth()->toDateString(),
        ])
        ->assertRedirect();

    expect((float) Goal::query()->where('user_id', $compte->id)->sole()->current_value)->toBe(1.0);
});
