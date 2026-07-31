import { describe, it, expect, afterEach, vi } from 'vitest'
import { readFileSync, readdirSync } from 'node:fs'
import { join } from 'node:path'

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

/**
 * The two tests above cover the helpers, not the call sites. Four separate
 * forms had each written the UTC-day expression by hand, and a helper does
 * nothing about the fifth one somebody writes next month — so this guards the
 * source itself rather than any one page.
 *
 * Utils/date.js is skipped: it quotes the broken form in the comment that
 * explains why the helper exists.
 */
describe('no page derives a calendar day from UTC', () => {
    const sourceRoot = join(process.cwd(), 'resources', 'js')
    const skipped = join(sourceRoot, 'Utils', 'date.js')

    const sourceFiles = (directory) =>
        readdirSync(directory, { withFileTypes: true }).flatMap((entry) => {
            const path = join(directory, entry.name)

            if (entry.isDirectory()) return sourceFiles(path)

            return /\.(vue|js)$/.test(entry.name) && path !== skipped ? [path] : []
        })

    // new Date().toISOString() then cut to ten characters, in any of the forms
    // that were in the codebase or are one keystroke away from it.
    const utcDayPattern =
        /toISOString\(\)\s*\.\s*(substr|substring|slice)\(\s*0\s*,\s*10\s*\)|toISOString\(\)\s*\.\s*split\(\s*['"]T['"]\s*\)\s*\[\s*0\s*\]/

    /**
     * The columns behind these three names are cast `date:Y-m-d` server-side,
     * so they arrive as calendar days. `new Date('2026-07-31')` is specified to
     * parse as midnight UTC, which renders the day before anywhere behind UTC.
     *
     * Naming the fields rather than banning `new Date` outright is deliberate:
     * started_at, created_at and last_taken_at are genuine instants and reading
     * them that way is correct.
     */
    const calendarDayFields = /new Date\(\s*[A-Za-z_$][\w$.]*\.(date|deadline|measured_at)\b/

    it('never slices a UTC timestamp down to a day', () => {
        const offenders = sourceFiles(sourceRoot).filter((path) => utcDayPattern.test(readFileSync(path, 'utf8')))

        expect(offenders.map((path) => path.replace(sourceRoot, 'resources/js'))).toEqual([])
    })

    it('never builds a Date straight from a calendar-day field', () => {
        // Written after three more of these turned up in chart labels that the
        // first sweep had not looked at: a helper existing is not the same as
        // every caller using it.
        const offenders = sourceFiles(sourceRoot).filter((path) => calendarDayFields.test(readFileSync(path, 'utf8')))

        expect(offenders.map((path) => path.replace(sourceRoot, 'resources/js'))).toEqual([])
    })
})
