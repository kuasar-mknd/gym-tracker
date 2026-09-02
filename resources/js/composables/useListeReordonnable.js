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
 *   appuiLong?: boolean,
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
const reglages = (handle, rappels, appuiLong) => ({
    dragHandle: handle,

    /*
     * La bibliothèque saisit au PREMIER mouvement, sans regarder la direction :
     * elle n'expose aucun seuil directionnel. Une poignée dédiée porte
     * `touch-action: none`, ce contact ne peut donc pas être autre chose et
     * l'attente ne ferait que gêner.
     *
     * Une rangée entière, elle, doit encore pouvoir glisser latéralement pour
     * se supprimer. Le temps est alors le seul arbitre : maintenir déplace,
     * glisser tout de suite supprime.
     */
    longPress: appuiLong,
    longPressDuration: 220,
    longPressClass: 'rangee-armee',

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
            ...reglages(options.handle, options, options.appuiLong === true),
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
 *   appuiLong?: boolean,
 *   auDebut?: (cle: string | number) => void,
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
                ...reglages(
                    options.handle,
                    {
                        auDebut: () => options.auDebut?.(cle),
                        aLaFin: (ancien, nouveau) => options.aLaFin?.(cle, ancien, nouveau),
                    },
                    options.appuiLong === true,
                ),
            })
        }
    }

    const oublier = (cle) => liees.delete(cle)

    onBeforeUnmount(() => liees.clear())

    return { rafraichir, oublier }
}
