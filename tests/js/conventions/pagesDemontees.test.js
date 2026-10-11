import { it, expect } from 'vitest'
import { readFileSync, readdirSync, statSync } from 'node:fs'
import { join, relative, resolve } from 'node:path'

/**
 * Les pages qui, laissées montées, écrivent encore après leur test.
 *
 * `Workouts/Show` envoie une valeur tapée une seconde plus tard, et ses
 * écouteurs de `window` répondent aux évènements de file des tests suivants.
 * Le PATCH d'un test terminé tombait ainsi dans un autre, sur le même mock de
 * `SyncService` : « refuses to move a set past either end » échouait selon la
 * vitesse de la machine et l'ordre des tests (#2003).
 */
const PAGES_A_DEMONTER = ['@/Pages/Workouts/Show.vue']

const racineDesTests = resolve(__dirname, '..')

const fichiersDeTest = (dossier) =>
    readdirSync(dossier).flatMap((entree) => {
        const chemin = join(dossier, entree)

        if (statSync(chemin).isDirectory()) {
            return fichiersDeTest(chemin)
        }

        return chemin.endsWith('.test.js') ? [chemin] : []
    })

it('démonte à la fin de chaque test les pages qui écrivent encore après lui', () => {
    const fautifs = []

    for (const chemin of fichiersDeTest(racineDesTests)) {
        const source = readFileSync(chemin, 'utf8')
        const pages = PAGES_A_DEMONTER.filter((page) => source.includes(`from '${page}'`))

        if (pages.length === 0) {
            continue
        }

        const retient = /\.retenir\(/.test(source)
        const demonteDAbord = /afterEach\(async \(\) => \{\s*await \w+\.demonterToutes\(\)/.test(source)

        if (!retient || !demonteDAbord) {
            fautifs.push(`tests/js/${relative(racineDesTests, chemin)} monte ${pages.join(', ')}`)
        }
    }

    expect(
        fautifs,
        'suivreLesPagesMontees (tests/js/pages/pagesMontees.js) : retenir chaque page montée, et ouvrir l’afterEach par `await pages.demonterToutes()`',
    ).toEqual([])
})

it('trouve au moins un fichier qui monte chacune de ces pages', () => {
    const sources = fichiersDeTest(racineDesTests).map((chemin) => readFileSync(chemin, 'utf8'))

    for (const page of PAGES_A_DEMONTER) {
        expect(
            sources.some((source) => source.includes(`from '${page}'`)),
            `${page} : aucun test ne la monte, la garde ne vérifie plus rien`,
        ).toBe(true)
    }
})
