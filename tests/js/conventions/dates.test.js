import { it, expect } from 'vitest'
import { readFileSync } from 'node:fs'
import { collectSourceFiles, jsRoot } from './sourceFiles'

/**
 * Une date sans langue suit celle du navigateur : l'échéance d'un objectif au
 * 4 octobre s'écrivait « 10/4/2026 » sur un téléphone réglé en anglais, et
 * l'heure d'un verre d'eau « 02:30 PM » (#1976). Seize appels écrivaient
 * `'fr-FR'`, six l'oubliaient, et la garde des nombres (`nombres.test.js`) ne
 * regardait que `toFixed(` et `toLocaleString(`.
 *
 * Toute mise en forme de date passe donc par `'fr-FR'`, écrit en toutes
 * lettres ou par `LANGUE_DES_DATES` de `Utils/date.js`, dont `dateCourte()`,
 * `dateAvecJour()` et `heureCourte()` sont les formes courantes.
 */
const APPEL_DE_DATE = /(?:\.toLocaleDateString|\.toLocaleTimeString|Intl\.DateTimeFormat)\(([^)]*)/g
const LANGUE_EXPLICITE = /^\s*(?:'fr-FR'|"fr-FR"|LANGUE_DES_DATES)\s*(?:,|$)/

/**
 * Les appels d'un source qui ne donnent pas la langue, avec leur ligne.
 *
 * @param {string} source
 * @returns {{ligne: number, appel: string}[]}
 */
const appelsSansLangue = (source) =>
    [...source.matchAll(APPEL_DE_DATE)]
        .filter(([, argumentsDeLAppel]) => !LANGUE_EXPLICITE.test(argumentsDeLAppel))
        .map((correspondance) => ({
            ligne: source.slice(0, correspondance.index).split('\n').length,
            appel: correspondance[0].trim(),
        }))

it('ne met en forme une date qu’en fr-FR', () => {
    const fautifs = collectSourceFiles({ extensions: ['.vue', '.js'] }).flatMap((chemin) =>
        appelsSansLangue(readFileSync(chemin, 'utf8')).map(
            ({ ligne, appel }) => `${chemin.replace(jsRoot, 'resources/js')}:${ligne} ${appel}`,
        ),
    )

    expect(fautifs, "passer par dateCourte(), dateAvecJour(), heureCourte() ou 'fr-FR'").toEqual([])
})

it('reconnaît les formes qu’elle existe pour refuser', () => {
    for (const source of [
        'parseCalendarDate(goal.deadline)?.toLocaleDateString()',
        "new Date(m).toLocaleDateString(undefined, { weekday: 'short' })",
        "new Date(log.consumed_at).toLocaleTimeString([], { hour: '2-digit' })",
        "date.toLocaleDateString('en-US')",
        'new Intl.DateTimeFormat().format(date)',
    ]) {
        expect(appelsSansLangue(source), source).toHaveLength(1)
    }
})

it('laisse passer une langue explicite', () => {
    for (const source of [
        "date.toLocaleDateString('fr-FR')",
        "date.toLocaleDateString('fr-FR', { day: '2-digit' })",
        "date.toLocaleTimeString(LANGUE_DES_DATES, { hour: '2-digit' })",
        "new Intl.DateTimeFormat('fr-FR', { month: 'long' })",
    ]) {
        expect(appelsSansLangue(source), source).toEqual([])
    }
})
