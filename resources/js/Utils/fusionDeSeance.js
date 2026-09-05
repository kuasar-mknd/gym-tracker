import { isTemporaryId } from '@/Utils/pendingIds'

/**
 * Rebuilds from the server's copy without discarding what it has not heard of.
 *
 * This watch used to assign the incoming props wholesale. Any ordinary Inertia
 * round trip on this page — renaming the session, correcting its start time —
 * therefore threw away every row still being created and every value the server
 * had refused, without a word. The user renamed their workout and the sets they
 * had just added disappeared.
 *
 * The server is authoritative for everything it knows about. It is not
 * authoritative about rows it has never been told of, nor about values it
 * rejected while the user kept theirs on screen.
 *
 * @param {object} server la seance telle que le serveur la rend
 * @param {object|null} local la seance telle que l'ecran la tient
 * @param {{
 *   estNonSynchronisee: (set: object) => boolean,
 *   validationEnVol: (set: object) => boolean,
 *   ordreLocalPrime: boolean,
 * }} garde ce que l'ecran sait et que le serveur ignore encore
 */
export const fusionnerLaSeance = (server, local, { estNonSynchronisee, validationEnVol, ordreLocalPrime }) => {
    const merged = JSON.parse(JSON.stringify(server))

    if (merged.workout_lines && !Array.isArray(merged.workout_lines)) {
        merged.workout_lines = Object.values(merged.workout_lines)
    }

    if (!Array.isArray(merged.workout_lines)) {
        merged.workout_lines = []
    }

    const localLines = Array.isArray(local?.workout_lines) ? local.workout_lines : []

    merged.workout_lines.forEach((line) => {
        if (!Array.isArray(line.sets)) line.sets = []

        const localLine = localLines.find((candidate) => candidate.id === line.id)

        if (!localLine) return

        /*
         * L'identite de la rangee survit au rafraichissement.
         *
         * Le serveur ne connait pas `_rowKey` : il est frappe ici, a la
         * creation. La copie JSON ci-dessus le perdait donc a chaque
         * rafraichissement de props — un renommage de seance, une correction
         * d'heure, un « enregistrer comme modele ».
         *
         * Deux consequences, et la seconde ne se voit pas. `rowKey()` sert de
         * `:key` au `v-for` : sans `_rowKey` il retombe sur l'id, donc la cle
         * de CHAQUE rangee change et Vue detruit puis reconstruit des lignes
         * qui n'ont pas bouge — etat de glissement perdu, champs recrees, focus
         * qui saute en pleine frappe. Et l'ordonnancement des ecritures est
         * indexe sur cette meme identite : deux appuis encadrant le
         * rafraichissement reprenaient deux cles, et les deux garde-fous
         * sautaient ensemble.
         */
        if (localLine._rowKey) line._rowKey = localLine._rowKey

        const localSets = Array.isArray(localLine.sets) ? localLine.sets : []

        // Still being created, or waiting in the offline queue.
        localSets.filter((set) => isTemporaryId(set.id)).forEach((set) => line.sets.push(set))

        // Marked unsynced means the server's copy is the stale one; taking it
        // would quietly undo an edit the user can see on screen.
        line.sets.forEach((set, index) => {
            const localSet = localSets.find((candidate) => candidate.id === set.id)

            if (!localSet) return

            if (estNonSynchronisee(set)) {
                line.sets[index] = localSet

                return
            }

            if (localSet._rowKey) set._rowKey = localSet._rowKey

            // La validation est partie, le serveur ne l'a pas encore : sa copie
            // est la perimee des deux.
            if (validationEnVol(localSet)) {
                set.is_completed = localSet.is_completed
            }
        })
    })

    // Whole exercises the server has never heard of.
    localLines.filter((line) => isTemporaryId(line.id)).forEach((line) => merged.workout_lines.push(line))

    // L'ordre local est le plus recent des deux tant que le deplacement n'a pas
    // atterri : le reprendre du serveur annulerait le geste a l'ecran.
    if (ordreLocalPrime) {
        const rang = new Map(localLines.map((line, index) => [String(line.id), index]))

        merged.workout_lines.sort((a, b) => (rang.get(String(a.id)) ?? Infinity) - (rang.get(String(b.id)) ?? Infinity))
    }

    return merged
}
