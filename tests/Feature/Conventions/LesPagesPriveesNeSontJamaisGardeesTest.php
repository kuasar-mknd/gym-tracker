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
 *
 * Une page gardée peut aussi montrer ce que ces pages-là gardent : l'accueil
 * rend le dernier poids, tiré des mesures, et les objectifs en cours. Ces
 * props ne vont dans aucune copie (`PROPS_JAMAIS_GARDEES`, que le magasin
 * retire avant de chiffrer). La garde lit le contrat des pages gardées
 * (`tests/Feature/HorsLigne/contrat-des-pages-gardees.json`, relevé sur les
 * vraies réponses par `ContratDesPagesGardeesTest`, sans ces props), et y
 * refuse toute prop dont un nom dit la santé, le corps, le journal, les
 * objectifs ou les notifications, ou, au premier niveau, un poids : une
 * charge d'entraînement (le poids d'une série, d'un disque, de la barre) se
 * nomme aussi `weight`, plus bas.
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

/**
 * Ce qu'un nom de prop d'une page gardée ne dit jamais, à tout niveau.
 */
const PROPS_PRIVEES_PARTOUT = '/(body|bodies|mesure|measur|goal|objecti|fat|graisse|bmi|imc|health|sant[eé]|sleep|sommeil|journal|habit|supplement|compl[eé]ment|water|hydrat|fasting|je[uû]ne|macro|calori|wilks|notification|mood|humeur)/iu';

/**
 * Ce qu'un nom de prop de premier niveau ne dit pas non plus : un poids, qui
 * plus bas est une charge d'entraînement.
 */
const PROPS_PRIVEES_AU_PREMIER_NIVEAU = '/(weight|poids)/i';

/**
 * Les chemins admis malgré leur nom, et pourquoi.
 *
 * @return array<string, string>
 */
function propsPriveesAdmises(): array
{
    return [
        'auth.user.unread_notifications_count' => 'un nombre, que la barre de navigation montre sur chaque page',
    ];
}

it('ne met dans les copies aucune prop de santé, de mesure ou d’objectif d’une page gardée', function (): void {
    $contrat = json_decode((string) file_get_contents(dirname(__DIR__).'/HorsLigne/contrat-des-pages-gardees.json'), true, flags: JSON_THROW_ON_ERROR);
    expect($contrat)->toBeArray()->toHaveKey('pages');
    assert(is_array($contrat) && is_array($contrat['pages']));

    $jamaisGardees = PagesGardees::propsJamaisGardees();
    $refusees = [];

    foreach ($contrat['pages'] as $page => $forme) {
        assert(is_string($page) && is_array($forme) && is_array($forme['props'] ?? null));
        $composant = explode(' (', $page, 2)[0];

        foreach (array_keys($forme['props']) as $chemin) {
            $chemin = (string) $chemin;
            $segments = array_map(static fn (string $segment): string => (string) preg_replace('/(\[\]|\{\})+$/', '', $segment), explode('.', $chemin));

            if (in_array($segments[0], $jamaisGardees[$composant] ?? [], true)) {
                $refusees[] = "{$page} › {$chemin} : nommée par `PROPS_JAMAIS_GARDEES`, elle n’a rien à faire dans le contrat";

                continue;
            }

            if (array_key_exists($chemin, propsPriveesAdmises())) {
                continue;
            }

            foreach ($segments as $rang => $segment) {
                if (preg_match(PROPS_PRIVEES_PARTOUT, $segment) === 1 || ($rang === 0 && preg_match(PROPS_PRIVEES_AU_PREMIER_NIVEAU, $segment) === 1)) {
                    $refusees[] = "{$page} › {$chemin}";

                    break;
                }
            }
        }
    }

    expect($refusees)->toBe([], "Une page gardée porte ces props, qu’une copie garderait : nomme leur prop de premier niveau dans `PROPS_JAMAIS_GARDEES` (resources/js/Utils/pagesGardees.js), ou, si elles ne disent rien de la santé, admets le chemin, avec sa raison, dans `propsPriveesAdmises()`.\n".implode("\n", $refusees));
});

it('lit, dans le module, les props qu’aucune copie ne garde', function (): void {
    expect(PagesGardees::propsJamaisGardees())->toBe(['Dashboard' => ['latestWeight', 'activeGoals']]);
});
