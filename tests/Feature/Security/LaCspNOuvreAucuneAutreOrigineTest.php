<?php

declare(strict_types=1);

use App\Models\Admin;
use App\Models\User;
use Illuminate\Http\Request;
use Spatie\Csp\Policy;
use Spatie\Csp\Preset;
use Spatie\Permission\Models\Role;
use Symfony\Component\Finder\Finder;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

/**
 * Aucune directive d'aucune politique CSP ne nomme une autre origine, hors de
 * la liste ci-dessous, où chacune dit pourquoi elle reste.
 *
 * Une source d'une autre origine, c'est une page de l'application qui peut
 * faire charger au navigateur une ressource d'un tiers, à qui partent alors
 * l'adresse IP et le navigateur de qui la lit. Le panneau prenait ainsi
 * l'avatar de l'administrateur chez un service d'avatars, son nom dans
 * l'adresse ; Horizon, Telescope et Pulse leur fonte sur un site de polices ;
 * et Pulse admettait toute image en `https:`. Une source par schéma (`https:`,
 * `wss:`) ou l'étoile admettent toutes les origines : elles comptent aussi.
 * `data:` et `blob:` ne mènent à aucune autre origine.
 *
 * Les politiques sont lues dans `app/Support/Csp/Policies` : une politique
 * ajoutée y est vérifiée sans qu'on ait à la nommer ici.
 */
beforeEach(function (): void {
    config([
        'csp.enabled' => true,
        'csp.nonce_enabled' => true,
        'app.debug' => false,
    ]);
});

/**
 * Les autres origines que la CSP admet encore, par directive, chacune avec sa
 * raison.
 *
 * @return array<string, array<string, string>>
 */
function laCspAutresOriginesAdmises(): array
{
    return [
        'connect-src' => [
            'https://fcm.googleapis.com' => 'Abonnement Web Push : services push des navigateurs (Chrome et Edge, Safari, '
                .'Windows). Le navigateur s’y abonne lui-même, l’application ne fait qu’envoyer, du serveur : leur '
                .'retrait se vérifie sur de vrais appareils abonnés, ce que la CI ne sait pas faire.',
            'https://updates.push.apple.com' => 'Abonnement Web Push, comme ci-dessus.',
            'https://*.notify.windows.com' => 'Abonnement Web Push, comme ci-dessus.',
        ],
    ];
}

/**
 * Celles du serveur de développement de Vite, qui ne sortent qu'en local
 * (`CustomPolicy::configureLocal()`).
 *
 * @return array<string, array<string, string>>
 */
function laCspAutresOriginesDuDeveloppement(): array
{
    $vite = 'Le serveur de développement de Vite, en local seulement.';

    return [
        'script-src' => ['http://localhost:5173' => $vite],
        'style-src' => ['http://localhost:5173' => $vite],
        'connect-src' => ['http://localhost:5173' => $vite, 'ws://localhost:5173' => $vite],
    ];
}

/**
 * Les sources d'une autre origine d'un en-tête CSP, par directive : tout ce
 * qui n'est ni un mot-clef entre apostrophes (`'self'`, nonce, empreinte), ni
 * `data:`, ni `blob:`.
 *
 * @return array<string, list<string>>
 */
function laCspSourcesDUneAutreOrigine(string $entete): array
{
    $autres = [];

    foreach (array_filter(array_map(trim(...), explode(';', $entete)), static fn (string $directive): bool => $directive !== '') as $directive) {
        $sources = explode(' ', (string) preg_replace('/\s+/', ' ', $directive));
        $nom = array_shift($sources);
        $etrangeres = array_values(array_filter(
            $sources,
            static fn (string $source): bool => ! str_starts_with($source, "'") && ! in_array(strtolower($source), ['data:', 'blob:'], true),
        ));

        if ($etrangeres !== []) {
            $autres[$nom] = $etrangeres;
        }
    }

    ksort($autres);

    return $autres;
}

/**
 * Ce qui dépasse de la liste admise.
 *
 * @param  array<string, list<string>>  $trouvees
 * @param  array<string, array<string, string>>  $admises
 * @return array<string, list<string>>
 */
function laCspAutresOriginesNonAdmises(array $trouvees, array $admises): array
{
    $refusees = [];

    foreach ($trouvees as $directive => $sources) {
        $hors = array_values(array_diff($sources, array_keys($admises[$directive] ?? [])));

        if ($hors !== []) {
            $refusees[$directive] = $hors;
        }
    }

    return $refusees;
}

/**
 * Chaque politique de `app/Support/Csp/Policies`.
 *
 * @return list<class-string<Preset>>
 */
function laCspPolitiques(): array
{
    $politiques = [];

    foreach (Finder::create()->files()->in(app_path('Support/Csp/Policies'))->name('*.php') as $fichier) {
        $classe = 'App\\Support\\Csp\\Policies\\'.$fichier->getBasename('.php');

        if (is_subclass_of($classe, Preset::class)) {
            $politiques[] = $classe;
        }
    }

    sort($politiques);

    return $politiques;
}

/**
 * L'en-tête que la politique produit dans un environnement, pour une requête,
 * comme `AddCspHeaders` le compose (directives globales de `config/csp.php`
 * comprises).
 *
 * @param  class-string<Preset>  $politique
 */
function laCspEnTeteDe(string $politique, string $environnement, string $chemin): string
{
    config(['app.env' => $environnement]);
    app()['env'] = $environnement;
    app()->instance('request', Request::create($chemin));

    /** @var array<int, mixed> $directives */
    $directives = config('csp.directives', []);

    return Policy::create(presets: [$politique], directives: $directives)->getContents();
}

it('trouve chaque politique, et sait voir une autre origine', function (): void {
    expect(laCspPolitiques())->toContain(\App\Support\Csp\Policies\CustomPolicy::class, \App\Support\Csp\Policies\PulsePolicy::class)
        ->and(laCspSourcesDUneAutreOrigine("default-src 'self'; img-src 'self' data: blob: https: https://ailleurs.example.org; script-src 'nonce-abc' 'unsafe-eval' *; font-src 'self'"))
        ->toBe(['img-src' => ['https:', 'https://ailleurs.example.org'], 'script-src' => ['*']]);
});

it('ne nomme aucune autre origine hors de la liste admise, en production', function (string $chemin): void {
    $refusees = [];

    foreach (laCspPolitiques() as $politique) {
        $horsListe = laCspAutresOriginesNonAdmises(laCspSourcesDUneAutreOrigine(laCspEnTeteDe($politique, 'production', $chemin)), laCspAutresOriginesAdmises());

        if ($horsListe !== []) {
            $refusees[$politique] = $horsListe;
        }
    }

    expect($refusees)->toBe([]);
})->with(['/', '/backoffice', '/backoffice/pulse', '/horizon']);

it('n’y ajoute en local que le serveur de développement de Vite', function (): void {
    /** @var array<string, array<string, string>> $admises */
    $admises = array_merge_recursive(laCspAutresOriginesAdmises(), laCspAutresOriginesDuDeveloppement());
    $refusees = [];

    foreach (laCspPolitiques() as $politique) {
        $horsListe = laCspAutresOriginesNonAdmises(laCspSourcesDUneAutreOrigine(laCspEnTeteDe($politique, 'local', '/')), $admises);

        if ($horsListe !== []) {
            $refusees[$politique] = $horsListe;
        }
    }

    expect($refusees)->toBe([]);
});

/**
 * La liste ne garde pas une exception que plus rien n'emploie : celle-ci
 * resterait prête à rouvrir l'origine sans que personne ne l'ait décidé.
 */
it('n’admet que des origines que la politique de production nomme encore', function (): void {
    $nommees = [];

    foreach (laCspPolitiques() as $politique) {
        foreach (laCspSourcesDUneAutreOrigine(laCspEnTeteDe($politique, 'production', '/')) as $directive => $sources) {
            $nommees[$directive] = array_values(array_unique([...($nommees[$directive] ?? []), ...$sources]));
        }
    }

    $admises = array_map(array_keys(...), laCspAutresOriginesAdmises());
    ksort($nommees);
    ksort($admises);

    expect($nommees)->toBe($admises);
});

/**
 * Les en-têtes réellement servis, piles de routes comprises : le panneau,
 * Pulse (sa propre politique, posée par sa pile) et Horizon. La suite tourne
 * sous `testing`, que `CustomPolicy` traite comme le poste de développement :
 * le serveur de Vite y est admis.
 */
it('sert des pages dont la CSP ne nomme aucune autre origine hors de la liste admise', function (): void {
    $administrateur = Admin::factory()->create();
    $administrateur->assignRole(Role::findOrCreate('super_admin', 'admin'));
    $administrateur = $administrateur->fresh() ?? $administrateur;
    config(['horizon.allowed_emails' => 'ops@example.org']);
    $horizon = User::factory()->create(['email' => 'ops@example.org']);

    $reponses = [
        '/login' => get('/login'),
        '/backoffice/login' => get('/backoffice/login'),
        '/backoffice' => actingAs($administrateur, 'admin')->get('/backoffice'),
        '/backoffice/pulse' => actingAs($administrateur, 'admin')->get('/backoffice/pulse'),
        '/horizon' => actingAs($horizon)->get('/horizon'),
    ];

    /** @var array<string, array<string, string>> $admises */
    $admises = array_merge_recursive(laCspAutresOriginesAdmises(), laCspAutresOriginesDuDeveloppement());
    $refusees = [];

    foreach ($reponses as $chemin => $reponse) {
        $reponse->assertOk();
        $entete = (string) $reponse->baseResponse->headers->get('Content-Security-Policy');

        expect($entete)->not->toBe('', $chemin.' répond sans CSP.');

        $horsListe = laCspAutresOriginesNonAdmises(laCspSourcesDUneAutreOrigine($entete), $admises);

        if ($horsListe !== []) {
            $refusees[$chemin] = $horsListe;
        }
    }

    expect($refusees)->toBe([]);
});
