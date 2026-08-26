import { describe, it, expect } from 'vitest'
import { readFileSync } from 'node:fs'

import { collectSourceFiles, jsRoot, visibleText } from './sourceFiles'

/**
 * `<GlassButton …>` and everything up to its closing tag.
 *
 * The attribute part has to swallow quoted strings whole: a Vue binding like
 * `:variant="open ? 'a' : 'b'"` contains a `>` often enough, and a naive
 * `[^>]*` stops inside it and reports the rest of the file as button body.
 */
const glassButtons = /<GlassButton\b(?<attributs>(?:[^>"']|"[^"]*"|'[^']*')*?)(?<autofermant>\/?)>/gs

const attribut = (attributs, nom) => new RegExp(`(?<![\\w:-])${nom}="([^"]*)"`).exec(attributs)?.[1]

/**
 * The variant, and whether it was written as a literal or computed.
 *
 * Three screens make the button switch role: the same control opens the form,
 * then closes it, and reads `:variant="showAddForm ? 'secondary' : 'primary'"`.
 * That is a deliberate two-state control, not an undeclared one — the rules
 * below accept a computed variant that can still resolve to the right value.
 */
const variante = (attributs) => {
    const litterale = attribut(attributs, 'variant')

    return litterale === undefined
        ? { valeur: attribut(attributs, ':variant'), calculee: true }
        : { valeur: litterale, calculee: false }
}

const vaut = (variante, attendue) =>
    variante.valeur !== undefined &&
    (variante.calculee ? variante.valeur.includes(attendue) : variante.valeur === attendue)

/**
 * The visible label, tags and Material Symbols ligatures stripped out.
 *
 * The closing tag has to be found by COUNTING: `Workouts/Show.vue` nests a
 * whole exercise picker inside a button's modal, and taking the first
 * `</GlassButton>` after the opening tag swallowed two hundred lines of that
 * picker as if they were the label.
 */
const libelle = (source, apres) => {
    const balises = /<GlassButton\b(?:[^>"']|"[^"]*"|'[^']*')*?(\/?)>|<\/GlassButton>/gs
    balises.lastIndex = apres

    let profondeur = 0
    let fin = -1

    for (let trouve = balises.exec(source); trouve !== null; trouve = balises.exec(source)) {
        if (trouve[0] === '</GlassButton>') {
            if (profondeur === 0) {
                fin = trouve.index
                break
            }

            profondeur -= 1
        } else if (trouve[1] !== '/') {
            profondeur += 1
        }
    }

    if (fin < 0) {
        return ''
    }

    return visibleText(source.slice(apres, fin))
}

const boutons = () =>
    collectSourceFiles().flatMap((fichier) => {
        const source = readFileSync(fichier, 'utf8')

        return [...source.matchAll(glassButtons)].map((trouve) => ({
            ou: `${fichier.replace(jsRoot, 'resources/js')}:${source.slice(0, trouve.index).split('\n').length}`,
            attributs: trouve.groups.attributs,
            variante: variante(trouve.groups.attributs),
            libelle: trouve.groups.autofermant === '/' ? '' : libelle(source, trouve.index + trouve[0].length),
        }))
    })

/**
 * An affordance that creates something: the `+` of a list screen, and the
 * full-width “add a row” inside a form.
 *
 * Sam reported this one by eye, twice: « le bouton + n'est pas pareil partout »,
 * then « pourquoi ici c'est gradient le + ? ». Both times the answer was the
 * same — the screen had not passed a variant, or had not used GlassButton at
 * all. A guard states the rule once instead.
 */
const creeQuelqueChose = (bouton) =>
    /^(?:\+\s*)?(?:Ajouter|Créer|Nouveau|Nouvelle|Commencer)\b/i.test(bouton.libelle) ||
    /aria-label="(?:Ajouter|Créer|Nouveau|Nouvelle)\b/i.test(bouton.attributs) ||
    />add<|>\s*add\s*</.test(bouton.attributs)

describe('les variantes de bouton', () => {
    /**
     * A submit is the one thing a form exists to do, so it is the page's primary
     * action and says so. Leaving the prop off falls back to `default`, the pale
     * glass variant used for tertiary controls — which is how a « Créer
     * l'objectif » ended up looking like a « Annuler » beside it.
     */
    it('fait déclarer sa variante à chaque bouton de soumission', () => {
        const muets = boutons()
            .filter((bouton) => /type="submit"/.test(bouton.attributs) && bouton.variante.valeur === undefined)
            .map((bouton) => bouton.ou)

        expect(muets).toEqual([])
    })

    /**
     * Refusing and confirming must not look alike. `secondary` sits one clear
     * step below the action it stands next to; `ghost` sits at the same level as
     * plain text, and three screens had put « Annuler » there.
     */
    it('garde « Annuler » en secondary', () => {
        const deviants = boutons()
            .filter((bouton) => bouton.libelle === 'Annuler' && !vaut(bouton.variante, 'secondary'))
            .map((bouton) => `${bouton.ou} (${bouton.variante.valeur ?? 'aucune'})`)

        expect(deviants).toEqual([])
    })

    /** One shape for “create”, on every screen. */
    it('donne la même variante à toutes les créations', () => {
        const deviants = boutons()
            .filter((bouton) => creeQuelqueChose(bouton) && !vaut(bouton.variante, 'primary'))
            .map((bouton) => `${bouton.ou} « ${bouton.libelle} » (${bouton.variante.valeur ?? 'aucune'})`)

        expect(deviants).toEqual([])
    })

    /**
     * A hand-rolled `<button>` wearing the app's accent gradient IS a
     * GlassButton, minus the focus ring, the loading state, the touch target and
     * the ability to follow the charter when it moves. Two of them shipped — the
     * `+` of the library and the `+` of the supplements — and both looked
     * nothing like their own desktop twin sitting two lines below.
     *
     * Only `bg-gradient-main` and the `glass-button-*` utilities are banned
     * here. The accent FILLS are not: a selected filter chip legitimately wears
     * one, and it is not a button in this sense.
     */
    it("n'autorise personne à repeindre un bouton à la main", () => {
        const marqueDeposee = /\b(?:bg-gradient-main|glass-button-(?:primary|neon|gradient-border|secondary|danger))\b/

        const copies = collectSourceFiles()
            .filter((fichier) => !fichier.endsWith('GlassButton.vue'))
            .flatMap((fichier) => {
                const source = readFileSync(fichier, 'utf8')

                return [...source.matchAll(/<button\b(?:[^>"']|"[^"]*"|'[^']*')*?>/gs)]
                    .filter((trouve) => marqueDeposee.test(trouve[0]))
                    .map(
                        (trouve) =>
                            `${fichier.replace(jsRoot, 'resources/js')}:${source.slice(0, trouve.index).split('\n').length}`,
                    )
            })

        expect(copies).toEqual([])
    })
})
