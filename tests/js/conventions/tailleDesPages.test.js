import { it, expect } from 'vitest'
import { readdirSync, readFileSync, statSync } from 'node:fs'
import { join, relative } from 'node:path'
import { jsRoot } from './sourceFiles'

/**
 * Une page qui grossit sans limite finit comme `Workouts/Show.vue` (#1675) :
 * 2 527 lignes et onze responsabilites, eclatees en huit passes. Le plafond
 * vaut pour toute page nouvelle ; celles qui le depassaient le jour de la pose
 * sont en sursis, chacune a son compte du moment, et ne peuvent que descendre.
 */
const PLAFOND = 400

const EN_SURSIS = {
    'Habits/Index.vue': 575,
    'Exercises/Index.vue': 541,
}

const pages = join(jsRoot, 'Pages')

const lister = (dossier) =>
    readdirSync(dossier).flatMap((nom) => {
        const chemin = join(dossier, nom)

        if (statSync(chemin).isDirectory()) return lister(chemin)

        return chemin.endsWith('.vue') ? [chemin] : []
    })

it('ne laisse aucune page dépasser quatre cents lignes, et fait descendre celles en sursis', () => {
    const fautes = []

    for (const chemin of lister(pages)) {
        const page = relative(pages, chemin)
        const lignes = (readFileSync(chemin, 'utf8').match(/\n/g) ?? []).length
        const sursis = EN_SURSIS[page]

        if (sursis === undefined) {
            if (lignes > PLAFOND) fautes.push(`${page} : ${lignes} lignes, au-dessus du plafond de ${PLAFOND}`)
        } else if (lignes > sursis) {
            fautes.push(`${page} : ${lignes} lignes, au-dessus de son sursis de ${sursis}`)
        } else if (lignes <= PLAFOND) {
            fautes.push(`${page} : ${lignes} lignes, sous le plafond — retire-la du sursis`)
        }
    }

    expect(fautes).toEqual([])
})
