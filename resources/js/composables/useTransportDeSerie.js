import SyncService from '@/Utils/SyncService'

/**
 * Les deux seules façons dont l'écran parle d'une série au serveur : une
 * modification et un retrait, toutes deux garées derrière la création encore
 * en vol, pour que l'adresse porte toujours un identifiant que le serveur a
 * émis.
 *
 * @param {{
 *   pendingIds: import('@/Utils/pendingIds').PendingIds,
 *   markUnsynced: (setId: unknown) => void,
 * }} page
 */
export const useTransportDeSerie = ({ pendingIds, markUnsynced }) => {
    /**
     * The only two ways this screen may talk to the server about a set.
     *
     * Both wait out a creation still in flight, so the URL always carries an id the
     * server issued. When the row has no server-side counterpart they reject with
     * the `isOffline` shape every caller here already understands: keep the value
     * on screen, change nothing, and mark it unsynced rather than send a request
     * that can only be refused.
     */
    const patchSet = (set, payload) =>
        pendingIds.resolve(set.id).then((realId) => {
            if (realId === null) {
                markUnsynced(set.id)

                return Promise.reject({ isOffline: true, message: 'Set not created server-side yet' })
            }

            return SyncService.patch(route('api.v1.sets.update', { set: realId }), payload)
        })

    const deleteSet = (setId) =>
        pendingIds.resolve(setId).then((realId) => {
            if (realId === null) {
                pendingIds.forget(setId)

                return Promise.reject({ isOffline: true, message: 'Set not created server-side yet' })
            }

            return SyncService.delete(route('api.v1.sets.destroy', { set: realId }))
        })

    return { patchSet, deleteSet }
}
