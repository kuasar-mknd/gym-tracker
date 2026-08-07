/**
 * The bridge between a placeholder id and the real one the server gives back.
 *
 * Optimistic writes show a row instantly under an id the server has never seen
 * (`temp-4`). Every mutation that follows — editing a weight, deleting a set,
 * adding a set to an exercise — used to take that id and put it straight in a
 * URL or a payload. The server saw `PATCH /api/v1/sets/temp-3` and
 * `workout_line_id: "temp-1"`, both observed in production traffic.
 *
 * Live, that costs the edit: route model binding 404s and the `exists` rule
 * 422s. Queued, it is worse. A payload sitting in the offline queue keeps the
 * placeholder baked in, so when the queue drains the lines are created with
 * real ids while every queued set still points at `temp-1` and is refused. That
 * is exactly what happened to workout 8: five lines created inside two seconds
 * by a queue flush, and not one set survived.
 *
 * So no caller may send an id without asking here first. A placeholder either
 * resolves to the real id once the creation lands, or to null — meaning the row
 * does not exist server-side and no request about it can be worth making.
 */
const TEMP_PREFIX = 'temp-'

export const isTemporaryId = (id) => typeof id === 'string' && id.startsWith(TEMP_PREFIX)

export class PendingIds {
    constructor() {
        /** @type {Map<string, Promise<number|string|null>>} */
        this.promises = new Map()
        /** @type {Map<string, number|string|null>} */
        this.settled = new Map()
    }

    /**
     * Registers a placeholder against the creation that will replace it.
     *
     * @param {string} tempId
     * @param {Promise<*>} creation resolves with the real id, or null if the row
     *                              was never created (queued offline, or refused)
     */
    track(tempId, creation) {
        const settling = creation
            .then((realId) => realId ?? null)
            .catch(() => null)
            .then((realId) => {
                this.settled.set(tempId, realId)

                return realId
            })

        this.promises.set(tempId, settling)

        return settling
    }

    /**
     * The id to actually send, waiting for the server when it has to.
     *
     * Returns null when the row has no server-side counterpart, which is the
     * caller's signal to keep the value on screen and send nothing.
     *
     * @param {number|string} id
     * @returns {Promise<number|string|null>}
     */
    async resolve(id) {
        if (!isTemporaryId(id)) {
            return id
        }

        if (this.settled.has(id)) {
            return this.settled.get(id)
        }

        const pending = this.promises.get(id)

        // An untracked placeholder is not a row waiting on the network — it is a
        // row nobody ever tried to create. Sending anything about it is wrong.
        return pending ? await pending : null
    }

    /** Whether this placeholder is still waiting on the server. */
    isPending(id) {
        return isTemporaryId(id) && this.promises.has(id) && !this.settled.has(id)
    }

    forget(tempId) {
        this.promises.delete(tempId)
        this.settled.delete(tempId)
    }
}
