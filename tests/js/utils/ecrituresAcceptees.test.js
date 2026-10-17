import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import {
    CLEF_DES_ECRITURES_ACCEPTEES,
    SEANCES_NOTEES_AU_PLUS,
    derniereEcritureAcceptee,
    noterUneEcritureAcceptee,
    oublierLesEcrituresAcceptees,
} from '@/Utils/ecrituresAcceptees'

/*
 * La note des écritures de séance acceptées en ligne (#2048, partie D) : la
 * séance et l'heure, par compte, que la copie hors ligne d'une séance plus
 * ancienne permettra de dire (partie E).
 */

const MIDI = Date.parse('2026-10-12T12:00:00Z')

beforeEach(() => {
    localStorage.clear()
})

afterEach(() => {
    vi.restoreAllMocks()
    localStorage.clear()
})

describe('la note des écritures acceptées', () => {
    it('garde, pour chaque séance du compte, l’instant de sa dernière écriture acceptée', () => {
        expect(noterUneEcritureAcceptee({ compte: 7, seance: 5, instant: MIDI })).toBe(true)
        expect(noterUneEcritureAcceptee({ compte: '7', seance: '5', instant: MIDI + 1000 })).toBe(true)
        expect(noterUneEcritureAcceptee({ compte: 7, seance: 6, instant: MIDI + 2000 })).toBe(true)

        expect(derniereEcritureAcceptee({ compte: 7, seance: 5 })).toBe(MIDI + 1000)
        expect(derniereEcritureAcceptee({ compte: '7', seance: '6' })).toBe(MIDI + 2000)
        expect(derniereEcritureAcceptee({ compte: 7, seance: 9 })).toBeNull()
        expect(JSON.parse(localStorage.getItem(CLEF_DES_ECRITURES_ACCEPTEES))).toEqual({
            compte: '7',
            seances: { 5: MIDI + 1000, 6: MIDI + 2000 },
        })
    })

    it('ne recule jamais : une réponse revenue après une plus récente ne remplace pas son heure', () => {
        noterUneEcritureAcceptee({ compte: 7, seance: 5, instant: MIDI + 5000 })
        noterUneEcritureAcceptee({ compte: 7, seance: 5, instant: MIDI })

        expect(derniereEcritureAcceptee({ compte: 7, seance: 5 })).toBe(MIDI + 5000)
    })

    it('ne se lit pas pour un autre compte, et la note d’un autre compte est remplacée', () => {
        noterUneEcritureAcceptee({ compte: 7, seance: 5, instant: MIDI })

        expect(derniereEcritureAcceptee({ compte: 8, seance: 5 })).toBeNull()

        noterUneEcritureAcceptee({ compte: 8, seance: 6, instant: MIDI + 1000 })

        expect(derniereEcritureAcceptee({ compte: 7, seance: 5 })).toBeNull()
        expect(JSON.parse(localStorage.getItem(CLEF_DES_ECRITURES_ACCEPTEES))).toEqual({
            compte: '8',
            seances: { 6: MIDI + 1000 },
        })
    })

    it(`ne retient que les ${SEANCES_NOTEES_AU_PLUS} séances les plus récemment écrites`, () => {
        for (let seance = 1; seance <= SEANCES_NOTEES_AU_PLUS + 2; seance++) {
            noterUneEcritureAcceptee({ compte: 7, seance, instant: MIDI + seance })
        }

        const seances = Object.keys(JSON.parse(localStorage.getItem(CLEF_DES_ECRITURES_ACCEPTEES)).seances)

        expect(seances).toHaveLength(SEANCES_NOTEES_AU_PLUS)
        expect(derniereEcritureAcceptee({ compte: 7, seance: 1 })).toBeNull()
        expect(derniereEcritureAcceptee({ compte: 7, seance: 2 })).toBeNull()
        expect(derniereEcritureAcceptee({ compte: 7, seance: 3 })).toBe(MIDI + 3)
    })

    it('ne note rien sans compte, sans séance ou sans instant', () => {
        expect(noterUneEcritureAcceptee({ compte: null, seance: 5, instant: MIDI })).toBe(false)
        expect(noterUneEcritureAcceptee({ compte: 7, seance: null, instant: MIDI })).toBe(false)
        expect(noterUneEcritureAcceptee({ compte: 7, seance: 5, instant: Number.NaN })).toBe(false)
        expect(localStorage.getItem(CLEF_DES_ECRITURES_ACCEPTEES)).toBeNull()
    })

    it('s’efface entière, ou seulement quand elle est d’un autre compte que celui qu’on garde', () => {
        noterUneEcritureAcceptee({ compte: 7, seance: 5, instant: MIDI })

        oublierLesEcrituresAcceptees(localStorage, { saufDuCompte: 7 })
        expect(derniereEcritureAcceptee({ compte: 7, seance: 5 })).toBe(MIDI)

        oublierLesEcrituresAcceptees(localStorage, { saufDuCompte: 8 })
        expect(localStorage.getItem(CLEF_DES_ECRITURES_ACCEPTEES)).toBeNull()

        noterUneEcritureAcceptee({ compte: 7, seance: 5, instant: MIDI })
        oublierLesEcrituresAcceptees()
        expect(localStorage.getItem(CLEF_DES_ECRITURES_ACCEPTEES)).toBeNull()
    })

    it('se lit vide quand le stockage est abîmé, et ne lève jamais quand il se refuse', () => {
        localStorage.setItem(CLEF_DES_ECRITURES_ACCEPTEES, '{pas du JSON')
        expect(derniereEcritureAcceptee({ compte: 7, seance: 5 })).toBeNull()

        localStorage.setItem(CLEF_DES_ECRITURES_ACCEPTEES, JSON.stringify({ compte: 7, seances: {} }))
        expect(derniereEcritureAcceptee({ compte: 7, seance: 5 })).toBeNull()

        vi.spyOn(Storage.prototype, 'setItem').mockImplementation(() => {
            throw new DOMException('quota', 'QuotaExceededError')
        })
        vi.spyOn(Storage.prototype, 'removeItem').mockImplementation(() => {
            throw new DOMException('refusé', 'SecurityError')
        })

        expect(noterUneEcritureAcceptee({ compte: 7, seance: 5, instant: MIDI })).toBe(false)
        expect(() => oublierLesEcrituresAcceptees()).not.toThrow()
        expect(derniereEcritureAcceptee({ compte: 7, seance: 5 }, undefined)).toBeNull()
    })
})
