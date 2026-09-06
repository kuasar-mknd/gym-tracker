import { describe, it, expect, vi } from 'vitest'
import { flushPromises } from '@vue/test-utils'

import { useValidationDeSerie } from '@/composables/useValidationDeSerie'
import { createWriteSequencer } from '@/Utils/writeOrdering'

const differee = () => {
    let resoudre
    const promesse = new Promise((r) => {
        resoudre = r
    })

    return { promesse, resoudre }
}

const monter = (patchSet) => {
    const { next: nextWrite, isLatest: isLatestWrite } = createWriteSequencer()
    const flushPendingUpdates = vi.fn(() => Promise.resolve())
    const reportSyncFailure = vi.fn()
    const apresValidation = vi.fn()

    const validation = useValidationDeSerie({
        patchSet,
        nextWrite,
        isLatestWrite,
        flushPendingUpdates,
        reportSyncFailure,
        apresValidation,
    })

    return { validation, flushPendingUpdates, reportSyncFailure, apresValidation }
}

describe('la validation d’une série', () => {
    it('se dit en vol le temps de l’aller-retour, pour que la fusion des props ne décoche pas', async () => {
        const reponse = differee()
        const { validation } = monter(() => reponse.promesse)
        const set = { id: 4, _rowKey: 'row-4', is_completed: false }

        const envoi = validation.toggleSetCompletion(set, 90)
        await flushPromises()

        expect(set.is_completed).toBe(true)
        expect(validation.completionsEnVol.has('completion:row-4')).toBe(true)

        reponse.resoudre({ data: { data: { is_completed: true, personal_record: null, updated_at: 'now' } } })
        await envoi

        expect(validation.completionsEnVol.has('completion:row-4')).toBe(false)
    })

    it('ne croit que le dernier mot : une réponse dépassée par un second appui ne recoche rien', async () => {
        const premiere = differee()
        const seconde = differee()
        const reponses = [premiere.promesse, seconde.promesse]
        const { validation, apresValidation } = monter(() => reponses.shift())
        const set = { id: 4, _rowKey: 'row-4', is_completed: false }

        const coche = validation.toggleSetCompletion(set, 60)
        await flushPromises()
        const decoche = validation.toggleSetCompletion(set, 60)

        expect(set.is_completed).toBe(false)
        expect(apresValidation).toHaveBeenCalledTimes(1)

        premiere.resoudre({ data: { data: { is_completed: true, personal_record: null, updated_at: 't1' } } })
        await coche
        expect(set.is_completed).toBe(false)

        seconde.resoudre({ data: { data: { is_completed: false, personal_record: null, updated_at: 't2' } } })
        await decoche
        expect(set.is_completed).toBe(false)
        expect(set.updated_at).toBe('t2')
    })
})
