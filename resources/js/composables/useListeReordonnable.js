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
export const useListeReordonnable = (conteneur, options) => {
    let montee = null
    let branche = false

    const attacher = async () => {
        if (conteneur.value === null || branche) {
            return
        }

        montee ??= import('@formkit/drag-and-drop/vue')

        const { dragAndDrop } = await montee

        if (conteneur.value === null || branche) {
            return
        }

        branche = true

        dragAndDrop({
            parent: conteneur,
            values: options.valeurs,

            dragHandle: options.handle,

            /*
             * PAS d'appui long. La poignée porte `touch-action: none` : un
             * contact dessus ne peut pas être un défilement, donc il n'y a
             * rien à distinguer. L'attente ne protégeait rien et rendait le
             * geste impossible à qui bouge le doigt tout de suite.
             */
            longPress: false,

            draggingClass: 'rangee-en-vol',
            synthDraggingClass: 'rangee-en-vol',
            dropZoneClass: 'rangee-creux',
            synthDropZoneClass: 'rangee-creux',

            onDragstart: () => options.auDebut?.(),

            /*
             * La bibliothèque a DÉJÀ réordonné le tableau : ce rappel ne doit
             * qu'écrire. Muter ici appliquerait le déplacement deux fois.
             */
            onSort: ({ previousPosition, position }) => {
                options.aLaFin?.(previousPosition, position)
            },
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
