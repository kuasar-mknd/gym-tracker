import { describe, it, expect } from 'vitest'

import { workoutDurationMinutes } from '@/Utils/workoutDuration'

/**
 * Four charts each computed this inline and each returned 0 for a session with
 * no ended_at. Zero is a measurement — it claims the session lasted no time —
 * so starting a workout dropped the last point of every duration curve to the
 * floor, and an abandoned session left it there permanently.
 */
describe('workoutDurationMinutes', () => {
    it('measures a finished session', () => {
        expect(
            workoutDurationMinutes({
                started_at: '2026-07-31T10:00:00Z',
                ended_at: '2026-07-31T11:30:00Z',
            }),
        ).toBe(90)
    })

    it('returns null for a session still running, not zero', () => {
        // null is what Chart.js skips. 0 is what it plots on the axis.
        expect(workoutDurationMinutes({ started_at: '2026-07-31T10:00:00Z', ended_at: null })).toBeNull()
    })

    it('returns null when there is nothing to measure from', () => {
        expect(workoutDurationMinutes({})).toBeNull()
        expect(workoutDurationMinutes(null)).toBeNull()
        expect(workoutDurationMinutes(undefined)).toBeNull()
    })

    it('returns null rather than NaN on an unparseable timestamp', () => {
        expect(workoutDurationMinutes({ started_at: 'pas une date', ended_at: 'non plus' })).toBeNull()
    })
})
