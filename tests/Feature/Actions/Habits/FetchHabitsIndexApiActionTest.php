<?php

declare(strict_types=1);

/*
 * La taille de page de l'API habitudes etait traversee par les tests sans etre
 * verifiee : les trois mutants de `FetchHabitsIndexApiAction` survivaient tous,
 * soit un score de 0 %.
 *
 * Ce que chacun laissait passer, concretement — les trois reecritures passaient
 * la suite au vert : ignorer le `per_page` demande et imposer 15 a tout le
 * monde, ou decaler la valeur par defaut a 14 ou a 16. Personne ne comptait ce
 * qui revenait.
 */

use App\Actions\Habits\FetchHabitsIndexApiAction;
use App\Models\Habit;
use App\Models\User;

it('decoupe la page a la taille demandee par la requete', function (): void {
    $user = User::factory()->create();
    Habit::factory()->count(4)->create(['user_id' => $user->id]);

    $page = app(FetchHabitsIndexApiAction::class)->execute($user, ['per_page' => 2]);

    // 2 et non 15 : c'est la valeur de la requete qui gagne. Sans cette
    // assertion, un code qui ignorerait `per_page` rendrait les quatre
    // habitudes sur une page unique sans que rien ne proteste.
    expect($page->perPage())->toBe(2)
        ->and($page->items())->toHaveCount(2)
        ->and($page->total())->toBe(4)
        ->and($page->lastPage())->toBe(2);
});

it('retombe sur quinze par page quand la requete ne dit rien', function (): void {
    $user = User::factory()->create();
    Habit::factory()->count(16)->create(['user_id' => $user->id]);

    $page = app(FetchHabitsIndexApiAction::class)->execute($user, []);

    // Quinze exactement, et une seizieme habitude qui deborde sur la page 2 :
    // c'est ce qui distingue 15 de 14 et de 16. Un jeu de moins de seize
    // habitudes tiendrait sur une page dans les trois cas.
    expect($page->perPage())->toBe(15)
        ->and($page->items())->toHaveCount(15)
        ->and($page->total())->toBe(16)
        ->and($page->lastPage())->toBe(2);
});
