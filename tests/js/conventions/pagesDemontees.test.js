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

/**
 * Les aides qui montent une page pour le test et retiennent elles-mêmes chaque
 * montage, dans le suivi qu'elles exportent : le fichier qui s'en sert n'a
 * rien à retenir, mais ouvre son `afterEach` par leur démontage.
 *
 * Les séances de `monterLaSeance` (`tests/js/composables/seanceHorsLigne.js`)
 * gardaient leurs écouteurs de `window` : ils répondaient au vidage de la file
 * des tests suivants, jusqu'à dix séances d'un même fichier à la fois (#2003).
 */
const AIDES_DE_MONTAGE = [{ module: 'seanceHorsLigne', montage: 'monterLaSeance', suivi: 'seances' }]

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

/** Le fichier importe l'aide et appelle son montage. */
const monteParLAide = (source, { module, montage }) =>
    new RegExp(`from '[^']*/${module}'`).test(source) && source.includes(`${montage}(`)

it('démonte à la fin de chaque test les pages que monte une aide', () => {
    const fautifs = []

    for (const chemin of fichiersDeTest(racineDesTests)) {
        const source = readFileSync(chemin, 'utf8')

        for (const aide of AIDES_DE_MONTAGE) {
            const demonteDAbord = new RegExp(
                `afterEach\\(async \\(\\) => \\{\\s*await ${aide.suivi}\\.demonterToutes\\(\\)`,
            ).test(source)

            if (monteParLAide(source, aide) && !demonteDAbord) {
                fautifs.push(`tests/js/${relative(racineDesTests, chemin)} appelle ${aide.montage}`)
            }
        }
    }

    expect(
        fautifs,
        'ouvrir l’afterEach par le démontage de ce que l’aide a retenu, avant tout autre nettoyage (`await seances.demonterToutes()` pour monterLaSeance)',
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

    for (const aide of AIDES_DE_MONTAGE) {
        expect(
            sources.some((source) => monteParLAide(source, aide)),
            `${aide.montage} : aucun test ne s’en sert, la garde ne vérifie plus rien`,
        ).toBe(true)
    }
})
