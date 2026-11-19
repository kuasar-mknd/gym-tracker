import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import {
    abandonner,
    estEnAttenteDeSuppression,
    annuler,
    DELAI_POUR_ANNULER_MS,
    proposerUneAnnulation,
    reprendre,
    suppressionAnnulable,
    suspendre,
    valider,
} from '@/Utils/suppressionAnnulable'

const suppression = () => ({
    message: 'Série supprimée',
    porte: (set) => set.id === 1,
    valider: vi.fn(),
    annuler: vi.fn(),
})

beforeEach(() => vi.useFakeTimers())

afterEach(() => {
    abandonner()
    vi.useRealTimers()
})

describe('Utils/suppressionAnnulable (#2355)', () => {
    it('announces the deletion and does not send it yet', () => {
        const s = suppression()

        proposerUneAnnulation(s)
        vi.advanceTimersByTime(DELAI_POUR_ANNULER_MS - 1)

        expect(suppressionAnnulable.value).toEqual({ message: 'Série supprimée' })
        expect(s.valider).not.toHaveBeenCalled()
    })

    it('sends it when the time has run out, and clears the announcement', () => {
        const s = suppression()

        proposerUneAnnulation(s)
        vi.advanceTimersByTime(DELAI_POUR_ANNULER_MS)

        expect(s.valider).toHaveBeenCalledTimes(1)
        expect(s.annuler).not.toHaveBeenCalled()
        expect(suppressionAnnulable.value).toBeNull()
    })

    it('takes it back without ever sending it', () => {
        const s = suppression()

        proposerUneAnnulation(s)
        annuler()
        vi.advanceTimersByTime(DELAI_POUR_ANNULER_MS * 2)

        expect(s.annuler).toHaveBeenCalledTimes(1)
        expect(s.valider).not.toHaveBeenCalled()
        expect(suppressionAnnulable.value).toBeNull()
    })

    it('sends the previous deletion at once when a new one is announced', () => {
        const premiere = suppression()
        const seconde = suppression()

        proposerUneAnnulation(premiere)
        proposerUneAnnulation(seconde)

        expect(premiere.valider).toHaveBeenCalledTimes(1)
        expect(seconde.valider).not.toHaveBeenCalled()
        expect(suppressionAnnulable.value).toEqual({ message: 'Série supprimée' })
    })

    it('can be sent at once, and only once', () => {
        const s = suppression()

        proposerUneAnnulation(s)
        valider()
        valider()
        vi.advanceTimersByTime(DELAI_POUR_ANNULER_MS)

        expect(s.valider).toHaveBeenCalledTimes(1)
    })

    it('waits while the toast is hovered or focused, and starts over when it is left', () => {
        const s = suppression()

        proposerUneAnnulation(s)
        suspendre()
        vi.advanceTimersByTime(DELAI_POUR_ANNULER_MS * 3)

        expect(s.valider).not.toHaveBeenCalled()

        reprendre()
        vi.advanceTimersByTime(DELAI_POUR_ANNULER_MS - 1)
        expect(s.valider).not.toHaveBeenCalled()

        vi.advanceTimersByTime(1)
        expect(s.valider).toHaveBeenCalledTimes(1)
    })

    it('forgets a deletion that has no reason to happen any more, without sending it', () => {
        const s = suppression()

        proposerUneAnnulation(s)
        abandonner()
        vi.advanceTimersByTime(DELAI_POUR_ANNULER_MS)

        expect(s.valider).not.toHaveBeenCalled()
        expect(s.annuler).not.toHaveBeenCalled()
        expect(suppressionAnnulable.value).toBeNull()
    })

    it('ignores a pause or a resume when nothing is announced', () => {
        suspendre()
        reprendre()
        vi.advanceTimersByTime(DELAI_POUR_ANNULER_MS)

        expect(suppressionAnnulable.value).toBeNull()
    })

    it('says which row waits, and none once it is settled', () => {
        proposerUneAnnulation(suppression())

        expect(estEnAttenteDeSuppression({ id: 1 })).toBe(true)
        expect(estEnAttenteDeSuppression({ id: 2 })).toBe(false)

        valider()

        expect(estEnAttenteDeSuppression({ id: 1 })).toBe(false)
    })
})
