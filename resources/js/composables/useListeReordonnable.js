import { ref, onBeforeUnmount } from 'vue'

/**
 * Rend une liste déplaçable au doigt, et replie ses éléments pendant le geste.
 *
 * Le repli ne peut pas attendre le démarrage du glissement : la bibliothèque
 * fabrique alors un nœud de substitution à partir de ce qu'elle voit, et
 * replier ensuite laisse à l'écran une carte pleine — séries comprises —
 * au-dessus d'une liste qui vient de s'effondrer. Filmé sur simulateur : deux
 * titres superposés, la page raccourcie de 800 px.
 *
 * On écoute donc le CONTACT soi-même, et l'appui long de la bibliothèque laisse
 * à Vue le temps de rendre le repli avant que le geste ne commence. Un seul
 * geste : j'appuie, ça se replie, je glisse.
 *
 * La bibliothèque est importée à la monture, après la peinture : la liste des
 * exercices est le cœur de la page de séance, elle ne doit pas attendre un
 * morceau de code pour s'afficher.
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
    const cartesRepliees = ref(false)

    let montee = null
    let branche = false
    let enGlissement = false

    const relacher = () => {
        if (!enGlissement) {
            cartesRepliees.value = false
        }
    }

    /** Le repli part au CONTACT, pas au démarrage du glissement. */
    const surAppui = (evenement) => {
        if (evenement.target?.closest?.(options.handle) === null) {
            return
        }

        cartesRepliees.value = true
        options.auDebut?.()

        window.addEventListener('pointerup', relacher, { once: true })
        window.addEventListener('pointercancel', relacher, { once: true })
    }

    const detacher = () => {
        conteneur.value?.removeEventListener('pointerdown', surAppui)
        enGlissement = false
        cartesRepliees.value = false
    }

    const attacher = async () => {
        const element = conteneur.value

        if (element === null || branche) {
            return
        }

        montee ??= import('@formkit/drag-and-drop/vue')

        const { dragAndDrop } = await montee

        if (conteneur.value === null || branche) {
            return
        }

        branche = true

        element.addEventListener('pointerdown', surAppui)

        dragAndDrop({
            parent: conteneur,
            values: options.valeurs,

            dragHandle: options.handle,

            /*
             * L'appui long laisse à Vue le temps de rendre le repli avant que
             * le geste ne commence — et il évite qu'un simple contact sur la
             * poignée n'emporte la carte.
             */
            longPress: true,
            longPressDuration: 250,

            draggingClass: 'rangee-en-vol',
            synthDraggingClass: 'rangee-en-vol',
            dropZoneClass: 'rangee-creux',
            synthDropZoneClass: 'rangee-creux',

            onDragstart: () => {
                enGlissement = true
            },

            onDragend: () => {
                enGlissement = false
                cartesRepliees.value = false
            },

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

    onBeforeUnmount(detacher)

    return { cartesRepliees, rafraichir, detacher }
}
