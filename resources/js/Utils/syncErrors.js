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
 * - `permanent` the server refused it (4xx). Retrying cannot change that.
 * - `transient` a 5xx, a timeout, anything unattributable. Worth another try.
 *
 * 429 is deliberately transient: SyncService retries it once itself, and a rate
 * limit is by definition temporary.
 */
export const SYNC_OFFLINE = 'offline'
export const SYNC_PERMANENT = 'permanent'
export const SYNC_TRANSIENT = 'transient'

export const classifySyncError = (error) => {
    if (error?.isOffline) {
        return SYNC_OFFLINE
    }

    if (!navigator.onLine || error?.code === 'ERR_NETWORK') {
        return SYNC_OFFLINE
    }

    const status = error?.response?.status

    if (typeof status !== 'number') {
        return SYNC_TRANSIENT
    }

    if (status === 429) {
        return SYNC_TRANSIENT
    }

    return status >= 400 && status < 500 ? SYNC_PERMANENT : SYNC_TRANSIENT
}
