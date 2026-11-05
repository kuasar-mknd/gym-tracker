import { it, expect } from 'vitest'
import { readFileSync } from 'node:fs'
import { collectSourceFiles, jsRoot } from './sourceFiles'

/**
 * Aucune extension de dayjs (#2157).
 *
 * La page du jeûne, seule à importer dayjs, installait `relativeTime` et
 * `duration` depuis sa création (#370) sans jamais appeler une de leurs
 * méthodes : 6,1 Kio de code mort dans son morceau, un peu plus du quart. Rien
 * ne le signalait : pour `no-unused-vars`, une extension passée à
 * `dayjs.extend` est utilisée.
 *
 * La garde refuse donc toute extension, importée de `dayjs/plugin/…` ou
 * installée par `dayjs.extend`, plutôt qu'une extension dont aucune méthode
 * n'est appelée. Juger l'usage demanderait la liste des méthodes de chaque
 * extension, et un texte ne la lit pas : le `from` de `relativeTime` ne se
 * distingue pas de `Array.from`, et une extension installée dans un fichier
 * vaut pour tout le document, où un autre fichier peut s'en servir. La page
 * ne garde de dayjs que son cœur (`diff`, `format`), et une date passe par
 * `resources/js/Utils/date.js` (`.ai/rules/js.md`). Une extension qui
 * servirait vraiment se discute avec la garde, pas à côté d'elle.
 */
const EXTENSION_DE_DAYJS = /\bdayjs\/(?:esm\/)?plugin\b(?:\/[\w.-]+)?|\bdayjs\s*\.\s*extend\s*\([^)]*\)?/g

/**
 * Les extensions de dayjs qu'un source importe ou installe, avec leur ligne.
 *
 * @param {string} source
 * @returns {{ligne: number, forme: string}[]}
 */
const extensionsDe = (source) =>
    [...source.matchAll(EXTENSION_DE_DAYJS)].map((correspondance) => ({
        ligne: source.slice(0, correspondance.index).split('\n').length,
        forme: correspondance[0],
    }))

it('n’importe ni n’installe aucune extension de dayjs', () => {
    const fautifs = collectSourceFiles({ extensions: ['.vue', '.js'] }).flatMap((chemin) =>
        extensionsDe(readFileSync(chemin, 'utf8')).map(
            ({ ligne, forme }) => `${chemin.replace(jsRoot, 'resources/js')}:${ligne} ${forme}`,
        ),
    )

    expect(fautifs, 'le cœur de dayjs suffit à la page ; une date passe par resources/js/Utils/date.js').toEqual([])
})

it('reconnaît une extension importée ou installée, sous chacune de ses formes', () => {
    for (const source of [
        "import relativeTime from 'dayjs/plugin/relativeTime'",
        'import duration from "dayjs/plugin/duration.js"',
        "import utc from 'dayjs/esm/plugin/utc'",
        "const { default: isoWeek } = await import('dayjs/plugin/isoWeek')",
        'dayjs.extend(relativeTime)',
        'dayjs . extend(duration)',
    ]) {
        expect(extensionsDe(source), source).toHaveLength(1)
    }
})

it('laisse passer le cœur de dayjs', () => {
    for (const source of [
        "import dayjs from 'dayjs'",
        "dayjs(date).format('DD/MM/YYYY HH:mm')",
        "dayjs(end).diff(dayjs(start), 'second')",
        'const durees = Array.from(jeunes, (jeune) => jeune.duration)',
    ]) {
        expect(extensionsDe(source), source).toEqual([])
    }
})
