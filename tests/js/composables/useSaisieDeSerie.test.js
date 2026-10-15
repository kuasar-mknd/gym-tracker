import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { flushPromises } from '@vue/test-utils'

import { useBrouillonsDeSeries } from '@/composables/useBrouillonsDeSeries'
import { useSaisieDeSerie } from '@/composables/useSaisieDeSerie'
import { createWriteQueue, createWriteSequencer } from '@/Utils/writeOrdering'

const monter = () => {
    const patchSet = vi.fn((set, payload) =>
        Promise.resolve({ data: { data: { ...set, ...payload, updated_at: 'now' } } }),
    )
    const { next: nextWrite, isLatest: isLatestWrite } = createWriteSequencer()
    const brouillons = {
        lastConfirmed: vi.fn((set, field, repli) => repli),
        rememberConfirmed: vi.fn(),
        writeDraftField: vi.fn(),
        clearDraftField: vi.fn(),
    }
    const reportEditFailure = vi.fn()

    const fondreDansLaFile = vi.fn(() => true)
    const { noterUneEcritureNonConfirmee, oublierLEcritureNonConfirmee, valeursDeRepli } = useBrouillonsDeSeries()

    const saisie = useSaisieDeSerie({
        patchSet,
        fondreDansLaFile,
        nextWrite,
        isLatestWrite,
        fieldWrites: createWriteQueue(),
        ...brouillons,
        reportEditFailure,
        noterUneEcritureNonConfirmee,
        oublierLEcritureNonConfirmee,
    })

    return { saisie, patchSet, fondreDansLaFile, brouillons, reportEditFailure, valeursDeRepli }
}

/** Une réponse que le test rend quand il veut. */
const differee = () => {
    let resoudre
    let rejeter
    const promesse = new Promise((r, j) => {
        resoudre = r
        rejeter = j
    })

    return { promesse, resoudre, rejeter }
}

beforeEach(() => {
    vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] })
})

afterEach(() => {
    vi.useRealTimers()
})

describe('la saisie d’une série', () => {
    it('oublie les rafales d’une série qui s’en va : rien ne part plus pour elle', async () => {
        const { saisie, patchSet } = monter()
        const set = { id: 7, weight: 60 }

        saisie.updateSet(set, 'weight', '80')
        saisie.oublierLesRafales(7)
        vi.advanceTimersByTime(1500)
        await flushPromises()

        expect(set.weight).toBe(80)
        expect(patchSet).not.toHaveBeenCalled()
    })

    it('vide tout ce qui attend, série par série ou d’un coup, et dit quand c’est parti', async () => {
        const { saisie, patchSet } = monter()
        const premiere = { id: 7, weight: 60, reps: 10 }
        const seconde = { id: 8, weight: 40, reps: 12 }

        saisie.updateSet(premiere, 'weight', '80')
        saisie.updateSet(seconde, 'reps', '15')

        await saisie.flushPendingUpdates(7)
        expect(patchSet).toHaveBeenCalledTimes(1)
        expect(patchSet).toHaveBeenCalledWith(premiere, { weight: 80 })

        await saisie.flushAllPendingUpdates()
        expect(patchSet).toHaveBeenCalledTimes(2)
        expect(patchSet).toHaveBeenLastCalledWith(seconde, { reps: 15 })

        vi.advanceTimersByTime(1500)
        await flushPromises()
        expect(patchSet).toHaveBeenCalledTimes(2)
    })

    /**
     * Une série que le serveur n'a pas encore créée n'a pas d'adresse : sa
     * saisie rejoint l'entrée de file qui la crée, sans debounce ni brouillon,
     * puisque cette entrée survit déjà à un rechargement (#1960).
     */
    it('fond la saisie d’une série provisoire dans sa création en file', async () => {
        const { saisie, patchSet, fondreDansLaFile, brouillons } = monter()
        const provisoire = { id: 'temp-3', reps: 5 }

        saisie.updateSet(provisoire, 'reps', '3')
        vi.advanceTimersByTime(1500)
        await flushPromises()

        expect(provisoire.reps).toBe(3)
        expect(fondreDansLaFile).toHaveBeenCalledWith(provisoire, { reps: 3 })
        expect(patchSet).not.toHaveBeenCalled()
        expect(brouillons.writeDraftField).not.toHaveBeenCalled()
    })
})

/*
 * L'entrée d'historique de la séance garde, à la place d'une valeur dont
 * l'écriture attend le debounce ou sa réponse, celle que la page rétablit si
 * le serveur refuse : gardée telle qu'à l'écran, une valeur refusée après le
 * départ de la page revenait au bouton Avance comme enregistrée (#2048).
 */
describe('la valeur de repli d’une saisie que le serveur n’a pas confirmée', () => {
    it('vaut pendant le debounce et le vol, et s’oublie à la réponse', async () => {
        const { saisie, patchSet, valeursDeRepli } = monter()
        const reponse = differee()
        patchSet.mockImplementationOnce(() => reponse.promesse)
        const set = { id: 7, weight: 60 }

        saisie.updateSet(set, 'weight', '8')
        saisie.updateSet(set, 'weight', '80')
        expect(valeursDeRepli()).toEqual([{ id: 7, champ: 'weight', valeur: 60 }])

        vi.advanceTimersByTime(1500)
        await flushPromises()
        expect(patchSet).toHaveBeenCalledTimes(1)
        expect(valeursDeRepli()).toEqual([{ id: 7, champ: 'weight', valeur: 60 }])

        reponse.resoudre({ data: { data: { id: 7, weight: 80, updated_at: 'now' } } })
        await flushPromises()
        expect(set.weight).toBe(80)
        expect(valeursDeRepli()).toEqual([])
    })

    it('s’oublie au refus, qui rétablit la valeur, et quand la file prend l’écriture', async () => {
        const { saisie, patchSet, valeursDeRepli } = monter()
        patchSet.mockRejectedValueOnce({ response: { status: 422, data: {} } })
        patchSet.mockRejectedValueOnce({ isOffline: true })
        const refusee = { id: 7, weight: 60 }
        const enFile = { id: 8, reps: 5 }

        saisie.updateSet(refusee, 'weight', '99999')
        saisie.updateSet(enFile, 'reps', '6')
        expect(valeursDeRepli()).toHaveLength(2)

        await saisie.flushAllPendingUpdates()
        await flushPromises()

        expect(refusee.weight).toBe(60)
        expect(enFile.reps).toBe(6)
        expect(valeursDeRepli()).toEqual([])
    })

    it('reste notée quand la réponse arrive pour une saisie déjà dépassée', async () => {
        const { saisie, patchSet, valeursDeRepli } = monter()
        const premiere = differee()
        const seconde = differee()
        patchSet.mockImplementationOnce(() => premiere.promesse).mockImplementationOnce(() => seconde.promesse)
        const set = { id: 7, weight: 60 }

        saisie.updateSet(set, 'weight', '80')
        // Partie sans attendre sa réponse : la saisie suivante la dépasse.
        saisie.flushPendingUpdates(7)
        await flushPromises()
        expect(patchSet).toHaveBeenCalledTimes(1)
        saisie.updateSet(set, 'weight', '85')

        premiere.resoudre({ data: { data: { id: 7, weight: 80, updated_at: 't1' } } })
        await flushPromises()
        expect(valeursDeRepli()).toHaveLength(1)

        vi.advanceTimersByTime(1500)
        await flushPromises()
        seconde.resoudre({ data: { data: { id: 7, weight: 85, updated_at: 't2' } } })
        await flushPromises()
        expect(set.weight).toBe(85)
        expect(valeursDeRepli()).toEqual([])
    })
})
