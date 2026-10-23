import { describe, it, expect } from 'vitest'
import { readFileSync, readdirSync, statSync } from 'node:fs'
import { join, relative, resolve } from 'node:path'

import { importsDe, jsRoot } from './sourceFiles'

/**
 * Une entrée de file ne porte pas de date écrite en dur (#2050, #2123).
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
 * La garde reconnaît la date écrite donnée à `timestamp` directement, sous une
 * clé entre guillemets ou dans du JSON écrit en chaîne, par une affectation, ou
 * par une constante liée à une date écrite dans le même fichier
 * (`const demarree = '2026-…'` puis `timestamp: demarree`, la forme même du
 * test de la coquille). Une constante que le fichier fige
 * (`vi.setSystemTime(MAINTENANT)`) n'en est pas une : une heure qui en est
 * tirée relève de l'heure figée, plus bas. Elle ne suit ni une date importée
 * d'un autre module, ni une date rangée dans un objet.
 *
 * La vraie file se charge par une aide ou par `@/Utils/SyncService`, mais
 * aussi par une page, un layout ou un composable (le layout authentifié
 * l'atteint par `useDeconnexion`) : le texte d'un fichier ne dit pas s'il la
 * charge. La garde lit donc tous les fichiers JS de tests/js, et seuls ceux de
 * `SANS_LA_VRAIE_FILE` gardent leurs dates : ils ne lisent la file que par des
 * fonctions pures, qui n'abandonnent rien, et la garde vérifie que rien de ce
 * qu'ils importent n'atteint `resources/js/Utils/SyncService.js`.
 *
 * Elle ne voit pas l'autre forme du même piège, l'heure figée autour d'un
 * geste (`vi.setSystemTime`) puis rendue avant un vidage : savoir si l'entrée
 * faite sous l'horloge figée attend encore quand la vraie revient dépend du
 * réseau du cas, pas du texte. `.ai/rules/tests-js.md` en donne la borne.
 */

/**
 * Les fichiers qui donnent une heure écrite en dur à `timestamp` sans que la
 * vraie file la lise, et pourquoi : chacun porte encore une telle heure, et
 * rien de ce qu'il importe n'atteint la vraie file (deuxième cas).
 *
 * @type {Array<{ fichier: string, raison: string }>}
 */
const SANS_LA_VRAIE_FILE = [
    {
        fichier: 'utils/demarrageHorsLigneDepuisLeMagasin.test.js',
        raison: 'le démarrage de la coquille lit la séance en file dans le stockage, sans SyncService',
    },
    {
        fichier: 'utils/seanceEnAttente.test.js',
        raison: 'les fonctions de seanceEnAttente.js, lireLaFileDuStockage comprise, lisent les entrées sans en abandonner aucune',
    },
    {
        fichier: 'utils/seanceEtFile.test.js',
        raison: 'les fonctions de seanceEtFile.js lisent les entrées sans les comparer à l’heure',
    },
]

/**
 * Une date écrite en dur : une chaîne qui commence par une date (son
 * guillemet échappé compris, dans du JSON écrit en chaîne), ou une `Date`
 * construite d'une telle chaîne, d'une année écrite, de `Date.UTC`, de
 * `Date.parse` ou d'un nombre de millisecondes.
 */
const DATE_ECRITE =
    /(?:\\?['"`]\d{4}-\d{2}-\d{2}|new Date\(\s*(?:\\?['"`]\d{4}-|\d{4}\s*,|Date\.(?:UTC|parse)\(|\d{12,13}\b))/

/**
 * `timestamp` qui reçoit une valeur : une clé, nue ou entre guillemets (une
 * entrée écrite en JSON comprise), ou une affectation. Une clé nue précédée
 * d'un point (`entree.timestamp : …`, dans un ternaire) n'en est pas une.
 */
const CLE =
    /(?:(?<![\w$.])timestamp\s*:|\\?['"]timestamp\\?['"]\s*:|(?:\.timestamp|\[\s*['"]timestamp['"]\s*\])\s*=(?!=))\s*/

/** Une date écrite donnée directement à `timestamp`. */
const HEURE_ECRITE = new RegExp(CLE.source + DATE_ECRITE.source, 'g')

/** Une constante donnée à `timestamp`, telle quelle ou passée à `new Date` ou `Date.parse`. */
const HEURE_PAR_UNE_CONSTANTE = new RegExp(
    `${CLE.source}(?:new Date\\(\\s*|Date\\.parse\\(\\s*)?(?!new\\b)([A-Za-z_$][\\w$]*)(?![\\w$]|\\s*\\()`,
    'g',
)

/** `{ id, timestamp }` : la constante `timestamp` donnée à la clé du même nom. */
const HEURE_EN_RACCOURCI = /(?<=[{,]\s*)(timestamp)(?=\s*[,}])/g

/**
 * La liaison, dans le même source, d'une constante à une date écrite en dur,
 * ou `null`. Celle que le source fige (`vi.setSystemTime(MAINTENANT)`) n'en
 * est pas une : une heure tirée de l'heure figée relève de l'autre forme du
 * piège, que la garde ne voit pas.
 *
 * @param {string} source
 * @param {string} constante
 * @returns {string | null}
 */
const dateDeLaConstante = (source, constante) => {
    const nom = constante.replaceAll('$', '\\$')
    const liaison = source.match(new RegExp(`\\b(?:const|let|var)\\s+${nom}\\s*=\\s*${DATE_ECRITE.source}`))
    const figee = new RegExp(`\\bsetSystemTime\\(\\s*(?:new Date\\(\\s*)?${nom}(?![\\w$])`).test(source)

    return liaison === null || figee ? null : liaison[0]
}

const racineDesTests = resolve(__dirname, '..')
const racineDuDepot = resolve(racineDesTests, '../..')
const ceFichier = resolve(__dirname, 'entreesDeFileDatees.test.js')

/** Le module de la vraie file : son instance, créée au chargement, abandonne ce qui a plus de trente jours. */
const LA_VRAIE_FILE = join(jsRoot, 'Utils/SyncService.js')

/** Ce que Vitest charge avant chaque fichier de test (`setupFiles` de vitest.config.js). */
const AVANT_CHAQUE_FICHIER = join(racineDuDepot, 'vitest.setup.js')

/** Un import que la marche ne sait pas suivre : `import.meta.glob`, ou `import()` d'autre chose qu'une chaîne. */
const IMPORT_A_L_AVEUGLE = /\bimport\.meta\.glob\b|\bimport\s*\((?!\s*['")])/

const fichiersJs = (dossier) =>
    readdirSync(dossier).flatMap((entree) => {
        const chemin = join(dossier, entree)

        if (statSync(chemin).isDirectory()) {
            return fichiersJs(chemin)
        }

        return chemin.endsWith('.js') && chemin !== ceFichier ? [chemin] : []
    })

/**
 * Les imports par lesquels un fichier de test atteint la vraie file, du
 * fichier au module de la file, ou `null`.
 *
 * Un mock ne coupe rien : `vi.mock` sans fabrique charge le vrai module, et
 * une fabrique peut le charger par `importOriginal`. Un import que la marche
 * ne sait pas suivre compte comme un chemin. Elle peut donc voir la vraie file
 * là où le test ne la charge pas, jamais l'inverse.
 *
 * @param {string} chemin Le fichier de test, en absolu.
 * @param {string} source Son texte.
 * @returns {string[] | null} Les chemins, depuis la racine du dépôt.
 */
const cheminVersLaVraieFile = (chemin, source) => {
    const venuDe = new Map([
        [chemin, null],
        [AVANT_CHAQUE_FICHIER, null],
    ])
    const aVoir = [
        [chemin, source],
        [AVANT_CHAQUE_FICHIER, readFileSync(AVANT_CHAQUE_FICHIER, 'utf8')],
    ]

    while (aVoir.length > 0) {
        const [courant, texte] = aVoir.shift()

        if (courant === LA_VRAIE_FILE || IMPORT_A_L_AVEUGLE.test(texte)) {
            const etapes = []

            for (let etape = courant; etape !== null; etape = venuDe.get(etape)) {
                etapes.unshift(relative(racineDuDepot, etape))
            }

            return etapes
        }

        for (const suivant of importsDe(courant, texte)) {
            if (!venuDe.has(suivant)) {
                venuDe.set(suivant, courant)
                aVoir.push([suivant, readFileSync(suivant, 'utf8')])
            }
        }
    }

    return null
}

/**
 * Les heures écrites en dur qu'un source donne à `timestamp`, directement ou
 * par une constante liée dans le même source, avec leur ligne.
 *
 * @param {string} source
 * @returns {{ligne: number, texte: string}[]}
 */
const heuresEcrites = (source) => {
    const parUneConstante = [...source.matchAll(HEURE_PAR_UNE_CONSTANTE), ...source.matchAll(HEURE_EN_RACCOURCI)]
        .map((correspondance) => ({ correspondance, liaison: dateDeLaConstante(source, correspondance[1]) }))
        .filter(({ liaison }) => liaison !== null)
        .map(({ correspondance, liaison }) => ({
            index: correspondance.index,
            texte: `${correspondance[0]} ← ${liaison}`,
        }))
    const directes = [...source.matchAll(HEURE_ECRITE)].map(({ index, 0: texte }) => ({ index, texte }))

    return [...directes, ...parUneConstante]
        .toSorted((une, autre) => une.index - autre.index)
        .map(({ index, texte }) => ({ ligne: source.slice(0, index).split('\n').length, texte }))
}

/**
 * Les heures écrites en dur d'un fichier de tests/js, hors de ses exceptions.
 *
 * @param {string} chemin
 * @param {string} source
 * @returns {string[]}
 */
const fautifsDe = (chemin, source) => {
    if (SANS_LA_VRAIE_FILE.some(({ fichier }) => join(racineDesTests, fichier) === chemin)) {
        return []
    }

    return heuresEcrites(source).map(
        ({ ligne, texte }) => `tests/js/${relative(racineDesTests, chemin)}:${ligne} ${texte}`,
    )
}

describe('les entrées de file des tests', () => {
    it('ne portent pas de date écrite en dur', () => {
        const fautifs = fichiersJs(racineDesTests).flatMap((chemin) => fautifsDe(chemin, readFileSync(chemin, 'utf8')))

        expect(
            fautifs,
            'la file abandonne une entrée de plus de trente jours : `timestamp: horodatageRecent()` (tests/js/utils/fileHorsLigne.js), ou une heure tirée de celle que le cas a figée',
        ).toEqual([])
    })

    it('ne gardent leurs dates que dans les fichiers qui n’atteignent pas la vraie file', () => {
        const fichiers = fichiersJs(racineDesTests)

        for (const { fichier } of SANS_LA_VRAIE_FILE) {
            const chemin = join(racineDesTests, fichier)

            expect(fichiers, `tests/js/${fichier} n'existe plus : à retirer de SANS_LA_VRAIE_FILE`).toContain(chemin)

            const source = readFileSync(chemin, 'utf8')

            expect(
                heuresEcrites(source).length,
                `tests/js/${fichier} ne porte plus d'heure écrite en dur : à retirer de SANS_LA_VRAIE_FILE`,
            ).toBeGreaterThan(0)
            expect(
                cheminVersLaVraieFile(chemin, source),
                `tests/js/${fichier} atteint la vraie file, qui abandonnera ses heures écrites en dur`,
            ).toBeNull()
        }
    })
})

describe('la garde', () => {
    it('reconnaît une heure écrite en dur, sous chacune de ses formes', () => {
        const formes = [
            "timestamp: '2026-10-19T08:15:00.000Z',",
            'timestamp: "2026-10-19T08:15:00.000Z",',
            'timestamp: `2026-10-19T08:15:00.000Z`,',
            "timestamp: '2026-10-19',",
            "timestamp: new Date('2026-10-19T08:15:00.000Z').toISOString(),",
            'timestamp: new Date(2026, 9, 19).toISOString(),',
            'timestamp: new Date(Date.UTC(2026, 9, 19)).toISOString(),',
            "timestamp: new Date(Date.parse('2026-10-19T08:15:00.000Z')).toISOString(),",
            'timestamp: new Date(1792397700000).toISOString(),',
            "'timestamp': '2026-10-19T08:15:00.000Z',",
            '"timestamp": "2026-10-19T08:15:00.000Z",',
            `localStorage.setItem('offline_sync_queue', '[{"id":"q9","compte":"1","timestamp":"2026-10-19T08:15:00.000Z"}]')`,
            'localStorage.setItem("offline_sync_queue", "[{\\"id\\":\\"q9\\",\\"timestamp\\":\\"2026-10-19T08:15:00.000Z\\"}]")',
            "entree.timestamp = '2026-10-19T08:15:00.000Z'",
            "const demarree = '2026-10-19T08:15:00.000Z'\nconst entree = { id: 'q9', timestamp: demarree }",
            "const DEMARREE = new Date('2026-10-19T08:15:00.000Z')\nconst entree = { timestamp: DEMARREE.toISOString() }",
            "let demarree = '2026-10-19T08:15:00.000Z'\nconst entree = { timestamp: new Date(demarree).toISOString() }",
            "const timestamp = '2026-10-19T08:15:00.000Z'\nconst entree = { id: 'q9', timestamp }",
        ]

        expect(formes.filter((source) => heuresEcrites(source).length !== 1)).toEqual([])
    })

    it('laisse passer une heure tirée de l’horloge, ou ce qui n’est pas une date', () => {
        const formes = [
            'timestamp: horodatageRecent(),',
            'timestamp: new Date().toISOString(),',
            'timestamp: new Date(MAINTENANT.getTime() - ilYA).toISOString(),',
            'timestamp: HEURE_DU_GESTE.toISOString(),',
            "timestamp: 'illisible',",
            "started_at: '2026-10-19T08:15:00.000Z',",
            `'[{"started_at":"2026-10-19T08:15:00.000Z"}]'`,
            "it('takes the day out of a full timestamp', () => {",
            "expect(entree.timestamp).toBe('2026-10-19T08:15:00.000Z')",
            "expect(entree.timestamp === '2026-10-19T08:15:00.000Z').toBe(true)",
            "const quand = attendue ? entree.timestamp : '2026-10-19'",
            "const demarree = horodatageRecent()\nconst entree = { id: 'q9', timestamp: demarree }",
            "const timestamp = horodatageRecent()\nconst entree = { id: 'q9', timestamp }",
            "const { timestamp } = entree\nconst DEBUT = '2026-10-19T08:15:00.000Z'",
            "const MAINTENANT = new Date('2026-10-16T12:00:00.000Z')\nvi.setSystemTime(MAINTENANT)\nconst entree = { timestamp: MAINTENANT.toISOString() }",
            "const MAINTENANT = new Date('2026-10-16T12:00:00.000Z')\nvi.setSystemTime(MAINTENANT)\nconst entree = { timestamp: new Date(MAINTENANT.getTime() - ilYA).toISOString() }",
        ]

        expect(formes.filter((source) => heuresEcrites(source).length > 0)).toEqual([])
    })

    it('suit les imports jusqu’à la vraie file, sans compter un mock comme une coupure', () => {
        const uneSuite = join(racineDesTests, 'pages/uneFausseSuite.test.js')
        const atteint = (source) => cheminVersLaVraieFile(uneSuite, source) !== null

        expect(atteint("import SyncService from '@/Utils/SyncService'"), 'en tête').toBe(true)
        expect(atteint("file = (await import('@/Utils/SyncService')).default"), 'à la demande').toBe(true)
        expect(atteint("import { chargerSyncService } from '../utils/fileHorsLigne'"), 'par une aide').toBe(true)
        expect(atteint("import Notifications from '@/Pages/Notifications/Index.vue'"), 'par une page').toBe(true)
        expect(atteint("import '@/composables/useDeconnexion'"), 'pour son seul effet').toBe(true)
        expect(atteint("export { default } from '@/Utils/SyncService'"), 'réexportée').toBe(true)
        expect(atteint("const vraie = await vi.importActual('@/Utils/SyncService')"), 'réelle').toBe(true)
        expect(
            atteint("vi.mock('@/Utils/SyncService', () => ({}))\nimport SyncService from '@/Utils/SyncService'"),
            'moquée',
        ).toBe(true)
        expect(atteint("const pages = import.meta.glob('@/Pages/**/*.vue')"), 'par un motif').toBe(true)
        expect(atteint('const page = await import(`@/Pages/${nom}.vue`)'), 'par une expression').toBe(true)
        expect(atteint("import { lireLaFileDuStockage } from '@/Utils/seanceEnAttente'"), 'pure').toBe(false)
        expect(atteint("import { describe } from 'vitest'"), 'un paquet').toBe(false)

        const parLeLayout = cheminVersLaVraieFile(uneSuite, "import GuestLayout from '@/Layouts/GuestLayout.vue'")

        expect(parLeLayout.at(0)).toBe('tests/js/pages/uneFausseSuite.test.js')
        expect(parLeLayout.at(1)).toBe('resources/js/Layouts/GuestLayout.vue')
        expect(parLeLayout.at(-1)).toBe('resources/js/Utils/SyncService.js')
    })

    it('regarde tout fichier, qu’il charge la file par une page, un layout, un composable ou pas du tout', () => {
        const entree = "\nconst entree = { id: 'q9', compte: '1', timestamp: '2026-10-19T08:15:00.000Z' }"

        for (const source of [
            "import Notifications from '@/Pages/Notifications/Index.vue'",
            "import GuestLayout from '@/Layouts/GuestLayout.vue'",
            "const { useDeconnexion } = await import('@/composables/useDeconnexion')",
            "import { lireLaFileDuStockage } from '@/Utils/seanceEnAttente'",
        ]) {
            expect(
                fautifsDe(join(racineDesTests, 'pages/uneFausseSuite.test.js'), `${source}${entree}`),
                source,
            ).toEqual(["tests/js/pages/uneFausseSuite.test.js:2 timestamp: '2026-10-19"])
        }

        expect(fautifsDe(join(racineDesTests, SANS_LA_VRAIE_FILE[0].fichier), entree), 'une exception').toEqual([])
    })
})
