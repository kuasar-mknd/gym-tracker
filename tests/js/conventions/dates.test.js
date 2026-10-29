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

/**
 * Une heure qui part au serveur porte son décalage (#2111).
 *
 * La page du jeûne envoyait `dayjs().format('YYYY-MM-DDTHH:mm')` et
 * `dayjs().format('YYYY-MM-DD HH:mm:ss')` : une heure murale de l'appareil, sans
 * fuseau, que le serveur lit comme une heure de l'application. Un appareil à
 * Toronto démarrait son jeûne trop tôt de l'écart entre les deux fuseaux : cinq
 * heures le 10 mars 2026, six la plus grande partie de l'année. Un champ
 * `datetime-local` part par `formatToUTC()` de `Utils/date.js`, l'instant
 * présent par `toISOString()`.
 *
 * La garde refuse un format qui écrit le jour puis l'heure dans la forme du
 * serveur, séparés par `T` ou par une espace, entre crochets ou non (dayjs
 * écrit tel quel ce qu'ils entourent), sans jeton `Z` hors des crochets : `Z`
 * et `ZZ` écrivent le décalage, que le serveur ramène. L'affichage
 * (`DD/MM/YYYY HH:mm`) et le jour seul (`YYYY-MM-DD`) restent permis : l'un ne
 * part nulle part, l'autre n'est pas un instant. Elle ne voit pas un champ
 * envoyé tel quel, sans `formatToUTC()` : seul le test de la page en témoigne.
 */
const HEURE_MURALE_A_ENVOYER = /\.format\(\s*(['"`])YYYY-MM-DD(?:T|\[T\]| |\[ \])HH(?:\[[^\]]*\]|(?!\1)[^Z[])*\1/g

/**
 * @param {string} source
 * @returns {{ligne: number, appel: string}[]}
 */
const heuresMuralesAEnvoyer = (source) =>
    [...source.matchAll(HEURE_MURALE_A_ENVOYER)].map((correspondance) => ({
        ligne: source.slice(0, correspondance.index).split('\n').length,
        appel: correspondance[0].trim(),
    }))

it('n’envoie aucune heure murale sans décalage', () => {
    const fautifs = collectSourceFiles({ extensions: ['.vue', '.js'] }).flatMap((chemin) =>
        heuresMuralesAEnvoyer(readFileSync(chemin, 'utf8')).map(
            ({ ligne, appel }) => `${chemin.replace(jsRoot, 'resources/js')}:${ligne} ${appel}`,
        ),
    )

    expect(fautifs, 'passer par formatToUTC() pour un champ, toISOString() pour l’instant présent').toEqual([])
})

it('reconnaît les heures murales qu’elle existe pour refuser, et laisse l’affichage, le jour et une heure qui porte son décalage', () => {
    for (const source of [
        "dayjs().format('YYYY-MM-DDTHH:mm')",
        "dayjs().format('YYYY-MM-DD HH:mm:ss')",
        'dayjs(debut).format("YYYY-MM-DD HH:mm")',
        'dayjs().format(`YYYY-MM-DDTHH:mm`)',
        // Les crochets de dayjs écrivent leur contenu tel quel : la même heure murale.
        "dayjs().format('YYYY-MM-DD[T]HH:mm')",
        "dayjs().format('YYYY-MM-DD[ ]HH:mm:ss')",
        // Un « Z » entre crochets n'est qu'une lettre, posée sur l'heure de l'appareil.
        "dayjs().format('YYYY-MM-DDTHH:mm:ss[Z]')",
    ]) {
        expect(heuresMuralesAEnvoyer(source), source).toHaveLength(1)
    }

    for (const source of [
        "dayjs(date).format('DD/MM/YYYY HH:mm')",
        "dayjs().format('YYYY-MM-DD')",
        'new Date().toISOString()',
        'formatToUTC(data.start_time)',
        // Le jeton `Z` (`-04:00`) ou `ZZ` (`-0400`) écrit le décalage, que le serveur ramène.
        "dayjs().format('YYYY-MM-DDTHH:mm:ssZ')",
        "dayjs().format('YYYY-MM-DD[T]HH:mm:ssZZ')",
    ]) {
        expect(heuresMuralesAEnvoyer(source), source).toEqual([])
    }
})
