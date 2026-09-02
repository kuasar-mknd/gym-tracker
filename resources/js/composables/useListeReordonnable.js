import { onBeforeUnmount } from 'vue'

/**
 * Rend une liste déplaçable au doigt, sans composant enveloppant.
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
        if (conteneur() === null || instance !== null) {
            return
        }

        instance = new Sortable(element, {
            handle: options.handle,
            draggable: options.draggable,
            direction: 'vertical',

            /*
             * Le glisser natif du HTML n'existe pas au tactile. Le repli de
             * SortableJS le remplace par un CLONE qu'il déplace lui-même — et
             * c'est ce clone que `fallbackClass` habille.
             */
            forceFallback: true,
            fallbackClass: 'rangee-en-vol',
            dragClass: 'rangee-en-vol',

            /*
             * Posée sur l'ORIGINAL resté dans la liste, pas sur le clone. Sans
             * elle, la rangée se voit deux fois — l'original intact sous le
             * clone — et les deux titres se superposent.
             */
            ghostClass: 'rangee-creux',

            fallbackTolerance: 3,
            animation: 160,
            easing: 'cubic-bezier(0.2, 0.8, 0.2, 1)',

            /*
             * Le défaut oblige à traverser toute la rangée visée avant que
             * l'échange ne se fasse : c'est une part de la raideur ressentie.
             */
            swapThreshold: 0.65,

            /*
             * La bande de déclenchement du défilement automatique vaut 30 px
             * par défaut, entièrement cachée derrière la barre de navigation
             * flottante et l'en-tête collant.
             */
            scroll: true,
            scrollSensitivity: 96,
            scrollSpeed: 12,
            bubbleScroll: true,

            onStart: () => options.auDebut?.(),

            onEnd: ({ oldIndex, newIndex, item, from }) => {
                if (newIndex === oldIndex || oldIndex === undefined || newIndex === undefined) {
                    return
                }

                /*
                 * `oldIndex` compte les DÉPLAÇABLES ; `from.children` compte
                 * tous les enfants. Indexer l'un par l'autre range la ligne un
                 * rang à côté dès qu'un élément non déplaçable partage le
                 * conteneur.
                 */
                const rangs = [...from.children].filter((noeud) => noeud !== item && noeud.matches(options.draggable))

                // Rendre le nœud à sa place : le tableau est la source de
                // vérité, et Vue va rejouer le déplacement à partir de lui.
                from.insertBefore(item, rangs[oldIndex] ?? null)

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

    return { rafraichir, detacher }
}
