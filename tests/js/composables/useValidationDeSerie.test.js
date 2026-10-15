import { describe, it, expect, vi } from 'vitest'
import { flushPromises } from '@vue/test-utils'

import { useBrouillonsDeSeries } from '@/composables/useBrouillonsDeSeries'
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
    const { noterUneEcritureNonConfirmee, oublierLEcritureNonConfirmee, valeursDeRepli } = useBrouillonsDeSeries()

    const validation = useValidationDeSerie({
        patchSet,
        nextWrite,
        isLatestWrite,
        flushPendingUpdates,
        reportSyncFailure,
        apresValidation,
        noterUneEcritureNonConfirmee,
        oublierLEcritureNonConfirmee,
    })

    return { validation, flushPendingUpdates, reportSyncFailure, apresValidation, valeursDeRepli }
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

/*
 * L'entrée d'historique de la séance garde la coche d'avant tant que le
 * serveur n'a pas répondu au dernier appui, comme une valeur saisie (#2048).
 */
describe('la coche d’avant, tant que le serveur n’a pas répondu', () => {
    it('vaut jusqu’à la réponse du dernier appui', async () => {
        const premiere = differee()
        const seconde = differee()
        const reponses = [premiere.promesse, seconde.promesse]
        const { validation, valeursDeRepli } = monter(() => reponses.shift())
        const set = { id: 4, _rowKey: 'row-4', is_completed: false }

        const coche = validation.toggleSetCompletion(set, 60)
        await flushPromises()
        expect(valeursDeRepli()).toEqual([{ id: 4, champ: 'is_completed', valeur: false }])

        const decoche = validation.toggleSetCompletion(set, 60)
        expect(valeursDeRepli()).toEqual([{ id: 4, champ: 'is_completed', valeur: true }])

        premiere.resoudre({ data: { data: { is_completed: true, personal_record: null, updated_at: 't1' } } })
        await coche
        expect(valeursDeRepli()).toHaveLength(1)

        seconde.resoudre({ data: { data: { is_completed: false, personal_record: null, updated_at: 't2' } } })
        await decoche
        expect(valeursDeRepli()).toEqual([])
    })

    it('s’oublie au refus, qui rétablit la coche, et quand la file prend l’écriture', async () => {
        const refus = [{ response: { status: 403 } }, { isOffline: true }]
        const { validation, valeursDeRepli } = monter(() => Promise.reject(refus.shift()))
        const refusee = { id: 4, _rowKey: 'row-4', is_completed: false }
        const enFile = { id: 5, _rowKey: 'row-5', is_completed: false }

        await validation.toggleSetCompletion(refusee, 60)
        await validation.toggleSetCompletion(enFile, 60)

        expect(refusee.is_completed).toBe(false)
        expect(enFile.is_completed).toBe(true)
        expect(valeursDeRepli()).toEqual([])
    })
})
