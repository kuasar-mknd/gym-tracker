import { describe, it, expect, afterEach, vi } from 'vitest'

import { parseCalendarDate, todayAsCalendarDate } from '@/Utils/date'

/**
 * The test container runs in UTC, where both the correct and the broken form
 * agree. These set the timezone explicitly, because the whole defect only
 * exists on either side of UTC — asserting from UTC would prove nothing.
 */
const originalTz = process.env.TZ

afterEach(() => {
    process.env.TZ = originalTz
    vi.useRealTimers()
})

describe('todayAsCalendarDate', () => {
    it('gives the local day, not the UTC one, in the small hours', () => {
        // 00:30 on 31 July in Paris is still 30 July in UTC. Forms seeded from
        // toISOString() offered yesterday's date for the first two hours of
        // every day — and the journal upserts on that date, so saving
        // overwrote the previous day's entry instead of creating today's.
        process.env.TZ = 'Europe/Paris'
        vi.useFakeTimers()
        vi.setSystemTime(new Date('2026-07-30T22:30:00Z'))

        expect(todayAsCalendarDate()).toBe('2026-07-31')
    })

    it('pads month and day so the string is always sortable', () => {
        process.env.TZ = 'Europe/Paris'
        vi.useFakeTimers()
        vi.setSystemTime(new Date('2026-03-05T12:00:00Z'))

        expect(todayAsCalendarDate()).toBe('2026-03-05')
    })
})

describe('parseCalendarDate', () => {
    it('keeps the day it was given west of UTC', () => {
        // new Date('2026-07-31') is specified to parse as midnight UTC, which
        // renders as 30 July anywhere behind UTC.
        process.env.TZ = 'America/New_York'

        const parsed = parseCalendarDate('2026-07-31')

        expect(parsed.getFullYear()).toBe(2026)
        expect(parsed.getMonth()).toBe(6)
        expect(parsed.getDate()).toBe(31)
    })

    it('keeps the day it was given east of UTC', () => {
        process.env.TZ = 'Europe/Paris'

        expect(parseCalendarDate('2026-07-31').getDate()).toBe(31)
    })

    it('takes the day out of a full timestamp', () => {
        process.env.TZ = 'America/New_York'

        expect(parseCalendarDate('2026-07-31T00:00:00.000000Z').getDate()).toBe(31)
    })

    it('returns null rather than an Invalid Date the caller would render', () => {
        expect(parseCalendarDate(null)).toBeNull()
        expect(parseCalendarDate('')).toBeNull()
        expect(parseCalendarDate('pas une date')).toBeNull()
    })
})
