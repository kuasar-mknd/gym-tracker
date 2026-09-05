import { it, expect } from 'vitest'
import { readdirSync, readFileSync, statSync } from 'node:fs'
import { join, relative } from 'node:path'
import { jsRoot } from './sourceFiles'

/**
 * Une page qui grossit sans limite finit comme `Workouts/Show.vue` (#1675) :
 * 2 527 lignes et onze responsabilites, eclatees en huit passes. Le plafond
 * vaut pour toute page ; les cinq qui le dépassaient le jour de la pose sont
 * descendues une à une la nuit suivante, et il n'y a plus d'exception.
 */
const PLAFOND = 400

const pages = join(jsRoot, 'Pages')

const lister = (dossier) =>
    readdirSync(dossier).flatMap((nom) => {
        const chemin = join(dossier, nom)

        if (statSync(chemin).isDirectory()) return lister(chemin)

        return chemin.endsWith('.vue') ? [chemin] : []
    })

it('ne laisse aucune page dépasser quatre cents lignes', () => {
    const fautes = lister(pages)
        .map((chemin) => [relative(pages, chemin), (readFileSync(chemin, 'utf8').match(/\n/g) ?? []).length])
        .filter(([, lignes]) => lignes > PLAFOND)
        .map(([page, lignes]) => `${page} : ${lignes} lignes, au-dessus du plafond de ${PLAFOND}`)

    expect(fautes).toEqual([])
})
