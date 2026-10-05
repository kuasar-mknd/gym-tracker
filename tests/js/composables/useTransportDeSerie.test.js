import { describe, it, expect, vi, beforeEach } from 'vitest'

const sync = vi.hoisted(() => ({ patch: vi.fn(), delete: vi.fn(), modifierEnFile: vi.fn(), retirerDeLaFile: vi.fn() }))
vi.mock('@/Utils/SyncService', () => ({ default: sync }))

import { useTransportDeSerie } from '@/composables/useTransportDeSerie'
import { PendingIds } from '@/Utils/pendingIds'

beforeEach(() => {
    vi.clearAllMocks()
    globalThis.route = (nom, params = {}) => `/${nom}/${Object.values(params).join('/')}`
})

const monter = () => {
    const pendingIds = new PendingIds()
    const markUnsynced = vi.fn()

    return { ...useTransportDeSerie({ pendingIds, markUnsynced }), pendingIds, markUnsynced }
}

describe('le transport d’une série', () => {
    it('attend l’identifiant que le serveur a émis avant d’écrire ou de retirer', async () => {
        const { patchSet, deleteSet, pendingIds } = monter()
        sync.patch.mockResolvedValue({ data: { data: { id: 12 } } })
        sync.delete.mockResolvedValue({})
        pendingIds.track('temp-1', Promise.resolve(12))

        await patchSet({ id: 'temp-1' }, { weight: 80 })
        await deleteSet('temp-1')

        expect(sync.patch).toHaveBeenCalledWith('/api.v1.sets.update/12', { weight: 80 })
        expect(sync.delete).toHaveBeenCalledWith('/api.v1.sets.destroy/12')
    })

    it('refuse « hors ligne » une série que le serveur ne connaît pas, en la marquant non synchronisée', async () => {
        const { patchSet, deleteSet, pendingIds, markUnsynced } = monter()
        pendingIds.track('temp-2', Promise.resolve(null))

        await expect(patchSet({ id: 'temp-2' }, { reps: 8 })).rejects.toMatchObject({ isOffline: true })
        expect(markUnsynced).toHaveBeenCalledWith('temp-2')
        expect(sync.patch).not.toHaveBeenCalled()

        pendingIds.track('temp-3', Promise.resolve(null))
        await expect(deleteSet('temp-3')).rejects.toMatchObject({ isOffline: true })
        expect(sync.delete).not.toHaveBeenCalled()
    })

    /**
     * Une série dont la création attend en file n'a pas d'identifiant : sa
     * modification rejoint l'entrée qui la crée, et son retrait la sort de la
     * file (#1960).
     */
    it('fond la modification d’une série encore en file dans sa création, et la garde à l’écran', async () => {
        const { patchSet, pendingIds, markUnsynced } = monter()
        pendingIds.track('temp-4', new Promise(() => {}))
        pendingIds.noterEnFile('temp-4', 'q4')
        sync.modifierEnFile.mockReturnValue(true)

        await expect(patchSet({ id: 'temp-4' }, { is_completed: true })).rejects.toMatchObject({ isOffline: true })

        expect(sync.modifierEnFile).toHaveBeenCalledWith('q4', { is_completed: true })
        expect(markUnsynced).toHaveBeenCalledWith('temp-4')
        expect(sync.patch).not.toHaveBeenCalled()
    })

    it('passe par l’identifiant réel quand la création n’attend plus en file', async () => {
        const { patchSet, pendingIds } = monter()
        let creee
        pendingIds.track('temp-5', new Promise((resolve) => (creee = resolve)))
        pendingIds.noterEnFile('temp-5', 'q5')
        sync.modifierEnFile.mockReturnValue(false)
        sync.patch.mockResolvedValue({})

        const ecriture = patchSet({ id: 'temp-5' }, { reps: 6 })
        creee(55)
        await ecriture

        expect(sync.patch).toHaveBeenCalledWith('/api.v1.sets.update/55', { reps: 6 })
    })

    it('sort de la file la création d’une série retirée avant d’être partie', async () => {
        const { deleteSet, pendingIds } = monter()
        let creee
        pendingIds.track('temp-6', new Promise((resolve) => (creee = resolve)))
        pendingIds.noterEnFile('temp-6', 'q6')
        sync.retirerDeLaFile.mockImplementation(() => {
            creee(null)

            return true
        })

        await expect(deleteSet('temp-6')).rejects.toMatchObject({ isOffline: true })

        expect(sync.retirerDeLaFile).toHaveBeenCalledWith('q6')
        expect(sync.delete).not.toHaveBeenCalled()
    })
})
