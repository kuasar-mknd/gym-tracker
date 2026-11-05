import { describe, it, expect, afterEach, onTestFinished, vi } from 'vitest'

import {
    dateAvecJour,
    dateCourte,
    demainEnJourCalendaire,
    heureCourte,
    parseCalendarDate,
    todayAsCalendarDate,
} from '@/Utils/date'
import { simulerUnSystemeEnAnglais } from './systemeEnAnglais'
import { poserLeFuseau } from './fuseauDeLAppareil'

/**
 * The test container runs in UTC, where both the correct and the broken form
 * agree. These set the timezone explicitly, because the whole defect only
 * exists on either side of UTC — asserting from UTC would prove nothing.
 */
afterEach(() => {
    vi.useRealTimers()
})

describe('todayAsCalendarDate', () => {
    it('gives the local day, not the UTC one, in the small hours', () => {
        // 00:30 on 31 July in Paris is still 30 July in UTC. Forms seeded from
        // toISOString() offered yesterday's date for the first two hours of
        // every day — and the journal upserts on that date, so saving
        // overwrote the previous day's entry instead of creating today's.
        const instant = new Date('2026-07-30T22:30:00Z')
        onTestFinished(poserLeFuseau('Europe/Paris', -120, instant))
        vi.useFakeTimers()
        vi.setSystemTime(instant)

        expect(todayAsCalendarDate()).toBe('2026-07-31')
    })

    it('pads month and day so the string is always sortable', () => {
        const instant = new Date('2026-03-05T12:00:00Z')
        onTestFinished(poserLeFuseau('Europe/Paris', -60, instant))
        vi.useFakeTimers()
        vi.setSystemTime(instant)

        expect(todayAsCalendarDate()).toBe('2026-03-05')
    })
})

describe('demainEnJourCalendaire', () => {
    it('donne le lendemain local, y compris au passage d’un mois', () => {
        const instant = new Date('2026-09-30T22:30:00Z')
        onTestFinished(poserLeFuseau('Europe/Paris', -120, instant))
        vi.useFakeTimers()
        vi.setSystemTime(instant)

        expect(demainEnJourCalendaire()).toBe('2026-10-02')
    })

    it('passe à l’année suivante le 31 décembre', () => {
        const instant = new Date('2026-12-31T12:00:00Z')
        onTestFinished(poserLeFuseau('Europe/Paris', -60, instant))
        vi.useFakeTimers()
        vi.setSystemTime(instant)

        expect(demainEnJourCalendaire()).toBe('2027-01-01')
    })
})

describe('dateCourte, dateAvecJour, heureCourte', () => {
    it('écrivent en français quelle que soit la langue de l’appareil (#1976)', () => {
        const rendre = simulerUnSystemeEnAnglais()

        try {
            const quatreOctobre = new Date(2026, 9, 4, 14, 30)

            expect(dateCourte(quatreOctobre)).toBe('04/10/2026')
            expect(dateAvecJour(quatreOctobre)).toBe('dim. 4 oct. 2026')
            expect(heureCourte(quatreOctobre)).toBe('14:30')
        } finally {
            rendre()
        }
    })

    it('rendent une chaîne vide pour une date absente ou illisible', () => {
        for (const formateur of [dateCourte, dateAvecJour, heureCourte]) {
            expect(formateur(null)).toBe('')
            expect(formateur(undefined)).toBe('')
            expect(formateur(new Date('pas une date'))).toBe('')
        }
    })
})

describe('parseCalendarDate', () => {
    it('keeps the day it was given west of UTC', () => {
        // new Date('2026-07-31') is specified to parse as midnight UTC, which
        // renders as 30 July anywhere behind UTC.
        onTestFinished(poserLeFuseau('America/New_York', 240, new Date('2026-07-31T12:00:00Z')))

        const parsed = parseCalendarDate('2026-07-31')

        expect(parsed.getFullYear()).toBe(2026)
        expect(parsed.getMonth()).toBe(6)
        expect(parsed.getDate()).toBe(31)
    })

    it('keeps the day it was given east of UTC', () => {
        onTestFinished(poserLeFuseau('Europe/Paris', -120, new Date('2026-07-31T12:00:00Z')))

        expect(parseCalendarDate('2026-07-31').getDate()).toBe(31)
    })

    it('takes the day out of a full timestamp', () => {
        onTestFinished(poserLeFuseau('America/New_York', 240, new Date('2026-07-31T12:00:00Z')))

        expect(parseCalendarDate('2026-07-31T00:00:00.000000Z').getDate()).toBe(31)
    })

    it('returns null rather than an Invalid Date the caller would render', () => {
        expect(parseCalendarDate(null)).toBeNull()
        expect(parseCalendarDate('')).toBeNull()
        expect(parseCalendarDate('pas une date')).toBeNull()
    })
})
