<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Throwable;

/**
 * La charte graphique, lue depuis PHP.
 *
 * Trois endroits rendent des couleurs sans pouvoir lire une variable CSS :
 *
 *  - les COURRIELS, parce qu'aucun client de messagerie ne resout
 *    `var(--color-…)` — Outlook et Gmail les ignorent, et le texte tombe alors
 *    sur la couleur par defaut du client, souvent du noir sur du noir ;
 *  - les pages d'ERREUR, servies quand le manifeste Vite peut etre absent ;
 *  - les widgets FILAMENT, dont la configuration part en JSON vers Chart.js.
 *
 * Chacun de ces trois avait donc sa propre copie des couleurs, ecrite a la main.
 * Le widget d'activite portait `#8b5cf6` avec un commentaire « Violet » pour un
 * violet de charte qui vaut `#8800ff` : deux violets sous un nom, la meme panne
 * que du cote JavaScript.
 *
 * Cette classe lit `app.css` et rend le litteral. Le rendu final contient bien
 * une valeur — c'est inevitable pour un courriel — mais elle n'est ecrite qu'a
 * UN endroit, et changer la charte change les courriels.
 *
 * @see \App\Support\Charte::jeton()
 */
final class Charte
{
    /**
     * La valeur d'un jeton, par son nom sans le prefixe `--color-`.
     *
     * @throws RuntimeException si le jeton n'est pas declare
     */
    public static function jeton(string $nom): string
    {
        $jetons = self::tous();

        if (! array_key_exists($nom, $jetons)) {
            throw new RuntimeException(
                "Le jeton `--color-{$nom}` n'est pas declare dans la charte. ".
                'Les noms disponibles : '.implode(', ', array_keys($jetons))
            );
        }

        return $jetons[$nom];
    }

    /**
     * Tous les jetons de couleur declares, resolus.
     *
     * Le resultat est mis en cache : la feuille fait plus de mille lignes et un
     * courriel peut demander une dizaine de couleurs. En developpement le cache
     * est contourne, sinon une retouche de la charte ne se verrait qu'apres un
     * vidage — exactement le genre de friction qui fait recopier une valeur
     * « juste pour essayer ».
     *
     * @return array<string, string>
     */
    public static function tous(): array
    {
        if (app()->environment('local', 'testing')) {
            return self::lire();
        }

        /*
         * Le cache est une COMMODITE, jamais une dependance.
         *
         * Le premier lecteur de cette classe est la page d'erreur 500 — donc la
         * page qui s'affiche precisement quand quelque chose vient de tomber, et
         * ce quelque chose est souvent la base ou Redis, ou vit le cache. Un
         * `Cache::rememberForever()` nu y aurait leve une seconde exception
         * pendant le rendu de la premiere, et l'utilisateur aurait recu une page
         * blanche a la place du message.
         *
         * On relit donc la feuille quand le cache est indisponible. C'est plus
         * lent, et c'est exactement le moment ou la lenteur n'a aucune
         * importance.
         */
        try {
            /** @var array<string, string> */
            return Cache::rememberForever('charte.jetons', static fn (): array => self::lire());
        } catch (Throwable) {
            return self::lire();
        }
    }

    /**
     * @return array<string, string>
     */
    private static function lire(): array
    {
        $chemin = resource_path('css/app.css');
        $css = @file_get_contents($chemin);

        if ($css === false) {
            throw new RuntimeException("La charte est introuvable : {$chemin}");
        }

        preg_match_all('/--color-([a-z0-9-]+):\s*([^;]+);/', $css, $trouves, PREG_SET_ORDER);

        $bruts = [];

        foreach ($trouves as $trouve) {
            $bruts[$trouve[1]] = trim($trouve[2]);
        }

        return array_map(static fn (string $valeur): string => self::resoudre($valeur, $bruts), $bruts);
    }

    /**
     * Suit les alias — `var(--color-autre)` — jusqu'a une valeur pleine.
     *
     * La profondeur est bornee : un alias circulaire boucle sinon a l'infini, et
     * il vaut mieux rendre la reference telle quelle qu'epuiser la memoire pour
     * un courriel.
     *
     * @param  array<string, string>  $bruts
     */
    private static function resoudre(string $valeur, array $bruts): string
    {
        for ($saut = 0; $saut < 5; $saut++) {
            if (preg_match('/^var\(--color-([a-z0-9-]+)\)$/', $valeur, $alias) !== 1) {
                return $valeur;
            }

            if (! array_key_exists($alias[1], $bruts)) {
                return $valeur;
            }

            $valeur = $bruts[$alias[1]];
        }

        return $valeur;
    }
}
