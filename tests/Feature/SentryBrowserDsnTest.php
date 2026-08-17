<?php

declare(strict_types=1);

/*
 * Le DSN du navigateur doit venir de l'execution, jamais du build.
 *
 * Vite substitue `import.meta.env.VITE_*` au moment du `npm run build`. Une
 * variable `VITE_` serait donc cuite dans l'image publiee — et ce depot est
 * public, son image aussi. Toute personne installant l'application enverrait
 * ses erreurs au meme projet Sentry, et en consommerait le quota.
 *
 * C'est le premier correctif de #1444 qui prenait ce chemin. Le garde ci-dessous
 * existe pour que la question ne se repose pas.
 */

it('ne rend aucune configuration Sentry quand aucun DSN n’est fourni', function (): void {
    config(['sentry.dsn_public' => null]);

    $this->get('/login')
        ->assertOk()
        ->assertDontSee('window.SENTRY_CONFIG', escape: false);
});

it('pose le DSN configuré dans la page, à l’exécution', function (): void {
    config(['sentry.dsn_public' => 'https://exemple@o0.ingest.sentry.io/1']);

    $this->get('/login')
        ->assertOk()
        ->assertSee('window.SENTRY_CONFIG', escape: false)
        ->assertSee('o0.ingest.sentry.io', escape: false);
});

/**
 * Et le front doit le lire la, pas ailleurs.
 *
 * Une assertion sur le rendu ne suffirait pas : le bloc pourrait etre pose
 * correctement et main.js continuer de lire une variable de build, ce qui etait
 * exactement l'etat du depot — le bloc etait rendu depuis toujours, et lu par
 * personne.
 */
it('lit le DSN depuis la page et non depuis une variable de build', function (): void {
    $source = file_get_contents(base_path('resources/js/main.js'));

    expect($source)->toBeString();

    $lignes = array_filter(
        explode("\n", (string) $source),
        static fn (string $ligne): bool => str_contains($ligne, 'VITE_SENTRY')
            && preg_match('/^\s*(\*|\/\/|\/\*)/', $ligne) !== 1,
    );

    expect($lignes)->toBe([], sprintf(
        'main.js lit une variable de build pour Sentry. Vite la substituerait au `npm run build`, '
        ."donc le DSN partirait dans l'image publique :\n- %s",
        implode("\n- ", array_map(trim(...), $lignes)),
    ));

    expect($source)->toContain('window.SENTRY_CONFIG');
});
