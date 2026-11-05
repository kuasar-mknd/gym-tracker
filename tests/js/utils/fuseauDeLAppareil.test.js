import { describe, it, expect, beforeEach, onTestFinished } from 'vitest'

import { poserLeFuseau } from './fuseauDeLAppareil'

/**
 * L'utilitaire qui pose le fuseau de l'appareil (#2158) : il le vérifie à
 * l'instant du cas, tombe sur son nom quand il n'est pas en vigueur, et remet
 * le fuseau d'avant, retiré s'il n'était pas posé.
 *
 * Sous `--pool=threads`, où le fuseau posé reste sans effet, chaque cas qui en
 * pose un tombe sur « le fuseau … doit être en vigueur » : c'est ce qu'il
 * vérifie.
 */

/** Midi UTC le 10 mars 2026 : Toronto et New York sont à l'heure d'été depuis le 8 (UTC-4), Paris pas encore (UTC+1). */
const LE_10_MARS = new Date('2026-03-10T12:00:00Z')

/** 06:30Z le 1er novembre 2026 : Toronto est revenu à l'heure d'hiver (UTC-5) une demi-heure plus tôt. */
const LE_1ER_NOVEMBRE = new Date('2026-11-01T06:30:00Z')

/** Le fuseau du processus : la variable, posée ou non, et le décalage qu'elle donne. */
const fuseauActuel = () => ({
    pose: 'TZ' in process.env,
    valeur: process.env.TZ,
    decalage: LE_10_MARS.getTimezoneOffset(),
})

/** Celui de la machine, lu au chargement du fichier, avant qu'un cas pose le sien. */
const FUSEAU_DE_LA_MACHINE = fuseauActuel()

describe('poserLeFuseau', () => {
    it('pose le fuseau, dont le décalage suit l’instant', () => {
        onTestFinished(poserLeFuseau('America/Toronto', 240, LE_10_MARS))

        expect(process.env.TZ).toBe('America/Toronto')
        expect(LE_1ER_NOVEMBRE.getTimezoneOffset()).toBe(300)
    })

    it('tombe sur le nom du fuseau quand son décalage ne vient pas, et remet aussitôt celui d’avant', () => {
        expect(() => poserLeFuseau('America/Toronto', 300, LE_10_MARS)).toThrow(
            'le fuseau America/Toronto doit être en vigueur',
        )
        expect(fuseauActuel()).toEqual(FUSEAU_DE_LA_MACHINE)
    })

    it('rend une fonction qui remet le fuseau de la machine, retiré s’il n’était pas posé', () => {
        const rendreLeFuseau = poserLeFuseau('Europe/Paris', -60, LE_10_MARS)

        rendreLeFuseau()

        expect(fuseauActuel()).toEqual(FUSEAU_DE_LA_MACHINE)
    })

    it('remet un fuseau déjà posé', () => {
        onTestFinished(poserLeFuseau('Europe/Paris', -60, LE_10_MARS))

        poserLeFuseau('America/New_York', 240, LE_10_MARS)()

        expect(process.env.TZ).toBe('Europe/Paris')
        expect(LE_10_MARS.getTimezoneOffset()).toBe(-60)
    })

    it('demande l’instant du cas, sans rien poser', () => {
        expect(() => poserLeFuseau('America/New_York', 240)).toThrow(
            "poserLeFuseau attend l'instant du cas : le décalage de America/New_York en dépend",
        )
        expect(fuseauActuel()).toEqual(FUSEAU_DE_LA_MACHINE)
    })
})

describe('un fuseau posé par un hook', () => {
    beforeEach(() => poserLeFuseau('America/New_York', 240, LE_10_MARS))

    it('vaut pendant le cas', () => {
        expect(process.env.TZ).toBe('America/New_York')
        expect(LE_10_MARS.getTimezoneOffset()).toBe(240)
    })
})

describe('le cas qui suit un fuseau rendu', () => {
    it('lit de nouveau le fuseau de la machine', () => {
        expect(fuseauActuel()).toEqual(FUSEAU_DE_LA_MACHINE)
    })
})
