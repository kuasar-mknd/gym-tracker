import { describe, it, expect, afterEach } from 'vitest'

import { formatToLocalISO, formatToUTC } from '@/Utils/date'

/**
 * These two are the pair a <input type="datetime-local"> needs: the field shows
 * a wall-clock time with no zone, the server stores an instant in UTC. Both
 * halves of the trip are only wrong away from UTC, so the timezone is set
 * explicitly — asserting from UTC would let the identity function pass.
 */
const originalTz = process.env.TZ

afterEach(() => {
    // `process.env.TZ = undefined` stores the *string* "undefined", which Node
    // reads as an unknown zone and silently falls back to UTC — for this worker,
    // and so for every file that runs after this one in it. TZ is unset in CI,
    // so this is the branch that actually runs.
    if (originalTz === undefined) {
        delete process.env.TZ
    } else {
        process.env.TZ = originalTz
    }
})

describe('formatToLocalISO', () => {
    it('shows a UTC instant as the wall-clock time the user reads', () => {
        process.env.TZ = 'Europe/Paris'

        // 22:30 UTC on 31 July is 00:30 the next day in Paris: the date rolls
        // over too, which a naive toISOString().slice(0, 16) would not do.
        expect(formatToLocalISO('2026-07-31T22:30:00.000Z')).toBe('2026-08-01T00:30')
    })

    it('rolls the day back west of UTC', () => {
        process.env.TZ = 'America/New_York'

        expect(formatToLocalISO('2026-08-01T02:15:00.000Z')).toBe('2026-07-31T22:15')
    })

    it('cuts the string where the input stops, at the minute', () => {
        process.env.TZ = 'Europe/Paris'

        expect(formatToLocalISO('2026-07-31T10:20:45.678Z')).toBe('2026-07-31T12:20')
    })

    it('accepts a Date as readily as a string', () => {
        process.env.TZ = 'Europe/Paris'

        expect(formatToLocalISO(new Date('2026-07-31T22:30:00.000Z'))).toBe('2026-08-01T00:30')
    })

    it('gives an empty field rather than "Invalid Date" for nothing, or nonsense', () => {
        for (const value of [null, undefined, '', 'pas une date']) {
            expect(formatToLocalISO(value), String(value)).toBe('')
        }
    })
})

describe('formatToUTC', () => {
    it('reads the field as local time and sends the instant', () => {
        process.env.TZ = 'Europe/Paris'

        // Paris is UTC+2 in August: the same wall clock is two hours earlier in UTC.
        expect(formatToUTC('2026-08-01T00:30')).toBe('2026-07-31T22:30:00.000Z')
    })

    it('rolls the day forward west of UTC', () => {
        process.env.TZ = 'America/New_York'

        expect(formatToUTC('2026-07-31T22:15')).toBe('2026-08-01T02:15:00.000Z')
    })

    it('returns null rather than a string the server would reject', () => {
        for (const value of [null, undefined, '', 'pas une date']) {
            expect(formatToUTC(value), String(value)).toBeNull()
        }
    })

    it('brings back the instant it was given, both ways round', () => {
        process.env.TZ = 'America/New_York'

        expect(formatToUTC(formatToLocalISO('2026-08-01T02:15:00.000Z'))).toBe('2026-08-01T02:15:00.000Z')
    })
})
