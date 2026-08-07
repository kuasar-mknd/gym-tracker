import { describe, it, expect } from 'vitest'

import { filesMatching } from './sourceFiles'

/**
 * Utils/date.js holds two helpers for reading and writing calendar days. Their
 * unit tests prove the helpers are right; they prove nothing about the call
 * sites, and the call sites are where this went wrong — four forms had each
 * written the UTC-day expression by hand, and seven more places built a Date
 * straight from a day.
 *
 * A helper existing is not the same as every caller using it. These guard the
 * source itself.
 *
 * Utils/date.js is exempt from both: it quotes the banned forms in the comments
 * that explain why the helpers exist.
 */
const exemptions = { extensions: ['.vue', '.js'], skip: ['Utils/date.js'] }

describe('calendar days', () => {
    /**
     * new Date().toISOString() cut to ten characters is today in UTC, still
     * yesterday during the first hours of every local day ahead of it. Four
     * forms seeded their date field that way, and the journal upserts on that
     * date — saving between midnight and 2am overwrote the previous day.
     */
    it('never slices a UTC timestamp down to a day', () => {
        const utcDay =
            /toISOString\(\)\s*\.\s*(substr|substring|slice)\(\s*0\s*,\s*10\s*\)|toISOString\(\)\s*\.\s*split\(\s*['"]T['"]\s*\)\s*\[\s*0\s*\]/

        expect(filesMatching(utcDay, exemptions)).toEqual([])
    })

    /**
     * The columns behind these three names are cast date:Y-m-d server-side, so
     * they arrive as calendar days. new Date('2026-07-31') is specified to
     * parse as midnight UTC, which renders the day before anywhere behind UTC.
     *
     * Naming the fields rather than banning new Date outright is deliberate:
     * started_at, created_at and last_taken_at are genuine instants and reading
     * them that way is correct.
     */
    it('never builds a Date straight from a calendar-day field', () => {
        const calendarDayField = /new Date\(\s*[A-Za-z_$][\w$.]*\.(date|deadline|measured_at)\b/

        expect(filesMatching(calendarDayField, exemptions)).toEqual([])
    })
})
