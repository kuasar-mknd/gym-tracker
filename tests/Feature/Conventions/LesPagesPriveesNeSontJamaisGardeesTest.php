<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Tests\Support\PagesGardees;

/*
 * La page garde, chiffrées et pour un seul compte, des copies de quelques
 * pages utiles en salle (#2048, décisions du propriétaire du 2026-10-12). La
 * liste blanche (`resources/js/Utils/pagesGardees.js`) ne s'étend jamais aux
 * pages que l'issue écarte : la santé et le journal (mesures, parties du
 * corps, habitudes, compléments, eau, jeûne, objectifs, Wilks, macros), les
 * notifications, le profil à modifier, un formulaire de création ou
 * d'édition, une page d'invité. Une copie survit à la page et au réseau ;
 * rien de ce qu'on ne montrerait pas à la personne suivante sur l'appareil ne
 * doit y entrer, même chiffré.
 *
 * Chaque page écartée se nomme par sa route et par son composant, et la garde
 * vérifie que ces noms existent : une route renommée ne la ferait pas passer
 * à vide. Les formulaires et les pages d'invité se reconnaissent en plus à
 * leur forme, pour ceux qui viendront : une route qui finit par `.create` ou
 * `.edit`, un composant qui finit par `Create` ou `Edit` ou qui vit sous
 * `Auth`, une route sans `auth` ou sous `guest`.
 */

/**
 * Les pages que l'issue écarte : le nom de la route, et le composant qu'elle rend.
 *
 * @return array<string, string>
 */
function pagesPriveesEcartees(): array
{
    return [
        'daily-journals.index' => 'Journal/Index',
        'body-measurements.index' => 'Measurements/Index',
        'body-parts.index' => 'Measurements/Parts/Index',
        'body-parts.show' => 'Measurements/Parts/Show',
        'habits.index' => 'Habits/Index',
        'supplements.index' => 'Supplements/Index',
        'tools.water.index' => 'Tools/WaterTracker',
        'tools.fasting.index' => 'Tools/Fasting/Index',
        'goals.index' => 'Goals/Index',
        'goals.edit' => 'Goals/Edit',
        'tools.wilks' => 'Tools/WilksCalculator',
        'tools.macro-calculator' => 'Tools/MacroCalculator',
        'notifications.index' => 'Notifications/Index',
        'profile.edit' => 'Profile/Edit',
        'templates.create' => 'Workouts/Templates/Create',
        'templates.edit' => 'Workouts/Templates/Edit',
        'login' => 'Auth/Login',
        'register' => 'Auth/Register',
        'password.request' => 'Auth/ForgotPassword',
        'password.reset' => 'Auth/ResetPassword',
        'password.confirm' => 'Auth/ConfirmPassword',
        'verification.notice' => 'Auth/VerifyEmail',
    ];
}

it('nomme des pages que l’application sert', function (): void {
    foreach (pagesPriveesEcartees() as $route => $composant) {
        expect(Route::has($route))->toBeTrue("La route `{$route}` n’existe plus : la garde doit nommer celle qui l’a remplacée.")
            ->and(resource_path("js/Pages/{$composant}.vue"))->toBeReadableFile();
    }
});

/**
 * Chaque page gardée : son composant et le nom de sa route.
 *
 * @return list<array{string, string}>
 */
function pagesPriveesGardees(): array
{
    return array_map(static fn (array $page): array => [$page['composant'], $page['route']], PagesGardees::liste());
}

it('ne garde aucune page que l’issue écarte', function (string $composant, string $nom): void {
    $ecartees = pagesPriveesEcartees();

    expect(array_key_exists($nom, $ecartees))->toBeFalse("La route `{$nom}` ne se garde jamais hors ligne (#2048).")
        ->and(in_array($composant, $ecartees, true))->toBeFalse("Le composant `{$composant}` ne se garde jamais hors ligne (#2048).");
})->with(fn (): array => pagesPriveesGardees());

it('ne garde ni un formulaire ni une page d’invité', function (string $composant, string $nom): void {
    $middleware = Route::getRoutes()->getByName($nom)?->gatherMiddleware() ?? [];

    expect($nom)->not->toMatch('/\.(create|edit)$/')
        ->and($composant)->not->toMatch('#(^Auth/|/(Create|Edit)$)#')
        ->and($middleware)->toContain('auth')
        ->and($middleware)->not->toContain('guest');
})->with(fn (): array => pagesPriveesGardees());
