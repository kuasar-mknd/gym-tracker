<?php

declare(strict_types=1);

use App\Models\User;

use function Pest\Laravel\get;

/**
 * Le raccourci de connexion de développement ouvre une session sans mot de
 * passe. Ce qui le rend acceptable n'est pas qu'il soit discret, c'est qu'il
 * n'existe pas hors de l'environnement local — la route n'est alors pas
 * enregistrée, donc connaître l'URL ne sert à rien.
 *
 * La suite tourne avec APP_ENV=testing, ce qui est précisément le cas à
 * vérifier : tout environnement qui n'est pas local.
 */
it('does not exist outside the local environment', function (): void {
    expect(app()->environment('local'))->toBeFalse();

    get('/__dev-login')->assertNotFound();
});

it('does not advertise itself outside the local environment', function (): void {
    User::factory()->create();

    $props = get(route('login'))->viewData('page')['props'];

    expect($props['is_local'])->toBeFalse();
});
