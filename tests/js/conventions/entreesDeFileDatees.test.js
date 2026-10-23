import { describe, it, expect } from 'vitest'
import { readFileSync, readdirSync, statSync } from 'node:fs'
import { join, relative, resolve } from 'node:path'

/**
 * Une entrée que la vraie file lit ne porte pas de date écrite en dur (#2050,
 * #2123).
 *
 * La file abandonne ce qui attend depuis plus de trente jours, au chargement
 * et à chaque vidage (`JOURS_EN_FILE_AU_PLUS`,
 * `resources/js/Utils/ecrituresAbandonnees.js`). Une entrée posée avec
 * `timestamp: '2026-10-19T08:15:00.000Z'` passe le jour où on l'écrit et
 * disparaît trente jours plus tard, sans défaut du code : sept cas de
 * `syncService.test.js` sont tombés ainsi (#2050), et la coquille rouverte à
 * l'adresse d'une séance en attente serait tombée le 18 novembre (#2123). Une
 * telle entrée prend `horodatageRecent()` (`tests/js/utils/fileHorsLigne.js`),
 * ou une heure tirée de celle que le cas a figée.
 *
 * La garde ne regarde que les fichiers qui chargent la vraie file : par l'une
 * des aides de `CHARGEURS_DE_LA_FILE`, ou en important `@/Utils/SyncService`
 * sans le moquer. Un fichier qui ne lit la file que par une fonction pure (le
 * démarrage de la coquille, `lireLaFileDuStockage`) n'abandonne rien, et ses
 * dates écrites en dur restent vraies (`demarrageHorsLigneDepuisLeMagasin.test.js`,
 * `seanceEnAttente.test.js`).
 *
 * Elle ne voit pas l'autre forme du même piège, l'heure figée autour d'un
 * geste (`vi.setSystemTime`) puis rendue avant un vidage : savoir si l'entrée
 * faite sous l'horloge figée attend encore quand la vraie revient dépend du
 * réseau du cas, pas du texte. `.ai/rules/tests-js.md` en donne la borne.
 */

/** Les aides qui chargent la vraie file, et le module qui les exporte. */
const CHARGEURS_DE_LA_FILE = [
    { nom: 'chargerSyncService', module: 'utils/fileHorsLigne.js' },
    { nom: 'ouvrirUnAppareil', module: 'utils/fileHorsLigne.js' },
    { nom: 'monterLaSeance', module: 'composables/seanceHorsLigne.js' },
    { nom: 'rechargerQuandLeReseauRevient', module: 'composables/seanceHorsLigne.js' },
    { nom: 'ouvrirLaListeDesSeances', module: 'pages/seanceDansLHistorique.js' },
]

/**
 * Une heure écrite en dur donnée à `timestamp` : une chaîne qui commence par
 * une date, ou une `Date` construite d'une telle chaîne, d'une année écrite ou
 * de `Date.UTC`.
 */
const HEURE_ECRITE =
    /\btimestamp\s*:\s*(?:(['"`])\d{4}-\d{2}-\d{2}|new Date\(\s*(?:['"`]\d{4}-|\d{4}\s*,|Date\.UTC\())/g

const racineDesTests = resolve(__dirname, '..')
const ceFichier = resolve(__dirname, 'entreesDeFileDatees.test.js')

const fichiersJs = (dossier) =>
    readdirSync(dossier).flatMap((entree) => {
        const chemin = join(dossier, entree)

        if (statSync(chemin).isDirectory()) {
            return fichiersJs(chemin)
        }

        return chemin.endsWith('.js') && chemin !== ceFichier ? [chemin] : []
    })

/** Le fichier charge la vraie file, par une aide ou par le module lui-même, non moqué. */
const chargeLaVraieFile = (source) => {
    const parUneAide = CHARGEURS_DE_LA_FILE.some(({ nom }) => new RegExp(`\\b${nom}\\(`).test(source))
    const importeLeModule = /(?:from\s+|import\(\s*)(['"])@\/Utils\/SyncService(?:\.js)?\1/.test(source)
    const leMoque = /vi\.(?:mock|doMock)\(\s*(['"])@\/Utils\/SyncService(?:\.js)?\1/.test(source)

    return parUneAide || (importeLeModule && !leMoque)
}

/**
 * Les heures écrites en dur d'un source, avec leur ligne.
 *
 * @param {string} source
 * @returns {{ligne: number, texte: string}[]}
 */
const heuresEcrites = (source) =>
    [...source.matchAll(HEURE_ECRITE)].map((correspondance) => ({
        ligne: source.slice(0, correspondance.index).split('\n').length,
        texte: correspondance[0],
    }))

describe('les entrées que la vraie file lit', () => {
    it('ne portent pas de date écrite en dur', () => {
        const fautifs = fichiersJs(racineDesTests).flatMap((chemin) => {
            const source = readFileSync(chemin, 'utf8')

            if (!chargeLaVraieFile(source)) {
                return []
            }

            return heuresEcrites(source).map(
                ({ ligne, texte }) => `tests/js/${relative(racineDesTests, chemin)}:${ligne} ${texte}`,
            )
        })

        expect(
            fautifs,
            'la file abandonne une entrée de plus de trente jours : `timestamp: horodatageRecent()` (tests/js/utils/fileHorsLigne.js), ou une heure tirée de celle que le cas a figée',
        ).toEqual([])
    })

    it('connaît chacune des aides qui chargent la vraie file, et trouve des fichiers qui s’en servent', () => {
        const sources = fichiersJs(racineDesTests).map((chemin) => readFileSync(chemin, 'utf8'))

        for (const { nom, module } of CHARGEURS_DE_LA_FILE) {
            expect(
                readFileSync(join(racineDesTests, module), 'utf8'),
                `${nom} n'est plus exporté par tests/js/${module} : la garde ne le reconnaît plus`,
            ).toMatch(new RegExp(`export const ${nom} = `))
        }

        expect(sources.filter(chargeLaVraieFile).length, 'aucun fichier ne charge la vraie file').toBeGreaterThan(10)
    })
})

describe('la garde', () => {
    it('reconnaît une heure écrite en dur, sous chacune de ses formes', () => {
        for (const source of [
            "timestamp: '2026-10-19T08:15:00.000Z',",
            'timestamp: "2026-10-19T08:15:00.000Z",',
            'timestamp: `2026-10-19T08:15:00.000Z`,',
            "timestamp: '2026-10-19',",
            "timestamp: new Date('2026-10-19T08:15:00.000Z').toISOString(),",
            'timestamp: new Date(2026, 9, 19).toISOString(),',
            'timestamp: new Date(Date.UTC(2026, 9, 19)).toISOString(),',
        ]) {
            expect(heuresEcrites(source), source).toHaveLength(1)
        }
    })

    it('laisse passer une heure tirée de l’horloge, ou ce qui n’est pas une date', () => {
        for (const source of [
            'timestamp: horodatageRecent(),',
            'timestamp: new Date().toISOString(),',
            'timestamp: new Date(MAINTENANT.getTime() - ilYA).toISOString(),',
            'timestamp: HEURE_DU_GESTE.toISOString(),',
            "timestamp: 'illisible',",
            "started_at: '2026-10-19T08:15:00.000Z',",
            "it('takes the day out of a full timestamp', () => {",
        ]) {
            expect(heuresEcrites(source), source).toEqual([])
        }
    })

    it('ne regarde que les fichiers qui chargent la vraie file', () => {
        expect(chargeLaVraieFile('file = await chargerSyncService({ compte: 1 })'), 'par une aide').toBe(true)
        expect(chargeLaVraieFile('const { ouvrir } = ouvrirUnAppareil()'), 'deux onglets').toBe(true)
        expect(chargeLaVraieFile("file = (await import('@/Utils/SyncService')).default"), 'à la demande').toBe(true)
        expect(chargeLaVraieFile("import SyncService from '@/Utils/SyncService'"), 'en tête').toBe(true)
        expect(
            chargeLaVraieFile(
                "vi.mock('@/Utils/SyncService', () => ({}))\nimport SyncService from '@/Utils/SyncService'",
            ),
            'moqué',
        ).toBe(false)
        expect(chargeLaVraieFile("import { lireLaFileDuStockage } from '@/Utils/seanceEnAttente'"), 'pure').toBe(false)
        expect(chargeLaVraieFile("import { chargerSyncService } from '../utils/fileHorsLigne'"), 'importée seule').toBe(
            false,
        )
    })
})
