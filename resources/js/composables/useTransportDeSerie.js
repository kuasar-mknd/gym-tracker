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
     * Fond une modification dans la création d'une série encore en file.
     *
     * Une série créée hors ligne n'a pas d'identifiant serveur, et ne l'aura
     * qu'au vidage. Sa saisie et sa coche rejoignent donc l'entrée qui la crée :
     * elles partent avec elle, survivent à un rechargement, et c'est la valeur
     * saisie, cochée, que le serveur enregistre (#1960).
     *
     * @returns {boolean} false quand la création n'attend plus en file : elle
     *   vole, ou elle a déjà abouti, et la modification suit l'identifiant réel.
     */
    const fondreDansLaFile = (set, payload) => {
        const fileDeLaSerie = pendingIds.fileDe(set.id)

        return fileDeLaSerie !== null && SyncService.modifierEnFile(fileDeLaSerie, payload)
    }

    /**
     * The only two ways this screen may talk to the server about a set.
     *
     * Both wait out a creation still in flight, so the URL always carries an id the
     * server issued. When the row has no server-side counterpart they reject with
     * the `isOffline` shape every caller here already understands: keep the value
     * on screen, change nothing, and mark it unsynced rather than send a request
     * that can only be refused.
     */
    const patchSet = (set, payload) => {
        if (fondreDansLaFile(set, payload)) {
            markUnsynced(set.id)

            return Promise.reject({ isOffline: true, message: 'Set creation queued; the change rides with it' })
        }

        return pendingIds.resolve(set.id).then((realId) => {
            if (realId === null) {
                markUnsynced(set.id)

                return Promise.reject({ isOffline: true, message: 'Set not created server-side yet' })
            }

            return SyncService.patch(route('api.v1.sets.update', { set: realId }), payload)
        })
    }

    /**
     * Une série dont la création attend encore en file n'a rien à supprimer sur
     * le serveur : sa création sort de la file, et sa promesse se règle à null.
     * Une création déjà partie, elle, se supprime par l'identifiant qu'elle rend.
     */
    const deleteSet = (setId) => {
        const fileDeLaSerie = pendingIds.fileDe(setId)

        if (fileDeLaSerie !== null) {
            SyncService.retirerDeLaFile(fileDeLaSerie)
        }

        return pendingIds.resolve(setId).then((realId) => {
            if (realId === null) {
                pendingIds.forget(setId)

                return Promise.reject({ isOffline: true, message: 'Set not created server-side yet' })
            }

            return SyncService.delete(route('api.v1.sets.destroy', { set: realId }))
        })
    }

    return { patchSet, deleteSet, fondreDansLaFile }
}
