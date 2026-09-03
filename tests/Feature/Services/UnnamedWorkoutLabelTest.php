<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Workout;
use App\Services\Stats\VolumeStatsService;
use App\Services\Stats\WorkoutStatsService;

/*
 * `workouts.name` est nullable, et rien ne testait ce cas.
 *
 * Trois choses en decoulaient, toutes revelees par un mutant qui retirait un
 * simple `(string)` — que j'avais d'abord classe « bruit », a tort :
 *
 * 1. Le cast est PORTEUR. Les DTO exigent une chaine ; sans lui, une seance
 *    sans nom fait lever `TypeError: Argument #3 ($name) must be of type
 *    string, null given`, et le graphique de volume renvoie une erreur 500.
 * 2. Aucun test ne creait de seance sans nom, d'ou la survie du mutant.
 * 3. Les deux services ne repondaient pas la meme chose : WorkoutStatsService
 *    repliait sur « Séance », VolumeStatsService sur une etiquette VIDE.
 *
 * Ces tests couvrent le cas pour les trois points d'entree, et la valeur
 * asseree est le libelle traduit — pas la chaine vide, qui laissait une entree
 * anonyme dans la legende du graphique.
 */

/**
 * @return array{User, Workout}
 */
function seanceSansNom(): array
{
    $user = User::factory()->create();

    $workout = Workout::factory()->create([
        'user_id' => $user->id,
        'name' => null,
        'started_at' => now()->subDay()->setTime(10, 0),
        'ended_at' => now()->subDay()->setTime(11, 0),
        'workout_volume' => 1000,
    ]);

    return [$user, $workout];
}

it('étiquette une séance sans nom dans la tendance de volume', function (): void {
    [$user] = seanceSansNom();

    $trend = app(VolumeStatsService::class)->getVolumeTrend($user, 30);

    expect($trend)->toHaveCount(1)
        ->and($trend[0]->name)->toBe(__('Workout'))
        ->and($trend[0]->name)->not->toBe('');
});

it('étiquette une séance sans nom dans l’historique de volume', function (): void {
    [$user] = seanceSansNom();

    $history = app(VolumeStatsService::class)->getVolumeHistory($user, 10);

    expect($history)->toHaveCount(1)
        ->and($history[0]->name)->toBe(__('Workout'))
        ->and($history[0]->name)->not->toBe('');
});

/**
 * Celui-ci repliait deja correctement. Le test existe pour que les deux
 * services restent d'accord : c'est leur desaccord qui etait le defaut.
 */
it('étiquette une séance sans nom dans l’historique de durée', function (): void {
    [$user] = seanceSansNom();

    $history = app(WorkoutStatsService::class)->getDurationHistory($user);

    expect($history)->toHaveCount(1)
        ->and($history[0]->name)->toBe(__('Workout'));
});
