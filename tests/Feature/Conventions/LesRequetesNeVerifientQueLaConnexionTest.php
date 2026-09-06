<?php

declare(strict_types=1);

use Illuminate\Foundation\Http\FormRequest;
use Symfony\Component\Finder\Finder;

/*
 * L'autorisation d'une ressource vit au contrôleur ; une requête de validation
 * ne vérifie que la connexion (.ai/rules/requests.md, #1676). Dix-huit
 * requêtes rendaient encore `true` sans regarder l'utilisateur et une n'avait
 * pas d'`authorize()` du tout : rien ne cassait, parce que `Authenticate`
 * passe avant, mais la règle n'était tenue que par l'usage. Les requêtes
 * d'authentification servent des invités et rendent `true` à bon droit.
 *
 * Quatre autres n'étaient plus appelées par personne depuis que l'API v1 ne
 * sert que la page de séance (#1673) : une requête que rien n'appelle est
 * une règle de validation qui ne protège rien.
 */
$requetes = function (): array {
    $classes = [];

    foreach (Finder::create()->files()->in(app_path('Http/Requests'))->name('*.php')->notPath('Concerns') as $fichier) {
        $relatif = str_replace(['/', '.php'], ['\\', ''], $fichier->getRelativePathname());
        $classe = 'App\\Http\\Requests\\'.$relatif;

        if (is_subclass_of($classe, FormRequest::class)) {
            $classes[$classe] = $fichier->getRealPath();
        }
    }

    expect($classes)->not->toBeEmpty('aucune requête trouvée : le test ne prouverait rien');

    return $classes;
};

it('ne laisse aucune requête rendre vrai sans regarder l’utilisateur', function () use ($requetes): void {
    $fautes = [];

    foreach ($requetes() as $classe => $chemin) {
        if (str_starts_with($classe, 'App\\Http\\Requests\\Auth\\')) {
            continue;
        }

        $source = (string) file_get_contents($chemin);

        if (! str_contains($source, 'function authorize(): bool')) {
            $fautes[] = "{$classe} : pas d'authorize(), donc autorisée par défaut";

            continue;
        }

        if (preg_match('/function authorize\(\): bool\s*\{\s*return true;\s*\}/', $source) === 1) {
            $fautes[] = "{$classe} : authorize() rend true sans regarder l'utilisateur";
        }
    }

    expect($fautes)->toBe([], implode("\n  ", $fautes));
});

it('ne garde aucune requête que rien n’appelle', function () use ($requetes): void {
    $sources = [];

    foreach (Finder::create()->files()->in([app_path(), base_path('routes')])->name('*.php') as $fichier) {
        $sources[$fichier->getRealPath()] = (string) file_get_contents($fichier->getRealPath());
    }

    $orphelines = [];

    foreach ($requetes() as $classe => $chemin) {
        $motif = '/\b'.preg_quote($classe, '/').'\b/';
        $citee = array_any(
            $sources,
            fn (string $source, string $cheminDeLaSource): bool => $cheminDeLaSource !== $chemin && preg_match($motif, $source) === 1,
        );

        if (! $citee) {
            $orphelines[] = $classe;
        }
    }

    expect($orphelines)->toBe([], "requêtes que rien n'appelle :\n  ".implode("\n  ", $orphelines));
});
