import { describe, it, expect } from 'vitest'
import { readFileSync } from 'node:fs'

import { collectSourceFiles, jsRoot, visibleText } from './sourceFiles'

/** La charte fixe `--spacing-touch` à 44 px, comme les WCAG 2.5.8 (AAA). */
const CIBLE = 44

/** Le carré d'une ligature Material Symbols à sa taille par défaut. */
const ICONE = 24

/**
 * Le carré que l'icône occupe réellement.
 *
 * Une ligature vaut 24 px ; un `<svg>`, lui, DÉCLARE sa taille — `h-4` en fait
 * un carré de 16. Supposer 24 pour les deux surestimait la cible de chaque
 * bouton SVG, donc sous-estimait le défaut : treize d'entre eux étaient sous la
 * cible, et ce garde n'en voyait aucun.
 */
const icone = (corps) => {
    const svg = /<svg[^>]*class="([^"]*)"/.exec(corps)

    if (svg === null) {
        return ICONE
    }

    const tailles = svg[1]
        .split(/\s+/)
        .map((mot) => /^(?:size|[hw])-(\d+(?:\.5)?|px)$/.exec(mot))
        .filter(Boolean)
        .map((trouve) => echelle(trouve[1]))

    return tailles.length > 0 ? Math.min(...tailles) : ICONE
}

const echelle = (valeur) => (valeur === 'px' ? 1 : Number(valeur) * 4)

/**
 * La plus petite dimension que ce bouton offre au doigt, en pixels.
 *
 * Trois façons de l'obtenir cohabitent dans le dépôt, et elles s'additionnent :
 * une taille explicite (`size-11`), un rembourrage autour de l'icône (`p-2`), et
 * un pseudo-élément qui déborde sans pousser les voisins
 * (`before:-inset-2.5`) — cette dernière étant la seule utilisable quand le
 * bouton doit rester visuellement petit.
 */
const cible = (classes, tailleIcone) => {
    const mots = classes.split(/\s+/)

    const explicite = mots
        .map((mot) => /^(?:size|[hw])-(\d+(?:\.5)?|px)$/.exec(mot))
        .filter(Boolean)
        .map((trouve) => echelle(trouve[1]))

    const rembourrage = mots
        .map((mot) => /^p-(\d+(?:\.5)?|px)$/.exec(mot))
        .filter(Boolean)
        .map((trouve) => echelle(trouve[1]))

    const debord = mots
        .map((mot) => /^before:-inset-(\d+(?:\.5)?|px)$/.exec(mot))
        .filter(Boolean)
        .map((trouve) => echelle(trouve[1]))

    if (mots.includes('min-h-touch') || mots.includes('min-w-touch')) {
        return CIBLE
    }

    const boite = explicite.length > 0 ? Math.min(...explicite) : tailleIcone + 2 * Math.max(0, ...rembourrage, 0)

    return boite + 2 * Math.max(0, ...debord, 0)
}

/** `<button>` dont le contenu visible se réduit à une ligature. */
const boutonsIcone = /<button\b(?<attributs>(?:[^>"']|"[^"]*"|'[^']*')*?)>(?<corps>.*?)<\/button>/gs

/**
 * `GlassButton`, `GlassIconButton` et `GlassInput` DÉFINISSENT l'affordance ;
 * les mesurer reviendrait à mesurer la règle avec elle-même. Leurs tailles
 * passent d'ailleurs par une table indexée sur une prop, que rien ne permet de
 * lire depuis le gabarit.
 */
const primitives = [
    'Components/UI/GlassButton.vue',
    'Components/UI/GlassIconButton.vue',
    'Components/UI/GlassInput.vue',
]

/**
 * Les boutons dont la taille ne se lit PAS dans leurs classes.
 *
 * Deux formes, toutes deux légitimes : celui qui remplit son parent (`h-full`,
 * la trappe de suppression d'une ligne qu'on fait glisser) et celui qui porte un
 * utilitaire nommé de la charte (`glass-nav-fab`, qui vaut 72 px). Les compter à
 * 24 px serait faux ; les compter comme conformes serait un mensonge. Ils sont
 * écartés de la mesure, et cette note est là pour que personne ne croie la
 * mesure exhaustive.
 */
const mesurable = (classes) => !/\b(?:[hw]-full|size-full|inset-0|flex-1|glass-[a-z-]+)\b/.test(classes)

describe('les cibles tactiles', () => {
    /**
     * Une icône sans texte ne dit rien de sa propre taille : rien dans le HTML
     * n'oblige `p-1` à faire 44 px, et sept boutons de ce dépôt tombaient entre
     * 26 et 40. Sur un écran tactile, le geste rate — ou pire, attrape le
     * voisin, qui est parfois « supprimer ».
     *
     * La mesure est faite ici plutôt qu'à l'œil parce que c'est de
     * l'arithmétique, et que personne ne fait cette arithmétique en relisant une
     * diff.
     */
    it('donne 44 px à tout bouton réduit à une icône', () => {
        const trop_petits = collectSourceFiles({ skip: primitives }).flatMap((fichier) => {
            const source = readFileSync(fichier, 'utf8')

            return (
                [...source.matchAll(boutonsIcone)]
                    /*
                     * Deux formes portent une icône dans ce dépôt : la ligature
                     * Material Symbols et le `<svg>` en ligne. Ne filtrer que sur
                     * la première laissait la seconde entièrement hors de portée —
                     * c'est ainsi que treize boutons, dont neuf suppressions et un
                     * à 20 px sur l'écran de séance, tenaient sous un garde vert.
                     */
                    .filter(
                        (trouve) =>
                            (trouve.groups.corps.includes('material-symbols') ||
                                trouve.groups.corps.includes('<svg')) &&
                            /^[a-z_]*$/.test(visibleText(trouve.groups.corps)),
                    )
                    .map((trouve) => ({
                        ou: `${fichier.replace(jsRoot, 'resources/js')}:${source.slice(0, trouve.index).split('\n').length}`,
                        classes: [...trouve.groups.attributs.matchAll(/(?:class|:class)="([^"]*)"/gs)]
                            .map((c) => c[1])
                            .join(' '),
                        icone: icone(trouve.groups.corps),
                    }))
                    .filter((bouton) => mesurable(bouton.classes))
                    .map((bouton) => ({ ...bouton, px: cible(bouton.classes, bouton.icone) }))
                    .filter((bouton) => bouton.px < CIBLE)
                    .map((bouton) => `${bouton.ou} — ${bouton.px} px`)
            )
        })

        expect(trop_petits).toEqual([])
    })
})
