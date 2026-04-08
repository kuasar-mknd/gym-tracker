import { describe, it, expect } from 'vitest'
import { formatToLocalISO, formatToUTC } from './date.js'

describe('formatToLocalISO', () => {
    it('returns an empty string when date is falsy', () => {
        expect(formatToLocalISO(null)).toBe('')
        expect(formatToLocalISO(undefined)).toBe('')
        expect(formatToLocalISO('')).toBe('')
    })

    it('returns an empty string when date is invalid', () => {
        expect(formatToLocalISO('invalid-date')).toBe('')
    })

    it('formats a date object to local ISO string (YYYY-MM-DDTHH:mm)', () => {
        // Create a date in local timezone
        const d = new Date(2023, 4, 15, 14, 30) // May 15, 2023, 14:30:00 local time
        expect(formatToLocalISO(d)).toBe('2023-05-15T14:30')
    })

    it('formats a date string to local ISO string', () => {
        const d = new Date(2023, 11, 1, 8, 5) // Dec 1, 2023, 08:05 local
        // string representation of local time depends on the environment
        // so we pass the date object or a string that parses to the same local time
        expect(formatToLocalISO(d.toISOString())).toBe('2023-12-01T08:05')
    })
})

describe('formatToUTC', () => {
    it('returns null when localDateString is falsy', () => {
        expect(formatToUTC(null)).toBeNull()
        expect(formatToUTC(undefined)).toBeNull()
        expect(formatToUTC('')).toBeNull()
    })

    it('returns null when localDateString is invalid', () => {
        expect(formatToUTC('invalid-date')).toBeNull()
    })

    it('converts a local date string to UTC ISO string', () => {
        const localStr = '2023-05-15T14:30'
        const d = new Date(localStr)
        expect(formatToUTC(localStr)).toBe(d.toISOString())
    })
})
