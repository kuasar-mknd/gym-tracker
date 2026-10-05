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
        /**
         * Les identifiants provisoires dont la création attend dans la file hors
         * ligne, par entrée de file. Jusqu'au vidage, cette entrée est le seul
         * endroit où une modification de la rangée survit à un rechargement.
         *
         * @type {Map<string, string>}
         */
        this.enFile = new Map()
        /**
         * Ceux qui attendent par `reference()` de savoir si une création aboutit
         * ou part en file, selon ce qui arrive d'abord.
         *
         * @type {Map<string, Set<(reference: unknown) => void>>}
         */
        this.attentes = new Map()
    }

    /** Donne `reference` à tous ceux qui attendent cet identifiant provisoire, et les oublie. */
    reveiller(tempId, reference) {
        this.attentes.get(tempId)?.forEach((resoudre) => resoudre(reference))
        this.attentes.delete(tempId)
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
                this.enFile.delete(tempId)
                this.reveiller(tempId, realId)

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

    /**
     * Note que la création de cet identifiant provisoire est partie dans la
     * file hors ligne, sous cette entrée. La création suivie par `track`
     * continue d'attendre le vidage (#1960).
     *
     * @param {string} tempId
     * @param {string} queueId
     */
    noterEnFile(tempId, queueId) {
        this.enFile.set(tempId, queueId)
        this.reveiller(tempId, { enAttenteDe: queueId })
    }

    /**
     * L'entrée de file qui tient encore la création de cet identifiant, ou null
     * une fois rejouée, refusée ou oubliée — ou si elle n'a jamais été en file.
     *
     * @param {number|string} id
     * @returns {string|null}
     */
    fileDe(id) {
        return this.enFile.get(id) ?? null
    }

    /**
     * Ce qu'une charge peut nommer pour cette rangée, dès que c'est connu.
     *
     * L'identifiant réel une fois la création aboutie. Tant qu'elle attend dans
     * la file hors ligne, une référence à cette entrée — `{ enAttenteDe: queueId }` —
     * que seul `SyncService.mettreEnFile` accepte, et que le vidage remplace par
     * l'identifiant réel au moment de l'envoi. Attendre l'identifiant lui-même
     * gardait en mémoire, jusqu'au vidage, une série ajoutée sous un exercice en
     * file, et un rechargement la perdait (#1962).
     *
     * @param {number|string} id
     * @returns {Promise<number|string|{enAttenteDe: string}|null>}
     */
    async reference(id) {
        if (!isTemporaryId(id)) {
            return id
        }

        if (this.settled.has(id)) {
            return this.settled.get(id)
        }

        if (this.enFile.has(id)) {
            return { enAttenteDe: this.enFile.get(id) }
        }

        if (!this.promises.has(id)) {
            return null
        }

        return new Promise((resoudre) => {
            const attentes = this.attentes.get(id) ?? new Set()

            attentes.add(resoudre)
            this.attentes.set(id, attentes)
        })
    }

    /** Whether this placeholder is still waiting on the server. */
    isPending(id) {
        return isTemporaryId(id) && this.promises.has(id) && !this.settled.has(id)
    }

    forget(tempId) {
        this.promises.delete(tempId)
        this.settled.delete(tempId)
        this.enFile.delete(tempId)
        this.reveiller(tempId, null)
    }
}
