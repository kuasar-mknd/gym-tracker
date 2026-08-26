<?php

declare(strict_types=1);

/*
 * Un composant nomme un ROLE, jamais une couleur.
 *
 * C'est la regle qui manquait, et son absence a coute le mode sombre. Une meme
 * couleur s'ecrivait de quatre facons — `electric-orange`, `accent-primary`,
 * `#ff5500` en dur, ou `orange-500` de Tailwind — et le choix entre elles etait
 * un hasard d'ecriture. Mesure avant la charte : 700 usages de couleurs brutes dans 69 fichiers
 * en 56 teintes, dont 764 sans aucun jeton portant leur valeur.
 *
 * Un cliquet plutot qu'une interdiction seche, pour la meme raison que le
 * baseline PHPStan : il y a 49 fichiers a convertir, ca ne tient pas dans une
 * revue, et un garde qui exigerait tout d'un coup ne serait jamais pose. Le
 * plafond descend a chaque lot converti et ne remonte que par une decision
 * visible.
 *
 * Le jour ou il atteint zero, ce test devient l'interdiction seche qu'il
 * prefigure — et les alias d'anciens noms d'`app.css` peuvent partir.
 */

use Symfony\Component\Finder\Finder;

/**
 * Les utilitaires de couleur brute, par fichier.
 *
 * « Brute » veut dire : une teinte de la palette Tailwind ou un litteral, par
 * opposition a un jeton de la charte. `bg-slate-800` en est une, `bg-surface-card`
 * n'en est pas.
 *
 * @return array<string, int>
 */
function couleursBrutesParFichier(): array
{
    $familles = 'bg|text|border|from|to|via|ring|shadow|divide|placeholder|fill|stroke|accent|caret|outline|decoration';
    $palette = 'slate|gray|zinc|neutral|stone|red|orange|amber|yellow|lime|green|emerald|teal|cyan|sky|blue|indigo|violet|purple|fuchsia|pink|rose';

    $fichiers = Finder::create()
        ->files()
        ->in(resource_path('js'))
        ->name('*.vue');

    $trouves = [];

    foreach ($fichiers as $fichier) {
        $contenu = $fichier->getContents();
        $compte = 0;

        // Chaque attribut de classe, y compris les liaisons dynamiques.
        preg_match_all('/(?::)?class="([^"]*)"/s', $contenu, $attributs);

        foreach ($attributs[1] as $attribut) {
            $compteDeLAttribut = preg_match_all(
                '/\b(?:'.$familles.')-(?:(?:'.$palette.')-\d{2,3}|white|black)\b/',
                $attribut
            );

            // `preg_match_all` rend `false` sur motif invalide. Le compter comme
            // zero masquerait un balayage casse derriere un chiffre qui baisse,
            // ce qui est exactement ce qu'un cliquet ne doit jamais faire.
            if ($compteDeLAttribut === false) {
                throw new RuntimeException('Le motif de balayage des couleurs brutes est invalide.');
            }

            $compte += $compteDeLAttribut;
        }

        if ($compte > 0) {
            $trouves[$fichier->getRelativePathname()] = $compte;
        }
    }

    arsort($trouves);

    return $trouves;
}

it('ne laisse pas remonter le nombre de couleurs brutes', function (): void {
    $parFichier = couleursBrutesParFichier();
    $total = array_sum($parFichier);

    /*
     * PLAFOND — releve le 2026-08-26, avant toute conversion.
     *
     * A BAISSER a chaque lot, sur le compte reel rendu par ce test. Un plafond
     * laisse au-dessus du reel laisse re-rentrer ce qu'on vient de sortir : le
     * baseline PHPStan a deja appris cette lecon a ce depot.
     */
    $plafond = 700;

    $tete = array_slice($parFichier, 0, 6, true);
    $lignes = [];

    foreach ($tete as $fichier => $compte) {
        $lignes[] = sprintf('%4d  %s', $compte, $fichier);
    }

    expect($total)->toBeLessThanOrEqual($plafond, sprintf(
        "%d couleurs brutes dans %d fichiers, contre un plafond de %d.\n\n"
        .'Un composant nomme un role — `bg-surface-card`, `text-text-muted` — jamais une teinte. '
        ."Les teintes vivent dans le bloc `@theme` d'`app.css`, et nulle part ailleurs.\n\n"
        ."Les plus charges :\n%s",
        $total,
        count($parFichier),
        $plafond,
        implode("\n", $lignes)
    ));
});

it('compte quelque chose, sinon il ne prouve rien', function (): void {
    expect(couleursBrutesParFichier())->not->toBeEmpty(
        'aucune couleur brute trouvee : soit la conversion est finie — auquel cas ce test doit devenir '
        .'une interdiction seche — soit le balayage ne lit plus les fichiers.'
    );
});
