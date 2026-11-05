import { expect } from 'vitest'

/**
 * Fait comme si l'appareil était réglé sur un autre fuseau, et vérifie qu'il
 * l'est (#2111, #2158).
 *
 * La CI tourne en UTC, où un jour lu comme un instant et la bonne lecture
 * donnent la même date : un test des dates se place donc à l'ouest ou à l'est
 * d'UTC par `process.env.TZ`. Node relit la variable à chaque date dans le fil
 * principal d'un processus, celui du pool `forks` de Vitest, le défaut ; dans
 * un worker de `--pool=threads`, l'affectation reste sans effet et sans
 * erreur, `Intl` compris, et `vi.stubEnv('TZ', …)` de même. Un test qui ne le
 * vérifie pas tourne alors dans le fuseau de la machine, et passe sans rien
 * prouver quand celui-ci lui donne raison.
 *
 * Le fuseau posé, le décalage se lit donc à l'instant du cas : celui d'un
 * fuseau change avec l'heure d'été (Toronto rend 240 le 10 mars 2026, et 300
 * le 1er novembre 2026 à 06:30Z). Un décalage qui ne vient pas fait tomber le
 * cas sur « le fuseau … doit être en vigueur », avec son nom, et le fuseau
 * d'avant revient aussitôt.
 *
 * La fonction rendue remet le fuseau d'avant. Absent, il se retire par
 * `delete` : `process.env.TZ = undefined` écrirait la chaîne « undefined »,
 * que Node lit comme un fuseau inconnu, donc UTC, pour la suite du processus.
 * Un hook la rend à Vitest, qui l'appelle après le cas ou après le fichier ;
 * un cas la confie à `onTestFinished` :
 *
 *     beforeAll(() => poserLeFuseau('America/New_York', 240, LE_31_JUILLET))
 *     beforeEach(() => poserLeFuseau('America/Toronto', 240, NOW))
 *     onTestFinished(poserLeFuseau('Europe/Paris', -120, instant))
 *
 * `tests/js/conventions/fuseauxVerifies.test.js` refuse toute autre écriture
 * du fuseau dans tests/js.
 *
 * @param {string} fuseau Un fuseau IANA : `America/New_York`, `Europe/Paris`.
 * @param {number} decalageEnMinutes Ce que `getTimezoneOffset()` rend à `instant` dans ce fuseau.
 * @param {Date} instant L'instant du cas.
 * @returns {() => void} Remet le fuseau d'avant.
 */
export const poserLeFuseau = (fuseau, decalageEnMinutes, instant) => {
    if (typeof instant?.getTimezoneOffset !== 'function') {
        throw new TypeError(`poserLeFuseau attend l'instant du cas : le décalage de ${fuseau} en dépend`)
    }

    const fuseauDOrigine = process.env.TZ

    const rendreLeFuseau = () => {
        if (fuseauDOrigine === undefined) {
            delete process.env.TZ
        } else {
            process.env.TZ = fuseauDOrigine
        }
    }

    process.env.TZ = fuseau

    try {
        expect(instant.getTimezoneOffset(), `le fuseau ${fuseau} doit être en vigueur`).toBe(decalageEnMinutes)
    } catch (erreur) {
        rendreLeFuseau()

        throw erreur
    }

    return rendreLeFuseau
}
