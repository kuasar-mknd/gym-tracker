import { describe, it, expect } from 'vitest'
import { progressionAffichee } from '@/Utils/nombre'

describe('progressionAffichee', () => {
    it('réserve 100 % à l’objectif atteint', () => {
        expect(progressionAffichee({ progress_pct: 99.5, completed_at: null })).toBe(99)
        expect(progressionAffichee({ progress_pct: 100, completed_at: '2026-11-01T10:00:00Z' })).toBe(100)
    })

    it('laisse les autres progressions telles quelles', () => {
        expect(progressionAffichee({ progress_pct: 56.75, completed_at: null })).toBe(56.75)
        expect(progressionAffichee({ progress_pct: null, completed_at: null })).toBe(0)
    })
})
