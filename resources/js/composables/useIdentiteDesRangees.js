import { PendingIds } from '@/Utils/pendingIds'

/** Ce qui identifie une rangee a l'ecran avant et apres que le serveur l'ait nommee. */
export const useIdentiteDesRangees = () => {
    let tempIdCounter = 0
    const nouvelIdTemporaire = () => `temp-${++tempIdCounter}`

    /**
     * A row's identity for Vue, which must not change while the row is on screen.
     *
     * Ids do change here: an optimistic row wears `temp-4` until the server answers
     * and is then given the real one. Keying a v-for on that made the key change
     * under a row the user was typing into, and Vue answers a changed key by
     * destroying the node and building a new one — the input, its half-typed value
     * and its focus with it.
     *
     * So optimistic rows carry a key issued once at creation, and rows that came
     * from the server fall back to their id, which for them never changes. The two
     * cannot collide: one is a string with a prefix, the other a number.
     */
    let rowKeyCounter = 0
    const newRowKey = () => `row-${++rowKeyCounter}`
    const rowKey = (row) => row._rowKey ?? row.id

    /**
     * Placeholder ids never leave this component. Every mutation that names a line
     * or a set asks here for the id to actually send, and waits if the creation is
     * still in flight — see Utils/pendingIds for what sending `temp-3` cost.
     */
    const pendingIds = new PendingIds()

    /**
     * Placeholders whose create is sitting in the offline queue rather than in
     * flight. Anything added on top of one has to wait for the drain, and say so
     * meanwhile.
     */
    const queuedLineIds = new Set()

    return { nouvelIdTemporaire, newRowKey, rowKey, pendingIds, queuedLineIds }
}
