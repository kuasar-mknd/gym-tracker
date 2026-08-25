<?php

declare(strict_types=1);

/*
 * Les deux gardes de `CreateHabitAction` disent : « une couleur, c'est une
 * chaine NON VIDE ; sinon prends celle-ci ». La moitie « non vide » n'etait
 * verifiee par personne : remplacer `=== ''` par la comparaison a n'importe
 * quelle autre chaine laissait la suite au vert.
 *
 * Ce que cela laissait passer en clair : une habitude enregistree avec une
 * couleur vide. La colonne porte un defaut en base, mais un defaut de colonne
 * ne s'applique qu'a une valeur ABSENTE — une chaine vide envoyee
 * explicitement est stockee telle quelle, et l'habitude s'affiche alors sans
 * pastille ni icone.
 */

use App\Actions\Habits\CreateHabitAction;
use App\Models\User;

it('remplace une couleur vide par la couleur par defaut', function (): void {
    $user = User::factory()->create();

    $habit = app(CreateHabitAction::class)->execute($user, [
        'name' => 'Boire de l\'eau',
        'color' => '',
        'icon' => 'water_drop',
        'goal_times_per_week' => 7,
    ]);

    expect($habit->color)->toBe('bg-slate-500');
    // L'icone fournie, elle, est conservee : le repli ne deborde pas.
    expect($habit->icon)->toBe('water_drop');
    expect($habit->refresh()->color)->toBe('bg-slate-500');
});

it('remplace une icone vide par l\'icone par defaut', function (): void {
    $user = User::factory()->create();

    $habit = app(CreateHabitAction::class)->execute($user, [
        'name' => 'Etirements',
        'color' => 'bg-emerald-500',
        'icon' => '',
        'goal_times_per_week' => 3,
    ]);

    expect($habit->icon)->toBe('check_circle');
    expect($habit->color)->toBe('bg-emerald-500');
    expect($habit->refresh()->icon)->toBe('check_circle');
});

it('conserve une couleur et une icone renseignees', function (): void {
    $user = User::factory()->create();

    $habit = app(CreateHabitAction::class)->execute($user, [
        'name' => 'Course',
        'color' => 'bg-rose-500',
        'icon' => 'directions_run',
        'goal_times_per_week' => 2,
    ]);

    expect($habit->color)->toBe('bg-rose-500');
    expect($habit->icon)->toBe('directions_run');
    expect($habit->user_id)->toBe($user->id);
});
