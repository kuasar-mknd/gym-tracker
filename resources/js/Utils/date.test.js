import { describe, it, expect } from 'vitest'
import { formatToUTC, formatToLocalISO } from './date.js'

describe('formatToUTC', () => {
    it('returns null when provided with falsy values', () => {
        expect(formatToUTC(null)).toBeNull()
        expect(formatToUTC(undefined)).toBeNull()
        expect(formatToUTC('')).toBeNull()
    })

    it('returns null when provided with an invalid date string', () => {
        expect(formatToUTC('not-a-date')).toBeNull()
    })

    it('correctly formats a local datetime-local string to UTC ISO string', () => {
        const localString = '2023-01-01T12:00'
        const expectedUTC = new Date(localString).toISOString()
        expect(formatToUTC(localString)).toBe(expectedUTC)
    })
})

describe('formatToLocalISO', () => {
    it('returns an empty string when provided with falsy values', () => {
        expect(formatToLocalISO(null)).toBe('')
        expect(formatToLocalISO(undefined)).toBe('')
        expect(formatToLocalISO('')).toBe('')
    })

    it('returns an empty string when provided with an invalid date string', () => {
        expect(formatToLocalISO('not-a-date')).toBe('')
    })

    it('correctly formats a Date to a local ISO string (YYYY-MM-DDTHH:mm)', () => {
        const date = new Date('2023-01-01T12:00:00Z')
        const offset = date.getTimezoneOffset() * 60000
        const expectedLocal = new Date(date.getTime() - offset).toISOString().slice(0, 16)

        expect(formatToLocalISO(date)).toBe(expectedLocal)
    })
})
