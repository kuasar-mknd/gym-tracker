import { describe, it, expect } from 'vitest'
import { readFileSync } from 'node:fs'

import { collectSourceFiles, jsRoot } from './sourceFiles'

/** La charte fixe `--spacing-touch` à 44 px, comme les WCAG 2.5.8 (AAA). */
const CIBLE = 44

/** Le carré d'une ligature Material Symbols à sa taille par défaut. */
const ICONE = 24

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
const cible = (classes) => {
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

    const boite = explicite.length > 0 ? Math.min(...explicite) : ICONE + 2 * Math.max(0, ...rembourrage, 0)

    return boite + 2 * Math.max(0, ...debord, 0)
}

/** `<button>` dont le contenu visible se réduit à une ligature. */
const boutonsIcone = /<button\b(?<attributs>(?:[^>"']|"[^"]*"|'[^']*')*?)>(?<corps>.*?)<\/button>/gs

/**
 * `GlassInput` et `GlassIconButton` DÉFINISSENT l'affordance ; les mesurer
 * reviendrait à mesurer la règle avec elle-même.
 */
const primitives = ['Components/UI/GlassIconButton.vue', 'Components/UI/GlassInput.vue']

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

            return [...source.matchAll(boutonsIcone)]
                .filter((trouve) => {
                    const nu = trouve.groups.corps.replace(/<[^>]*>/gs, '').trim()

                    return trouve.groups.corps.includes('material-symbols') && /^[a-z_]*$/.test(nu)
                })
                .map((trouve) => ({
                    ou: `${fichier.replace(jsRoot, 'resources/js')}:${source.slice(0, trouve.index).split('\n').length}`,
                    px: cible(
                        [...trouve.groups.attributs.matchAll(/(?:class|:class)="([^"]*)"/gs)]
                            .map((c) => c[1])
                            .join(' '),
                    ),
                }))
                .filter((bouton) => bouton.px < CIBLE)
                .map((bouton) => `${bouton.ou} — ${bouton.px} px`)
        })

        expect(trop_petits).toEqual([])
    })
})
