<?php

declare(strict_types=1);

use Symfony\Component\Finder\Finder;

/*
 * Un compte ouvert par l'inscription attend la preuve de son adresse, et
 * s'efface s'il ne la reçoit jamais. Les tuiles, le graphique des inscriptions
 * et l'encart des derniers inscrits du tableau de bord le comptaient pourtant
 * comme un compte (#2059).
 *
 * La garde lit chaque widget du panneau (app/Filament/Widgets) : toute requête
 * qui part de `User` y dit quels comptes elle lit, par `ouverts()` ou
 * `enAttente()` dans sa chaîne, au même niveau (une portée écrite dans une
 * fermeture passée en argument ne compte pas) ; et aucun ne lit la table
 * `users` par le constructeur de requêtes (`DB::table('users')`), qui
 * échapperait aux portées. Elle lit le texte : une requête bâtie autrement
 * (une relation, une variable) lui échappe, et ne vaut pas permission.
 */

/**
 * Chaque requête sur les comptes, dans un widget, qui ne dit pas quels comptes
 * elle lit.
 *
 * @return list<string> « ligne : User::methode() » ou « ligne : DB::table('users') »
 */
function widgetsRequetesSurLesComptesSansPortee(string $source): array
{
    $jetons = array_values(array_filter(
        token_get_all($source),
        static fn (array|string $jeton): bool => ! is_array($jeton) || ! in_array($jeton[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true),
    ));
    $texte = static fn (mixed $jeton): string => is_string($jeton) ? $jeton : (is_array($jeton) && is_string($jeton[1] ?? null) ? $jeton[1] : '');
    $fautes = [];

    foreach ($jetons as $i => $jeton) {
        if (! is_array($jeton) || ! in_array($jeton[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
            continue;
        }

        $classe = (string) preg_replace('/^.*\\\\/', '', $jeton[1]);
        $methode = $texte($jetons[$i + 2] ?? '');

        if ($texte($jetons[$i + 1] ?? '') !== '::' || $texte($jetons[$i + 3] ?? '') !== '(') {
            continue;
        }

        if ($classe === 'DB' && $methode === 'table' && in_array($texte($jetons[$i + 4] ?? ''), ["'users'", '"users"'], true)) {
            $fautes[] = "{$jeton[2]} : DB::table('users')";

            continue;
        }

        if ($classe !== 'User') {
            continue;
        }

        $chaine = [$methode];
        $profondeur = 0;
        $j = $i + 3;

        while (isset($jetons[$j])) {
            $courant = $texte($jetons[$j]);

            if (in_array($courant, ['(', '[', '{'], true) || (is_array($jetons[$j]) && in_array($jetons[$j][0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) {
                $profondeur++;
            } elseif (in_array($courant, [')', ']', '}'], true)) {
                $profondeur--;

                if ($profondeur === 0 && $texte($jetons[$j + 1] ?? '') !== '->') {
                    break;
                }
            } elseif ($profondeur === 0 && $courant === '->') {
                $chaine[] = $texte($jetons[$j + 1] ?? '');
            }

            $j++;
        }

        if (array_intersect($chaine, ['ouverts', 'enAttente']) === []) {
            $fautes[] = "{$jeton[2]} : User::{$methode}()";
        }
    }

    return $fautes;
}

it('exige de chaque requête sur les comptes d’un widget du panneau qu’elle dise quels comptes elle lit', function (): void {
    $fautes = [];
    $lus = 0;

    foreach (Finder::create()->files()->in(app_path('Filament/Widgets'))->name('*.php') as $fichier) {
        $lus++;

        foreach (widgetsRequetesSurLesComptesSansPortee($fichier->getContents()) as $faute) {
            $fautes[] = 'app/Filament/Widgets/'.$fichier->getRelativePathname().':'.$faute;
        }
    }

    expect($lus)->toBeGreaterThanOrEqual(3)
        ->and($fautes)->toBe([], 'dites quels comptes la requête lit : User::ouverts() pour compter des comptes, User::enAttente() pour ceux qui attendent le lien');
});

it('repère une requête sur les comptes sans portée, même quand une fermeture en porte une', function (): void {
    $source = <<<'PHP'
        <?php
        return [
            User::count(),
            User::ouverts()->count(),
            User::query()->latest()->limit(10),
            User::query()->enAttente()->count(),
            \App\Models\User::where('created_at', '>=', $debut)->count(),
            User::query()->where(fn ($requete) => $requete->ouverts())->count(),
            User::JOURS_AVANT_LA_PURGE,
            User::class,
            DB::table('users')->count(),
            DB::table('workouts')->count(),
            Workout::query()->count(),
        ];
        PHP;

    expect(widgetsRequetesSurLesComptesSansPortee($source))->toBe([
        '3 : User::count()',
        '5 : User::query()',
        '7 : User::where()',
        '8 : User::query()',
        "11 : DB::table('users')",
    ]);
});
