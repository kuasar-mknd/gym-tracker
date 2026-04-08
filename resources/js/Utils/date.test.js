import { describe, it, expect } from 'vitest'
import { formatToUTC } from './date.js'

describe('formatToUTC', () => {
    it('returns null for falsy inputs', () => {
        expect(formatToUTC(null)).toBeNull()
        expect(formatToUTC('')).toBeNull()
        expect(formatToUTC(undefined)).toBeNull()
    })

    it('returns null for invalid date strings', () => {
        expect(formatToUTC('not-a-date')).toBeNull()
        expect(formatToUTC('2023-99-99')).toBeNull()
    })

    it('converts local date string without timezone to UTC ISO string', () => {
        const localDateString = '2023-01-01T12:00'
        const d = new Date(localDateString)
        expect(formatToUTC(localDateString)).toBe(d.toISOString())
    })

    it('handles existing UTC ISO strings correctly', () => {
        const utcString = '2023-01-01T12:00:00.000Z'
        expect(formatToUTC(utcString)).toBe(utcString)
    })

    it('handles standard Date objects if passed in', () => {
        const d = new Date('2023-01-01T12:00:00.000Z')
        expect(formatToUTC(d)).toBe('2023-01-01T12:00:00.000Z')
    })
})
