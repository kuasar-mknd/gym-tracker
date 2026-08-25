<?php

declare(strict_types=1);

use App\Actions\Workouts\UpdateWorkoutAction;
use App\Models\User;
use App\Models\Workout;
use Illuminate\Support\Facades\Cache;

it('clears only metadata caches when name is updated', function (): void {
    $user = User::factory()->create();
    $workout = Workout::factory()->create([
        'user_id' => $user->id,
        'started_at' => now(),
        'name' => 'Old Name',
    ]);

    // Seed caches
    Cache::put("stats.weekly_volume.{$user->id}", ['some_data'], 600);
    Cache::put("stats.volume_trend.{$user->id}.30", ['some_data'], 600);

    // Assert seeded
    expect(Cache::has("stats.weekly_volume.{$user->id}"))->toBeTrue();
    expect(Cache::has("stats.volume_trend.{$user->id}.30"))->toBeTrue();

    // Execute Action
    $action = app(UpdateWorkoutAction::class);
    $action->execute($workout, ['name' => 'New Name']);

    // Assert Metadata Cache is CLEARED
    expect(Cache::has("stats.volume_trend.{$user->id}.30"))->toBeFalse();

    // Assert Aggregation Cache is PRESERVED
    // This assertion expects the OPTIMIZATION to be in place.
    // It will FAIL initially because currently UpdateWorkoutAction calls clearWorkoutRelatedStats which clears EVERYTHING.
    expect(Cache::has("stats.weekly_volume.{$user->id}"))->toBeTrue();
});

it('clears all caches when date is updated', function (): void {
    $user = User::factory()->create();
    $workout = Workout::factory()->create([
        'user_id' => $user->id,
        'started_at' => now(),
    ]);

    // Seed caches
    Cache::put("stats.weekly_volume.{$user->id}", ['some_data'], 600);
    Cache::put("stats.volume_trend.{$user->id}.30", ['some_data'], 600);

    // Execute Action
    $action = app(UpdateWorkoutAction::class);
    $action->execute($workout, ['started_at' => now()->subDay()->toDateTimeString()]);

    // Assert ALL CLEARED
    expect(Cache::has("stats.volume_trend.{$user->id}.30"))->toBeFalse();
    expect(Cache::has("stats.weekly_volume.{$user->id}"))->toBeFalse();
});

/*
 * Trois reecritures de l'action passaient encore les deux tests ci-dessus :
 * ignorer `ended_at` dans la detection de changement, ne plus forcer le vidage
 * complet quand la seance se termine, et supprimer l'appel aux historiques dans
 * la branche complete. Les deux premieres sont fermees ci-dessous ; la
 * troisieme ne change aucun etat observable — voir le commentaire du dernier
 * test.
 */

it('vide tout le cache quand la fin de seance a change', function (): void {
    $user = User::factory()->create();
    $workout = Workout::factory()->create([
        'user_id' => $user->id,
        'started_at' => now(),
    ]);

    Cache::put("stats.weekly_volume.{$user->id}", ['some_data'], 600);

    /*
     * `fill()` ne touche jamais `ended_at` : la seule facon pour lui d'etre
     * sale a l'entree est d'avoir ete pose sur le modele avant l'appel. Rien ne
     * couvrait ce chemin, donc retirer 'ended_at' de `isDirty([...])` ligne 33
     * ne faisait echouer aucun test — alors qu'une fin de seance deplacee
     * change la duree et le volume de la semaine.
     */
    $workout->ended_at = now()->addHour();

    app(UpdateWorkoutAction::class)->execute($workout, []);

    expect($workout->refresh()->ended_at)->not->toBeNull();
    expect(Cache::has("stats.weekly_volume.{$user->id}"))->toBeFalse();
});

it('vide tout le cache quand la seance se termine, sans autre changement', function (): void {
    $user = User::factory()->create();
    $workout = Workout::factory()->create([
        'user_id' => $user->id,
        'started_at' => now(),
        'name' => 'Push',
    ]);

    Cache::put("stats.weekly_volume.{$user->id}", ['some_data'], 600);
    Cache::put("stats.volume_trend.{$user->id}.30", ['some_data'], 600);

    // Rien d'autre que la cloture : ni nom, ni date. C'est le seul cas ou le
    // `true` de la ligne 38 decide seul, et il n'etait pas teste — le remplacer
    // par `false` laissait la seance se terminer sans rien invalider.
    app(UpdateWorkoutAction::class)->execute($workout, ['is_finished' => true]);

    expect($workout->refresh()->ended_at)->not->toBeNull();
    expect(Cache::has("stats.weekly_volume.{$user->id}"))->toBeFalse();
    expect(Cache::has("stats.volume_trend.{$user->id}.30"))->toBeFalse();
});

it('vide les historiques en plus des agregats quand elle vide tout', function (): void {
    $user = User::factory()->create();
    $workout = Workout::factory()->create([
        'user_id' => $user->id,
        'started_at' => now(),
    ]);

    /*
     * Ce test est ce qui rend sure la suppression de l'appel redondant a
     * `clearWorkoutMetadataStats()` qui suivait `clearWorkoutRelatedStats()`.
     *
     * Les quatre familles de cles que le premier oubliait
     * (`volume_history.20`, `volume_history.30`, `duration_history.20`,
     * `volume_trend.{7,30,90,365}`) sont TOUTES deja oubliees par le second :
     * `clearVolumeStats` boucle sur `BORNES = [20, 30]` et sur `PERIODES`,
     * `clearDurationStats` sur les memes bornes. Sous-ensemble strict, donc le
     * second appel n'oubliait rien de neuf — d'ou un mutant qu'aucun test
     * fonde sur l'etat du cache ne pouvait tuer, et une ligne retiree plutot
     * qu'une assertion ajoutee.
     *
     * Ce que ce test tient : apres un vidage complet, ces entrees sont bien
     * parties. L'inclusion est verifiee aujourd'hui, pas garantie demain — les
     * deux listes ont deja derive l'une de l'autre (#1502). Si elle cesse
     * d'etre vraie, c'est ici que ca se verra.
     */
    Cache::put("stats.volume_history.{$user->id}.20", ['some_data'], 600);
    Cache::put("stats.volume_history.{$user->id}.30", ['some_data'], 600);
    Cache::put("stats.duration_history.{$user->id}.20", ['some_data'], 600);

    app(UpdateWorkoutAction::class)->execute($workout, ['is_finished' => true]);

    expect(Cache::has("stats.volume_history.{$user->id}.20"))->toBeFalse();
    expect(Cache::has("stats.volume_history.{$user->id}.30"))->toBeFalse();
    expect(Cache::has("stats.duration_history.{$user->id}.20"))->toBeFalse();
});
