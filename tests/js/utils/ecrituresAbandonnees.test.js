import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import {
    messageDesEcrituresAbandonnees,
    noterLesEcrituresAbandonnees,
    oublierLesEcrituresAbandonnees,
    reprendreLesEcrituresAbandonnees,
} from '@/Utils/ecrituresAbandonnees'

/**
 * L'avis de ce que la file a abandonné après trente jours (#2050) : par
 * compte, un nombre et une date, rien de ce que contenaient les écritures, et
 * jamais plus de trente jours lui-même.
 */

const CLEF = 'gym-tracker:ecritures-abandonnees'
const MAINTENANT = new Date('2026-10-16T12:00:00.000Z').getTime()
const JOUR = 24 * 60 * 60 * 1000

beforeEach(() => {
    localStorage.clear()
    vi.useFakeTimers({ toFake: ['Date'] })
    vi.setSystemTime(MAINTENANT)
})

afterEach(() => {
    vi.useRealTimers()
    vi.restoreAllMocks()
    localStorage.clear()
})

describe('l’avis des écritures abandonnées', () => {
    it('additionne les abandons d’un même compte et garde la date la plus ancienne', () => {
        noterLesEcrituresAbandonnees(new Map([['42', { nombre: 2, depuis: MAINTENANT - 31 * JOUR }]]))
        noterLesEcrituresAbandonnees(
            new Map([
                ['42', { nombre: 1, depuis: MAINTENANT - 40 * JOUR }],
                ['7', { nombre: 1, depuis: null }],
            ]),
        )

        expect(reprendreLesEcrituresAbandonnees(42)).toEqual({ nombre: 3, depuis: MAINTENANT - 40 * JOUR })
        expect(reprendreLesEcrituresAbandonnees('42')).toBeNull()
        expect(reprendreLesEcrituresAbandonnees('7')).toEqual({ nombre: 1, depuis: null })
        expect(localStorage.getItem(CLEF)).toBeNull()
    })

    it('ne garde qu’un nombre et des dates', () => {
        noterLesEcrituresAbandonnees(new Map([['42', { nombre: 2, depuis: MAINTENANT - 31 * JOUR }]]))

        expect(JSON.parse(localStorage.getItem(CLEF))).toEqual({
            42: { nombre: 2, depuis: MAINTENANT - 31 * JOUR, le: MAINTENANT },
        })
    })

    it('s’efface trente jours après avoir été noté, pour un compte qui ne revient pas', () => {
        noterLesEcrituresAbandonnees(new Map([['42', { nombre: 1, depuis: MAINTENANT - 31 * JOUR }]]))

        vi.setSystemTime(MAINTENANT + 30 * JOUR)
        expect(reprendreLesEcrituresAbandonnees('42')).not.toBeNull()

        noterLesEcrituresAbandonnees(new Map([['42', { nombre: 1, depuis: MAINTENANT - 31 * JOUR }]]))
        vi.setSystemTime(MAINTENANT + 60 * JOUR + 1)
        expect(reprendreLesEcrituresAbandonnees('42')).toBeNull()
    })

    it('s’oublie avec le compte qui quitte l’appareil, sans toucher aux autres', () => {
        noterLesEcrituresAbandonnees(
            new Map([
                ['42', { nombre: 1, depuis: null }],
                ['7', { nombre: 2, depuis: null }],
            ]),
        )

        oublierLesEcrituresAbandonnees(42)
        oublierLesEcrituresAbandonnees(null)

        expect(Object.keys(JSON.parse(localStorage.getItem(CLEF)))).toEqual(['7'])
    })

    it('lit un avis illisible comme « rien », et ne lève pas quand le stockage refuse', () => {
        localStorage.setItem(CLEF, '{coupé')
        expect(reprendreLesEcrituresAbandonnees('42')).toBeNull()

        localStorage.setItem(CLEF, JSON.stringify({ 42: { nombre: 'deux', le: MAINTENANT, depuis: null } }))
        expect(reprendreLesEcrituresAbandonnees('42')).toBeNull()

        localStorage.setItem(CLEF, JSON.stringify([1, 2]))
        expect(reprendreLesEcrituresAbandonnees('42')).toBeNull()

        vi.spyOn(Storage.prototype, 'setItem').mockImplementation(() => {
            throw new DOMException('plein', 'QuotaExceededError')
        })
        expect(() => noterLesEcrituresAbandonnees(new Map([['42', { nombre: 1, depuis: null }]]))).not.toThrow()
    })

    it('dit combien, et depuis quand', () => {
        const depuis = new Date('2026-09-12T08:00:00.000Z').getTime()

        expect(messageDesEcrituresAbandonnees({ nombre: 3, depuis })).toBe(
            "3 modifications faites hors ligne depuis le 12/09/2026 n'ont pas pu être enregistrées : gardées 30 jours sur cet appareil sans pouvoir partir, elles ont été abandonnées et effacées. Vérifie tes séances.",
        )
        expect(messageDesEcrituresAbandonnees({ nombre: 1, depuis })).toBe(
            "Une modification faite hors ligne le 12/09/2026 n'a pas pu être enregistrée : gardée 30 jours sur cet appareil sans pouvoir partir, elle a été abandonnée et effacée. Vérifie tes séances.",
        )
        expect(messageDesEcrituresAbandonnees({ nombre: 2, depuis: null })).toContain(
            'faites hors ligne depuis plus de 30 jours',
        )
        expect(messageDesEcrituresAbandonnees({ nombre: 1, depuis: null })).toContain(
            'faite hors ligne il y a plus de 30 jours',
        )
    })
})
