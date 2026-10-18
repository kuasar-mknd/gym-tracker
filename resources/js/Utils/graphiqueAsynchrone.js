import { defineAsyncComponent, defineComponent, h, onBeforeUnmount, provide, ref } from 'vue'
import GraphiqueIndisponible, { ECHEC_DU_GRAPHIQUE } from '@/Components/UI/GraphiqueIndisponible.vue'
import { surUneReponseDuServeur } from '@/composables/useEtatDuReseau'

/**
 * Un graphique chargé à la demande, avec le composant d'erreur commun (#2048,
 * partie E).
 *
 * Chart.js n'entre que par le morceau d'un graphique (`chartChunks.test.js`),
 * et ces morceaux restent hors du precache (décision 9 du propriétaire). Sans
 * réseau, l'import échouait, et le graphique laissait un trou dans une page
 * gardée qu'une copie hors ligne montre : il dit désormais « Graphique
 * indisponible hors ligne » (`GraphiqueIndisponible.vue`). Chaque graphique
 * passe par ici, jamais par `defineAsyncComponent` seul
 * (`tests/js/conventions/chartChunks.test.js`).
 *
 * Un graphique en échec se redemande à la réponse suivante du serveur
 * (`surUneReponseDuServeur` : une visite, `http()`, le rejeu de la file, la
 * sonde). Le rechargement complet d'une copie au retour du réseau garde la
 * page montée (`router.reload` impose `preserveState`), et le composant
 * asynchrone garde son échec tant qu'il n'est pas remonté : le graphique
 * disait encore « indisponible hors ligne » sur une page qui ne l'était plus.
 * Une enveloppe le remonte donc, par sa clé, une fois par réponse et
 * seulement après un échec ; un graphique dessiné ne se remonte jamais. Elle
 * passe au graphique ses props, attributs et créneaux, telle quelle.
 *
 * @param {() => Promise<unknown>} chargeur l'import du composant du graphique
 */
export const graphiqueAsynchrone = (chargeur) => {
    const Graphique = defineAsyncComponent({ loader: chargeur, errorComponent: GraphiqueIndisponible })

    return defineComponent({
        // Un nom à elle : une recherche par le nom du graphique trouve le graphique, pas son enveloppe.
        name: 'GraphiqueAsynchrone',
        inheritAttrs: false,
        setup(_props, { attrs, slots }) {
            const montage = ref(0)
            let enEchec = false

            provide(ECHEC_DU_GRAPHIQUE, () => {
                enEchec = true
            })

            const arreter = surUneReponseDuServeur(() => {
                if (enEchec) {
                    enEchec = false
                    montage.value += 1
                }
            })

            onBeforeUnmount(arreter)

            return () => h(Graphique, { ...attrs, key: montage.value }, slots)
        },
    })
}
