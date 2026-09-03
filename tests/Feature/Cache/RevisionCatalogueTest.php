<?php

declare(strict_types=1);

use App\Models\Exercise;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

/**
 * La revision du catalogue etait `time()`.
 *
 * Deux modifications faites dans la meme seconde la marquaient de la meme
 * valeur : la seconde n'invalidait plus rien. Une liste reconstruite entre les
 * deux — par n'importe quelle requete d'utilisateur — restait servie une heure.
 *
 * L'horloge figee est ce qui rend le temoin deterministe : c'est exactement ce
 * qu'une implementation qui la lit ne peut pas satisfaire.
 */
it('avance la révision deux fois de suite, horloge figée', function (): void {
    $this->freezeTime();
    $catalogue = Exercise::factory()->create(['user_id' => null, 'name' => 'Développé']);

    $catalogue->update(['name' => 'Développé couché']);
    $premiere = Cache::get('exercises_catalogue_revision');

    $catalogue->update(['name' => 'Développé incliné']);
    $seconde = Cache::get('exercises_catalogue_revision');

    expect($premiere)->not->toBe($seconde);
});

it('sert la deuxième modification du catalogue faite dans la même seconde', function (): void {
    $this->freezeTime();
    $user = User::factory()->create();
    $catalogue = Exercise::factory()->create(['user_id' => null, 'name' => 'Squat']);

    $catalogue->update(['name' => 'Squat avant']);
    Exercise::getCachedForUser($user->id);

    $catalogue->update(['name' => 'Squat arrière']);

    expect(Exercise::getCachedForUser($user->id)->pluck('name')->all())->toContain('Squat arrière');
});

it('n’invalide pas la liste d’un autre utilisateur pour un exercice personnel', function (): void {
    $premier = User::factory()->create();
    $second = User::factory()->create();
    Exercise::factory()->create(['user_id' => $second->id, 'name' => 'Tirage']);

    $listeDuPremier = Exercise::getCachedForUser($premier->id);
    Exercise::factory()->create(['user_id' => $second->id, 'name' => 'Rowing']);

    expect(Cache::has('exercices_liste_'.$premier->id.'_r0'))->toBeTrue()
        ->and($listeDuPremier->pluck('name')->all())->not->toContain('Tirage');
});

it('rend la liste à jour après la création d’un exercice personnel', function (): void {
    $user = User::factory()->create();
    Exercise::getCachedForUser($user->id);

    Exercise::factory()->create(['user_id' => $user->id, 'name' => 'Soulevé de terre']);

    expect(Exercise::getCachedForUser($user->id)->pluck('name')->all())->toContain('Soulevé de terre');
});
