import { it, expect } from 'vitest'
import { readFileSync } from 'node:fs'
import { collectSourceFiles, jsRoot } from './sourceFiles'

/**
 * Aucune importation de dayjs (#2189).
 *
 * Après #2157, la page du jeûne était la seule à importer dayjs, et n'en
 * gardait que le cœur : deux écarts en secondes et un format d'affichage. Ce
 * cœur pesait environ 7 Kio, recopiés dans son seul morceau. Un écart en
 * secondes se calcule sur deux `Date`
 * (`Math.floor((new Date(fin) - new Date(debut)) / 1000)`), et une date
 * s'écrit par `dateCourte()` et `heureCourte()` de `resources/js/Utils/date.js`,
 * dans le fuseau de l'appareil, comme sur les autres pages.
 *
 * La garde refuse dans resources/js toute importation de dayjs : son cœur, une
 * extension (`dayjs/plugin/…`, `dayjs/esm/…`), une langue (`dayjs/locale/…`)
 * ou une extension tierce (`dayjs-…`), qui l'importe elle-même, en tête, pour
 * son seul effet, à la demande, réexportée ou par `require`. Elle lit le nom
 * du module, pas celui de la variable : un module à soi importé sous le nom
 * `dayjs` passe, et une mention de dayjs hors d'une importation aussi. Un
 * nouveau besoin de date passe par `resources/js/Utils/date.js` ; un retour de
 * dayjs se discute avec la garde, pas à côté d'elle.
 */
const IMPORTATION_DE_DAYJS = /\b(?:from|import|require)\s*\(?\s*(['"`])dayjs(?:[/-][^'"`]*)?\1/g

/**
 * Les importations de dayjs d'un source, avec leur ligne.
 *
 * @param {string} source
 * @returns {{ligne: number, forme: string}[]}
 */
const importationsDe = (source) =>
    [...source.matchAll(IMPORTATION_DE_DAYJS)].map((correspondance) => ({
        ligne: source.slice(0, correspondance.index).split('\n').length,
        forme: correspondance[0],
    }))

it('n’importe dayjs nulle part', () => {
    const fautifs = collectSourceFiles({ extensions: ['.vue', '.js'] }).flatMap((chemin) =>
        importationsDe(readFileSync(chemin, 'utf8')).map(
            ({ ligne, forme }) => `${chemin.replace(jsRoot, 'resources/js')}:${ligne} ${forme}`,
        ),
    )

    expect(fautifs, 'une date passe par resources/js/Utils/date.js').toEqual([])
})

it('reconnaît une importation de dayjs, sous chacune de ses formes', () => {
    for (const source of [
        "import dayjs from 'dayjs'",
        'import dayjs from "dayjs"',
        "import * as dayjs from 'dayjs'",
        'import relativeTime from "dayjs/plugin/relativeTime.js"',
        "import utc from 'dayjs/esm/plugin/utc'",
        "import 'dayjs/locale/fr'",
        "import joursOuvres from 'dayjs-business-days'",
        "const { default: dayjs } = await import('dayjs')",
        'const { default: isoWeek } = await import(`dayjs/plugin/isoWeek`)',
        "export { default } from 'dayjs'",
        "const dayjs = require('dayjs')",
        "import {\n    extend,\n} from 'dayjs'",
    ]) {
        expect(importationsDe(source), source).toHaveLength(1)
    }

    expect(importationsDe("// La page du jeûne.\n\nimport dayjs from 'dayjs'")).toEqual([
        { ligne: 3, forme: "from 'dayjs'" },
    ])
})

it('laisse passer ce qui remplace dayjs, une mention et un module à soi', () => {
    for (const source of [
        "import { dateCourte, heureCourte } from '@/Utils/date'",
        'const secondes = Math.floor((new Date(fin) - new Date(debut)) / 1000)',
        "// Le format de dayjs ('DD/MM/YYYY HH:mm') s'écrit par dateCourte() et heureCourte().",
        "import dayjs from '@/Utils/horloge'",
        'const durees = Array.from(jeunes, (jeune) => jeune.duration)',
    ]) {
        expect(importationsDe(source), source).toEqual([])
    }
})
