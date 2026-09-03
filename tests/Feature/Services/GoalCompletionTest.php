<?php

declare(strict_types=1);

use App\Enums\GoalType;
use App\Models\BodyMeasurement;
use App\Models\Goal;
use App\Models\User;
use App\Services\GoalService;

/*
 * Ce qui decide qu'un objectif est atteint.
 *
 * Deux regles cohabitent : « plus c'est haut, mieux c'est » pour la force, le
 * volume et la frequence, et « plus c'est bas, mieux c'est » pour une mensuration
 * qu'on cherche a faire baisser — perdre du poids, descendre en masse grasse.
 * C'est `target_value < start_value` qui bascule de l'une a l'autre.
 *
 * Rien ne testait ces bornes. Un objectif de perte de poids atteint PILE, un
 * objectif dont la cible egale le point de depart, une mensuration a 1 : trois
 * cas ou deux implementations raisonnables divergent, et ou quatre mutants
 * survivaient.
 */

/**
 * Un objectif de mensuration, avec sa derniere mesure enregistree.
 */
function objectifDeMensuration(float $depart, float $cible, float $mesure, string $type = 'weight'): Goal
{
    $user = User::factory()->create();

    BodyMeasurement::factory()->create([
        'user_id' => $user->id,
        'measured_at' => now()->subDay(),
        $type => $mesure,
    ]);

    $goal = Goal::factory()->create([
        'user_id' => $user->id,
        'exercise_id' => null,
        'type' => GoalType::Measurement,
        'measurement_type' => $type,
        'start_value' => $depart,
        'target_value' => $cible,
        'current_value' => $depart,
        'completed_at' => null,
    ]);

    app(GoalService::class)->syncGoals($user);

    return $goal->refresh();
}

/**
 * Perdre du poids : la cible atteinte PILE compte comme atteinte.
 *
 * `<=` plutot que `<`. Descendre a 80 kg quand on visait 80 kg, c'est reussi —
 * le mutant qui exige 79,99 laisse l'objectif ouvert pour toujours, puisque la
 * cible ronde est justement celle qu'on vise.
 */
it('valide un objectif de perte de poids atteint pile', function (): void {
    expect(objectifDeMensuration(depart: 90, cible: 80, mesure: 80)->completed_at)->not->toBeNull();
});

it('laisse ouvert un objectif de perte de poids pas encore atteint', function (): void {
    expect(objectifDeMensuration(depart: 90, cible: 80, mesure: 81)->completed_at)->toBeNull();
});

/**
 * Cible egale au depart : on retombe sur « plus c'est haut, mieux c'est ».
 *
 * `target < start` est strict, et c'est ce qui distingue une perte de poids d'un
 * objectif ou la cible ne descend pas. Le mutant `<=` ferait basculer ce cas du
 * cote « plus bas, mieux c'est » : a 85 kg pour une cible de 80 partie de 80,
 * l'objectif passerait d'atteint a non atteint.
 */
it('traite une cible égale au départ comme un objectif à la hausse', function (): void {
    expect(objectifDeMensuration(depart: 80, cible: 80, mesure: 85)->completed_at)->not->toBeNull();
});

/**
 * Le garde `> 0` protege du zero, pas du un.
 *
 * Il existe pour qu'une mensuration absente — donc a zero — ne fasse pas passer
 * pour atteint un objectif de perte de poids, ou toute valeur basse satisfait la
 * cible. Le mutant qui le porte a `> 1` refuserait une mesure de 1, valeur que
 * la validation accepte (`min:1`) et qui a un sens pour un pourcentage.
 */
it('valide une mensuration à un, que le garde du zéro ne doit pas exclure', function (): void {
    expect(objectifDeMensuration(depart: 30, cible: 5, mesure: 1, type: 'body_fat')->completed_at)->not->toBeNull();
});

/**
 * Un objectif redevenu inatteint se rouvre — mais par un seul chemin.
 *
 * `syncGoals` filtre `whereNull('completed_at')` : un objectif atteint n'est plus
 * jamais reexamine par le lot, ce qui est coherent — un objectif atteint l'est
 * une fois pour toutes, comme un succes. La branche qui efface `completed_at`
 * n'est donc atteignable que depuis l'ecran d'edition, quand on releve la cible.
 *
 * C'est ce que ce test emprunte. Le mutant qui remplace le `&&` de
 * `checkCompletion` par un `||` reecrit la date au lieu de l'effacer : l'objectif
 * reste marque atteint alors qu'on vient d'en eloigner la cible.
 */
it('rouvre un objectif dont on relève la cible', function (): void {
    $user = User::factory()->create();

    $goal = Goal::factory()->create([
        'user_id' => $user->id,
        'exercise_id' => null,
        'type' => GoalType::Frequency,
        'title' => 'Trois séances',
        'start_value' => 0,
        'target_value' => 3,
        'current_value' => 3,
        'completed_at' => now()->subDay(),
    ]);

    // Aucune seance en base : la cible relevee a dix ne peut plus etre atteinte.
    $this->actingAs($user)
        ->put(route('goals.update', $goal), [
            'title' => 'Dix séances',
            'type' => 'frequency',
            'target_value' => 10,
            'deadline' => now()->addMonths(3)->toDateString(),
        ])
        ->assertRedirect();

    expect($goal->refresh()->completed_at)->toBeNull();
});
