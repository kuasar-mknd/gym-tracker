/**
 * What a failed sync attempt means, in the three terms that decide what to do
 * with the local edit behind it.
 *
 * The distinction that matters is permanent vs transient. Both used to be
 * treated the same way, in both directions and in both places: the draft replay
 * in Workouts/Show kept retrying a request the server will never accept, and
 * SyncService.processQueue threw away a queued mutation on any failure at all —
 * a 500 or a rejected token cost the user their edit, with a console.error as
 * the only trace.
 *
 * - `offline`   SyncService has queued the request; it will go out later.
 * - `auth`      401 ou 419 : la session ou le jeton CSRF a expiré pendant que
 *               la PWA dormait. La porte est fermée, pas l'écriture refusée :
 *               elle repart telle quelle après reconnexion.
 * - `permanent` the server refused it (4xx). Retrying cannot change that.
 * - `transient` a 5xx, a timeout, anything unattributable. Worth another try.
 *
 * 429 is deliberately transient: SyncService retries it once itself, and a rate
 * limit is by definition temporary.
 */
export const SYNC_OFFLINE = 'offline'
export const SYNC_AUTH = 'auth'
export const SYNC_PERMANENT = 'permanent'
export const SYNC_TRANSIENT = 'transient'

export const classifySyncError = (error) => {
    if (error?.isOffline) {
        return SYNC_OFFLINE
    }

    /**
     * Whether the server answered is the fact that settles this, not
     * `navigator.onLine`. That flag reports the network interface, not
     * reachability, and an iOS PWA reports it false at a cold launch while the
     * connection is perfectly usable — which used to file a legitimate 422 as
     * "offline" and queue it forever instead of surfacing it.
     */
    if (error?.code === 'ERR_NETWORK' || (!error?.response && error?.request)) {
        return SYNC_OFFLINE
    }

    const status = error?.response?.status

    if (typeof status !== 'number') {
        return SYNC_TRANSIENT
    }

    if (status === 429) {
        return SYNC_TRANSIENT
    }

    if (status === 401 || status === 419) {
        return SYNC_AUTH
    }

    return status >= 400 && status < 500 ? SYNC_PERMANENT : SYNC_TRANSIENT
}
