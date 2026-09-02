import { onBeforeUnmount } from 'vue'

/**
 * Rend une liste déplaçable au doigt.
 *
 * La bibliothèque est donnée-d'abord : elle mute le tableau qu'on lui confie,
 * et Vue garde la propriété du DOM. C'est ce qui évite d'avoir à défaire ses
 * déplacements — et avec eux toute une classe de défauts.
 *
 * Elle est importée à la MONTURE, après la peinture : la liste des exercices
 * est le cœur de la page de séance, elle ne doit pas attendre un morceau de
 * code pour s'afficher.
 *
 * @param {import('vue').Ref<HTMLElement | null>} conteneur
 * @param {{
 *   valeurs: import('vue').Ref<Array<unknown>>,
 *   handle: string,
 *   estActif: () => boolean,
 *   auDebut?: () => void,
 *   aLaFin?: (ancien: number, nouveau: number) => void,
 * }} options
 */
/**
 * La bibliothèque n'est importée qu'une fois pour toute la page, quel que soit
 * le nombre de listes qu'on lui confie.
 */
let montee = null

const bibliotheque = () => {
    montee ??= import('@formkit/drag-and-drop/vue')

    return montee
}

/**
 * Les réglages du geste, partagés par toutes les listes de la page.
 *
 * @param {string} handle
 * @param {{ auDebut?: () => void, aLaFin?: (ancien: number, nouveau: number) => void }} rappels
 */
const reglages = (handle, rappels) => ({
    dragHandle: handle,

    /*
     * PAS d'appui long. La poignée porte `touch-action: none` : ce contact ne
     * peut pas être un défilement, donc il n'y a rien à distinguer, et
     * l'attente empêchait de saisir qui bouge le doigt tout de suite.
     */
    longPress: false,

    draggingClass: 'rangee-en-vol',
    synthDraggingClass: 'rangee-en-vol',
    dropZoneClass: 'rangee-creux',
    synthDropZoneClass: 'rangee-creux',

    onDragstart: () => rappels.auDebut?.(),

    /*
     * La bibliothèque a DÉJÀ réordonné le tableau : ce rappel ne doit
     * qu'écrire. Muter ici appliquerait le déplacement deux fois.
     */
    onSort: ({ previousPosition, position }) => rappels.aLaFin?.(previousPosition, position),
})

export const useListeReordonnable = (conteneur, options) => {
    let branche = false

    const attacher = async () => {
        if (conteneur.value === null || branche) {
            return
        }

        const { dragAndDrop } = await bibliotheque()

        if (conteneur.value === null || branche) {
            return
        }

        branche = true

        dragAndDrop({
            parent: conteneur,
            values: options.valeurs,
            ...reglages(options.handle, options),
        })
    }

    const rafraichir = () => {
        if (options.estActif()) {
            void attacher()
        }
    }

    onBeforeUnmount(() => {
        branche = false
    })

    return { rafraichir }
}

/**
 * Le même geste, mais sur PLUSIEURS listes — une par exercice, pour ses séries.
 *
 * Un composable ne se boucle pas : il faut donc une seule instance qui lie
 * chaque conteneur au fur et à mesure qu'il apparaît. Une liste déjà liée ne
 * l'est jamais deux fois, sans quoi chaque rendu empilerait un écouteur.
 *
 * @param {() => Array<{ cle: string | number, element: HTMLElement | null, valeurs: import('vue').Ref<Array<unknown>> }>} entrees
 * @param {{
 *   handle: string,
 *   estActif: () => boolean,
 *   auDebut?: () => void,
 *   aLaFin?: (cle: string | number, ancien: number, nouveau: number) => void,
 * }} options
 */
export const useSousListesReordonnables = (entrees, options) => {
    const liees = new Set()

    const rafraichir = async () => {
        if (!options.estActif()) {
            return
        }

        const aLier = entrees().filter(({ cle, element }) => element !== null && !liees.has(cle))

        if (aLier.length === 0) {
            return
        }

        const { dragAndDrop } = await bibliotheque()

        for (const { cle, element, valeurs } of aLier) {
            if (liees.has(cle)) {
                continue
            }

            liees.add(cle)

            dragAndDrop({
                parent: element,
                values: valeurs,
                ...reglages(options.handle, {
                    auDebut: options.auDebut,
                    aLaFin: (ancien, nouveau) => options.aLaFin?.(cle, ancien, nouveau),
                }),
            })
        }
    }

    const oublier = (cle) => liees.delete(cle)

    onBeforeUnmount(() => liees.clear())

    return { rafraichir, oublier }
}
