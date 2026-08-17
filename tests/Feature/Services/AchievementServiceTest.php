<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Models\Achievement;
use App\Models\Set;
use App\Models\User;
use App\Models\Workout;
use App\Models\WorkoutLine;
use App\Notifications\AchievementUnlocked;
use App\Services\AchievementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;

use function Pest\Laravel\assertDatabaseHas;
use function Pest\Laravel\assertDatabaseMissing;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->service = app(AchievementService::class);
    Notification::fake();
});

test('it awards count achievement', function (): void {
    $user = User::factory()->create();
    $achievement = Achievement::factory()->create([
        'type' => 'count',
        'threshold' => 1,
        'slug' => 'first-workout',
    ]);

    // Perform workout
    Workout::factory()->create(['user_id' => $user->id]);

    $this->service->syncAchievements($user);

    assertDatabaseHas('user_achievements', [
        'user_id' => $user->id,
        'achievement_id' => $achievement->id,
    ]);

    Notification::assertSentTo(
        $user,
        \App\Notifications\AchievementUnlocked::class,
        fn (AchievementUnlocked $notification): bool => $notification->achievement->id === $achievement->id
    );
});

test('it awards weight_record achievement', function (): void {
    $user = User::factory()->create();
    $achievement = Achievement::factory()->create([
        'type' => 'weight_record',
        'threshold' => 100,
        'slug' => 'heavy-lifter',
    ]);

    $workout = Workout::factory()->create(['user_id' => $user->id]);
    $line = WorkoutLine::factory()->create(['workout_id' => $workout->id]);
    Set::factory()->create([
        'workout_line_id' => $line->id,
        'weight' => 100,
        'reps' => 1,
    ]);

    $this->service->syncAchievements($user);

    assertDatabaseHas('user_achievements', [
        'user_id' => $user->id,
        'achievement_id' => $achievement->id,
    ]);
});

test('it awards volume_total achievement', function (): void {
    $user = User::factory()->create();
    $achievement = Achievement::factory()->create([
        'type' => 'volume_total',
        'threshold' => 1000,
        'slug' => 'volume-novice',
    ]);

    $workout = Workout::factory()->create(['user_id' => $user->id]);
    $line = WorkoutLine::factory()->create(['workout_id' => $workout->id]);

    // Set 1: 50 * 10 = 500
    Set::factory()->create([
        'workout_line_id' => $line->id,
        'weight' => 50,
        'reps' => 10,
    ]);

    // Set 2: 50 * 10 = 500
    Set::factory()->create([
        'workout_line_id' => $line->id,
        'weight' => 50,
        'reps' => 10,
    ]);

    $user->refresh();
    $this->service->syncAchievements($user);

    assertDatabaseHas('user_achievements', [
        'user_id' => $user->id,
        'achievement_id' => $achievement->id,
    ]);
});

test('it awards streak achievement', function (): void {
    $user = User::factory()->create();
    $achievement = Achievement::factory()->create([
        'type' => 'streak',
        'threshold' => 3,
        'slug' => 'streak-3',
    ]);

    // Create 3 workouts on 3 consecutive days
    Workout::factory()->create(['user_id' => $user->id, 'started_at' => now()->subDays(2)]);
    Workout::factory()->create(['user_id' => $user->id, 'started_at' => now()->subDays(1)]);
    Workout::factory()->create(['user_id' => $user->id, 'started_at' => now()]);

    $this->service->syncAchievements($user);

    assertDatabaseHas('user_achievements', [
        'user_id' => $user->id,
        'achievement_id' => $achievement->id,
    ]);
});

/*
 * Ce qui suit couvre le calcul de serie, ou 15 mutants survivaient. Le premier
 * test echouait avant la suppression de la fenetre `seuil + 30 jours` : c'est
 * lui qui a mis le defaut en evidence.
 */

/**
 * Une serie ancienne compte encore.
 *
 * La requete ne regardait que les `seuil + 30` derniers jours. Cinq jours
 * consecutifs il y a sept mois, puis plus rien, et `streak-3` — le seul succes
 * de serie qui existe en base — restait verrouille pour toujours.
 *
 * Personne ne pouvait le voir : un succes qui ne se debloque pas ne leve aucune
 * erreur, et le seul test de serie qui existait posait ses seances la veille.
 */
test('un enchaînement ancien débloque encore le succès de série', function (): void {
    $user = User::factory()->create();
    $achievement = Achievement::factory()->create([
        'type' => 'streak',
        'threshold' => 3,
        'slug' => 'streak-3',
    ]);

    // Cinq jours consecutifs il y a sept mois, et rien depuis.
    foreach ([204, 203, 202, 201, 200] as $joursEnArriere) {
        Workout::factory()->create([
            'user_id' => $user->id,
            'started_at' => now()->subDays($joursEnArriere)->setTime(12, 0),
        ]);
    }

    $this->service->syncAchievements($user);

    assertDatabaseHas('user_achievements', [
        'user_id' => $user->id,
        'achievement_id' => $achievement->id,
    ]);
});

/**
 * Deux jours isoles ne font pas une serie de deux.
 *
 * `$runs[] = 1` ouvre chaque nouvelle serie a 1. Le mutant qui l'ouvre a 2
 * transformait n'importe quelle seance isolee en serie de deux jours, ce qui
 * debloquait un succes de serie sans qu'aucun jour ne se suive.
 */
test('deux jours non consécutifs ne font pas une série de deux', function (): void {
    $user = User::factory()->create();
    $achievement = Achievement::factory()->create([
        'type' => 'streak',
        'threshold' => 2,
        'slug' => 'streak-2',
    ]);

    Workout::factory()->create(['user_id' => $user->id, 'started_at' => now()->setTime(12, 0)]);
    Workout::factory()->create(['user_id' => $user->id, 'started_at' => now()->subDays(5)->setTime(12, 0)]);

    $this->service->syncAchievements($user);

    assertDatabaseMissing('user_achievements', [
        'user_id' => $user->id,
        'achievement_id' => $achievement->id,
    ]);
});

/**
 * Une serie a cheval sur un changement d'heure reste une serie.
 *
 * L'application vit en Europe/Paris : la nuit du passage a l'heure d'ete dure
 * 23 h, celle du retour a l'heure d'hiver 25 h. L'ancien calcul divisait un
 * ecart de secondes par 86400 et arrondissait pour rattraper ces deux nuits —
 * un arrondi que rien ne testait, d'ou quatre mutants survivants (`floor`,
 * `ceil`, 86399, 86401).
 *
 * La comparaison porte desormais sur des jours calendaires, donc ce test passe
 * sans arrondi. Il reste parce que la propriete, elle, doit tenir — et le couple
 * de cas a ete verifie en reposant l'arithmetique de timestamps par-dessus :
 * `floor` fait tomber le passage a l'heure d'ete, `ceil` le retour a l'heure
 * d'hiver, `round` passe les deux. Chacun des deux cas garde donc un mutant
 * distinct, et un seul des deux ne suffirait pas.
 */
test('une série à cheval sur un changement d’heure reste une série', function (string $veille, string $transition, string $lendemain): void {
    $user = User::factory()->create();
    $achievement = Achievement::factory()->create([
        'type' => 'streak',
        'threshold' => 3,
        'slug' => 'streak-3-'.$transition,
    ]);

    foreach ([$veille, $transition, $lendemain] as $jour) {
        Workout::factory()->create([
            'user_id' => $user->id,
            'started_at' => $jour.' 12:00:00',
        ]);
    }

    $this->service->syncAchievements($user);

    assertDatabaseHas('user_achievements', [
        'user_id' => $user->id,
        'achievement_id' => $achievement->id,
    ]);
})->with([
    // Passage a l'heure d'ete : la nuit du 28 au 29 mars 2026 ne dure que 23 h.
    'heure d’été' => ['2026-03-28', '2026-03-29', '2026-03-30'],
    // Retour a l'heure d'hiver : la nuit du 25 au 26 octobre 2025 dure 25 h.
    'heure d’hiver' => ['2025-10-25', '2025-10-26', '2025-10-27'],
]);

/**
 * Sans aucune seance, la serie vaut exactement zero.
 *
 * Les deux seuils encadrent la valeur de repli des deux cotes : un seuil de 0
 * doit passer (0 >= 0) et un seuil de 1 doit echouer. Un repli a 1 debloquerait
 * le second, un repli a -1 refuserait le premier — un seul des deux seuils ne
 * separerait donc rien.
 */
test('sans séance, la plus longue série vaut zéro', function (): void {
    $user = User::factory()->create();

    $sansExigence = Achievement::factory()->create([
        'type' => 'streak',
        'threshold' => 0,
        'slug' => 'streak-0',
    ]);

    $unJour = Achievement::factory()->create([
        'type' => 'streak',
        'threshold' => 1,
        'slug' => 'streak-1',
    ]);

    $this->service->syncAchievements($user);

    assertDatabaseHas('user_achievements', [
        'user_id' => $user->id,
        'achievement_id' => $sansExigence->id,
    ]);

    assertDatabaseMissing('user_achievements', [
        'user_id' => $user->id,
        'achievement_id' => $unJour->id,
    ]);
});

test('it does not award achievement if threshold not met', function (): void {
    $user = User::factory()->create();
    $achievement = Achievement::factory()->create([
        'type' => 'weight_record',
        'threshold' => 100,
    ]);

    $workout = Workout::factory()->create(['user_id' => $user->id]);
    $line = WorkoutLine::factory()->create(['workout_id' => $workout->id]);
    Set::factory()->create([
        'workout_line_id' => $line->id,
        'weight' => 99,
        'reps' => 1,
    ]);

    $user->refresh();
    $this->service->syncAchievements($user);

    assertDatabaseMissing('user_achievements', [
        'user_id' => $user->id,
        'achievement_id' => $achievement->id,
    ]);
});

test('it does not award achievement based on other users data', function (): void {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();

    $achievement = Achievement::factory()->create([
        'type' => 'volume_total',
        'threshold' => 1000,
    ]);

    // Other user meets criteria
    $workout = Workout::factory()->create(['user_id' => $otherUser->id]);
    $line = WorkoutLine::factory()->create(['workout_id' => $workout->id]);
    Set::factory()->create([
        'workout_line_id' => $line->id,
        'weight' => 100,
        'reps' => 10,
    ]); // 1000 volume

    // User A has no workouts
    $this->service->syncAchievements($user);

    assertDatabaseMissing('user_achievements', [
        'user_id' => $user->id,
        'achievement_id' => $achievement->id,
    ]);

    // User A has smaller workout
    $userWorkout = Workout::factory()->create(['user_id' => $user->id]);
    $userLine = WorkoutLine::factory()->create(['workout_id' => $userWorkout->id]);
    Set::factory()->create([
        'workout_line_id' => $userLine->id,
        'weight' => 10,
        'reps' => 10,
    ]); // 100 volume

    $this->service->syncAchievements($user);

    assertDatabaseMissing('user_achievements', [
        'user_id' => $user->id,
        'achievement_id' => $achievement->id,
    ]);
});

test('it does not duplicate achievement assignments', function (): void {
    $user = User::factory()->create();
    $achievement = Achievement::factory()->create([
        'type' => 'count',
        'threshold' => 1,
    ]);

    Workout::factory()->create(['user_id' => $user->id]);

    // First sync
    $this->service->syncAchievements($user);
    $this->assertEquals(1, $user->achievements()->count());

    // Second sync
    $this->service->syncAchievements($user);
    $this->assertEquals(1, $user->achievements()->count());

    Notification::assertSentTimes(\App\Notifications\AchievementUnlocked::class, 1);
});

test('it awards multiple achievements at once', function (): void {
    $user = User::factory()->create();

    $achievement1 = Achievement::factory()->create([
        'type' => 'count',
        'threshold' => 1,
        'slug' => 'first-workout',
    ]);

    $achievement2 = Achievement::factory()->create([
        'type' => 'weight_record',
        'threshold' => 100,
        'slug' => 'heavy-lifter',
    ]);

    $workout = Workout::factory()->create(['user_id' => $user->id]);
    $line = WorkoutLine::factory()->create(['workout_id' => $workout->id]);
    Set::factory()->create([
        'workout_line_id' => $line->id,
        'weight' => 100,
        'reps' => 1,
    ]);

    $this->service->syncAchievements($user);

    assertDatabaseHas('user_achievements', [
        'user_id' => $user->id,
        'achievement_id' => $achievement1->id,
    ]);

    assertDatabaseHas('user_achievements', [
        'user_id' => $user->id,
        'achievement_id' => $achievement2->id,
    ]);

    Notification::assertSentTimes(\App\Notifications\AchievementUnlocked::class, 2);
});

/**
 * Un type de succes inconnu ne debloque rien.
 *
 * `isUnlocked()` est un `match` sur le type, dont le bras par defaut rend
 * `false`. C'est lui qui decide du sort d'un type que le code ne connait pas —
 * et le cas se produit des qu'on ajoute un type en base sans ajouter son bras,
 * ce que rien n'empeche : `Achievement::type` est une simple colonne texte.
 *
 * Le bras par defaut n'etait couvert par aucun test : la mutation `false` en
 * `true` survivait. Avec elle, un succes au type inconnu se debloque pour TOUS
 * les utilisateurs, immediatement, avec notification — quel que soit son seuil.
 */
test('un succès au type inconnu ne se débloque pour personne', function (): void {
    $user = User::factory()->create();

    $inconnu = Achievement::factory()->create([
        'type' => 'type_qui_nexiste_pas',
        'threshold' => 1,
        'slug' => 'type-inconnu',
    ]);

    // Une seance, pour que la synchronisation ait bien de quoi travailler : le
    // test doit echouer parce que le bras par defaut a change, pas parce que
    // rien ne s'est passe.
    Workout::factory()->create(['user_id' => $user->id]);

    $this->service->syncAchievements($user);

    assertDatabaseMissing('user_achievements', [
        'user_id' => $user->id,
        'achievement_id' => $inconnu->id,
    ]);
});
