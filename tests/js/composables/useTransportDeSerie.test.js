import { describe, it, expect, vi, beforeEach } from 'vitest'

const sync = vi.hoisted(() => ({ patch: vi.fn(), delete: vi.fn() }))
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
})
