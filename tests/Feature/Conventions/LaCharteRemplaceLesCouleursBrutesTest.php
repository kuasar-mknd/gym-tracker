<?php

declare(strict_types=1);

/*
 * Un composant nomme un ROLE, jamais une couleur.
 *
 * C'est la regle qui manquait, et son absence a coute le mode sombre. Une meme
 * couleur s'ecrivait de quatre facons — `electric-orange`, `accent-primary`,
 * `#ff5500` en dur, ou `orange-500` de Tailwind — et le choix entre elles etait
 * un hasard d'ecriture. Mesure avant la charte : 781 couleurs ecrites en brut —
 * 247 classes Tailwind, 340 hexadecimaux et 194 `rgba()` — reparties sur 69
 * fichiers.
 *
 * Ce test a d'abord ete un CLIQUET, un plafond qui ne descendait qu'a chaque lot
 * converti, parce qu'il y avait 49 fichiers a reprendre et qu'un garde exigeant
 * tout d'un coup n'aurait jamais ete pose. Le plafond est arrive a zero le
 * 2026-08-26 : c'est desormais l'interdiction seche qu'il prefigurait.
 *
 * Ce que la derniere vague a demande, et qui explique pourquoi elle n'etait pas
 * qu'un remplacement mecanique :
 *
 *  - 160 des 340 hexadecimaux ne decrivaient aucune donnee. C'etaient les
 *    infobulles et les axes, redecrits dans chacun des 47 graphiques faute d'un
 *    endroit ou les poser une fois ;
 *  - convertir une PALETTE vers des roles la detruit. Les seize couleurs
 *    d'habitude se sont repliees sur six, et le dictionnaire de leurs noms s'est
 *    retrouve avec des cles en double dont seule la derniere survivait ;
 *  - le code couleur des disques de fonte est une norme exterieure. Un disque de
 *    25 kg n'est pas rouge parce que cette application signale un danger ;
 *  - `bg-red-50 text-red-600` est un COUPLE lavis/texte, pas un role ecrit deux
 *    fois. Replie sur un seul jeton, il donne du rouge sur du rouge — 1:1.
 */

use Symfony\Component\Finder\Finder;

/**
 * Combien de couleurs brutes un texte contient.
 *
 * « Brute » veut dire : une teinte de la palette Tailwind ou un litteral, par
 * opposition a un jeton de la charte. `bg-slate-800` en est une,
 * `bg-surface-card` n'en est pas.
 */
function couleursBrutesDansLeTexte(string $contenu): int
{
    $familles = 'bg|text|border|from|to|via|ring|shadow|divide|placeholder|fill|stroke|accent|caret|outline|decoration';
    $palette = 'slate|gray|zinc|neutral|stone|red|orange|amber|yellow|lime|green|emerald|teal|cyan|sky|blue|indigo|violet|purple|fuchsia|pink|rose';

    $compte = 0;

    preg_match_all('/(?::)?class="([^"]*)"/s', $contenu, $attributs);

    foreach ($attributs[1] as $attribut) {
        $compteDeLAttribut = preg_match_all(
            '/\\b(?:'.$familles.')-(?:(?:'.$palette.')-\\d{2,3}|white|black)\\b/',
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

    return $compte;
}

/**
 * Les utilitaires de couleur brute, par fichier.
 *
 * @return array<string, int>
 */
function couleursBrutesParFichier(): array
{
    $fichiers = Finder::create()
        ->files()
        ->in(resource_path('js'))
        ->name('*.vue');

    $trouves = [];

    foreach ($fichiers as $fichier) {
        $compte = couleursBrutesDansLeTexte($fichier->getContents());

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
     * Zero, et il n'y a plus de raison qu'il remonte : la charte porte desormais
     * un jeton pour chaque usage rencontre, y compris les trois groupes qui n'en
     * avaient pas — la palette de l'utilisateur, les disques de fonte, et les
     * deux noirs qui ne sont pas de l'encre.
     *
     * Ancien commentaire, garde pour la regle qu'il enonce. A BAISSER a chaque lot, sur le compte reel rendu par ce test. Un plafond
     * laisse au-dessus du reel laisse re-rentrer ce qu'on vient de sortir : le
     * baseline PHPStan a deja appris cette lecon a ce depot.
     */
    $plafond = 0;

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

it('sait encore reconnaitre une couleur brute', function (): void {
    /*
     * Ce controle regardait autrefois si le depot en contenait encore, et
     * s'inquietait de n'en trouver aucune : tant que la conversion durait, un
     * balayage casse et une conversion finie rendaient le meme resultat — zero —
     * et il fallait bien distinguer les deux.
     *
     * La conversion est finie, alors la question change de forme. Ce n'est plus
     * « en reste-t-il ? » mais « saurais-tu en voir une ? ». On lui en montre
     * donc une, et une seule, sur un echantillon ecrit ici : le jour ou le motif
     * cesse de mordre, ce test tombe, pendant que le compteur reste a zero pour
     * une raison qui n'aurait rien de rassurant.
     */
    $echantillon = <<<'VUE'
        <template>
            <div class="bg-slate-800 text-white">
                <span :class="actif ? 'text-emerald-500' : 'text-surface-card'">ok</span>
            </div>
        </template>
        VUE;

    $trouves = couleursBrutesDansLeTexte($echantillon);

    expect($trouves)->toBe(3, sprintf(
        'Le balayage a compte %d couleurs brutes dans un echantillon qui en porte exactement trois : '
        .'`bg-slate-800` et `text-white` dans un attribut fixe, `text-emerald-500` dans une liaison '
        ."dynamique — celle-la compte, c'est tout l'interet du `(?::)?` du motif. `text-surface-card` "
        ."n'en est pas une, puisque c'est un jeton.\n\n"
        .'Un motif qui cesserait de mordre rendrait zero sur tout le depot, et le compteur resterait '
        .'a zero pour une raison qui n\'aurait rien de rassurant.',
        $trouves
    ));
});
