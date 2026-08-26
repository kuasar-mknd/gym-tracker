<?php

declare(strict_types=1);

/*
 * `text-<jeton>` n'a le droit d'exister que si le jeton se lit.
 *
 * La charte ecrivait deja la regle — « le vert d'etat se pose comme surface ou
 * comme marque, jamais comme couleur de texte » — et un test la constatait meme
 * par le calcul. Mais rien n'empechait un composant de le faire quand meme, et
 * trente-sept l'ont fait : `text-accent-state` a 1,18:1, `text-accent-info` a
 * 1,54:1, `text-accent-warning` a 1,92:1.
 *
 * Une regle qu'aucun test ne rattache au code n'est pas une regle, c'est une
 * note. Celui-ci fait le pont : il releve chaque `text-*` employe dans
 * `resources/`, resout le jeton dans `app.css`, et mesure son contraste sur les
 * trois surfaces de l'application. Ce qui echoue est nomme, avec la mesure.
 *
 * Pourquoi ca ne se voyait pas : ces trois couleurs sont EXCELLENTES en fond. Le
 * vert d'etat rend 15,19:1 avec de l'encre par-dessus. L'oeil qui a valide la
 * pastille verte a valide une pastille juste — et la meme couleur, deux lignes
 * plus loin, servait a ecrire un mot.
 *
 * La reponse n'est pas d'assombrir le jeton : sa vivacite est l'identite de
 * l'application, et Sam y tient. La charte porte donc, pour chacun des trois,
 * une variante `-deep` qui n'existe QUE pour porter du texte.
 */

use Symfony\Component\Finder\Finder;

/**
 * Le seuil AA de WCAG 2.1 pour du texte courant.
 */
const SEUIL_TEXTE = 4.5;

/**
 * Les fonds sur lesquels un texte peut se retrouver.
 *
 * Le type dit `non-empty-list` parce que le calcul en depend : on prend le PIRE
 * des trois contrastes, et `min()` d'une liste vide n'a pas de sens. Le declarer
 * ici evite un repli invente plus bas, qui aurait masque une liste vidée par
 * megarde derriere une mesure plausible.
 *
 * @return non-empty-list<string>
 */
function surfacesDeLApplication(): array
{
    return ['surface-page', 'surface-card', 'surface-sunken'];
}

/**
 * Les jetons qui sont faits pour etre poses SUR du sombre.
 *
 * `text-surface-card` n'est pas « du texte couleur carte », c'est du blanc ecrit
 * par-dessus un fond d'encre — mesurer son contraste sur une carte blanche
 * rendrait 1:1 et signalerait une faute la ou il n'y en a pas. Les surfaces, les
 * traits et les deux jetons `text-on-*` sont dans ce cas.
 *
 * Ce que ce controle ne couvre donc PAS : la lisibilite de ces jetons-la sur le
 * fond sombre qui les porte. C'est `LaCharteTientSesContrastesTest` qui s'en
 * charge, paire par paire, parce qu'il faut connaitre le fond pour la calculer —
 * et un fond, ca ne se lit pas dans un nom de classe.
 */
function estFaitPourUnFondSombre(string $nom): bool
{
    return str_starts_with($nom, 'surface-')
        || str_starts_with($nom, 'border')
        || str_starts_with($nom, 'on-')
        || str_starts_with($nom, 'text-on-')
        || str_starts_with($nom, 'glass-');
}

/**
 * Les jetons employes comme couleur de TEXTE, et ou.
 *
 * @return array<string, list<string>>
 */
function jetonsEcritsEnTexte(): array
{
    $fichiers = Finder::create()
        ->files()
        ->in(resource_path('js'))
        ->name(['*.vue', '*.js']);

    $trouves = [];

    foreach ($fichiers as $fichier) {
        preg_match_all('/\btext-([a-z][a-z0-9-]*)\b(?!\/)/', $fichier->getContents(), $noms);

        foreach ($noms[1] as $nom) {
            $trouves[$nom][] = $fichier->getRelativePathname();
        }
    }

    return array_map(static fn (array $ou): array => array_values(array_unique($ou)), $trouves);
}

it('n ecrit aucun texte dans une couleur qui ne se lit pas', function (): void {
    $illisibles = [];

    foreach (jetonsEcritsEnTexte() as $nom => $fichiers) {
        if (estFaitPourUnFondSombre($nom)) {
            continue;
        }

        try {
            $devant = valeurDuJeton($nom);
        } catch (RuntimeException) {
            // Ni un jeton de couleur ni une taille : `text-xs`, `text-center`.
            continue;
        }

        $pire = min(array_map(
            static fn (string $fond): float => contraste($devant, valeurDuJeton($fond)),
            surfacesDeLApplication()
        ));

        if ($pire < SEUIL_TEXTE) {
            $illisibles[] = sprintf(
                '%s : %.2f:1 sur la surface la moins favorable (%s)',
                $nom,
                $pire,
                implode(', ', array_slice($fichiers, 0, 3)).(count($fichiers) > 3 ? sprintf(' et %d autres', count($fichiers) - 3) : '')
            );
        }
    }

    expect($illisibles)->toBe([], sprintf(
        "Ces jetons servent de couleur de TEXTE et ne s'y lisent pas :\n  %s\n\n"
        ."Ils sont probablement excellents en FOND — c'est pour ca que personne ne les voit passer. "
        ."La charte porte une variante `-deep` pour chacun des trois accents clairs ; c'est elle qui "
        ."porte le texte, pendant que le jeton vif garde les fonds et les marques.\n\n"
        .'Si un nouveau jeton doit porter du texte, declarez sa variante lisible plutot que '
        ."d'assombrir le jeton lui-meme : sa vivacite est l'identite de l'application.",
        implode("\n  ", $illisibles)
    ));
});

it('garde une variante lisible pour chacun des trois accents clairs', function (): void {
    $manquants = [];

    foreach (['accent-state', 'accent-info', 'accent-warning'] as $vif) {
        try {
            $mesure = contraste(valeurDuJeton($vif.'-deep'), valeurDuJeton('surface-card'));
        } catch (RuntimeException) {
            $manquants[] = "{$vif}-deep n'est pas declare";

            continue;
        }

        if ($mesure < SEUIL_TEXTE) {
            $manquants[] = sprintf('%s-deep ne rend que %.2f:1', $vif, $mesure);
        }
    }

    expect($manquants)->toBe([], sprintf(
        "La sortie de secours n'existe plus :\n  %s\n\n"
        .'Sans variante lisible, le prochain qui aura besoin d ecrire un message de confirmation en '
        ."vert n'aura d'autre choix que d'employer le vert vif — et le test precedent le refusera "
        .'sans rien lui proposer, ce qui est la meilleure facon de faire desactiver un garde.',
        implode("\n  ", $manquants)
    ));
});
