import { ref, onBeforeUnmount } from 'vue'

/**
 * Rend une liste deplaçable au doigt, sans composant enveloppant.
 *
 * Le patron habituel enrobe la liste dans un composant tiers. Ici la liste est
 * le cœur de la page de séance : un enrobage chargé de façon asynchrone la
 * ferait clignoter au premier rendu, et un enrobage synchrone mettrait
 * SortableJS dans le paquet initial de tout le monde. La bibliothèque est donc
 * importée à la MONTURE, après la peinture, et rattachée au conteneur existant.
 *
 * Le piège du glisser-déposer avec Vue tient en une ligne : SortableJS déplace
 * le nœud du DOM lui-même, alors que Vue croit encore à l'ordre du tableau. Les
 * deux divergent, et le rendu suivant réordonne par-dessus le déplacement déjà
 * appliqué. On rend donc le nœud à sa place AVANT de muter le tableau — Vue
 * refait ensuite le déplacement, à partir de la seule source de vérité.
 *
 * @param {() => HTMLElement | null} conteneur
 * @param {{
 *   handle: string,
 *   draggable: string,
 *   estActif: () => boolean,
 *   auDebut?: () => void,
 *   aLaFin?: (ancien: number, nouveau: number) => void,
 * }} options
 */
export const useListeReordonnable = (conteneur, options) => {
    const deplacementEnCours = ref(false)

    let instance = null
    let montee = null

    const detacher = () => {
        instance?.destroy()
        instance = null
    }

    const attacher = async () => {
        const element = conteneur()

        if (element === null || instance !== null) {
            return
        }

        montee ??= import('sortablejs')

        const { default: Sortable } = await montee

        // Le conteneur a pu disparaître pendant l'import.
        if (conteneur() === null) {
            return
        }

        instance = new Sortable(element, {
            handle: options.handle,
            draggable: options.draggable,
            animation: 150,
            // Le doigt doit pouvoir faire défiler la page : sans ce délai, tout
            // début de glissement vertical sur une poignée attrape la carte.
            delay: 120,
            delayOnTouchOnly: true,
            forceFallback: true,
            fallbackTolerance: 4,

            onStart: () => {
                deplacementEnCours.value = true
                options.auDebut?.()
            },

            onEnd: ({ oldIndex, newIndex, item, from }) => {
                deplacementEnCours.value = false

                if (newIndex === oldIndex || oldIndex === undefined || newIndex === undefined) {
                    return
                }

                // Rendre le nœud à sa place : le tableau est la source de vérité,
                // et Vue va rejouer le déplacement à partir de lui.
                from.insertBefore(item, from.children[oldIndex > newIndex ? oldIndex + 1 : oldIndex])

                options.aLaFin?.(oldIndex, newIndex)
            },
        })
    }

    const rafraichir = () => {
        if (options.estActif()) {
            void attacher()

            return
        }

        detacher()
    }

    onBeforeUnmount(detacher)

    return { deplacementEnCours, rafraichir, detacher }
}
