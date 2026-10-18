import { defineAsyncComponent, defineComponent, h, onBeforeUnmount, provide, ref } from 'vue'
import GraphiqueIndisponible, { ECHEC_DU_GRAPHIQUE } from '@/Components/UI/GraphiqueIndisponible.vue'
import { surUneReponseDuServeur, useEtatDuReseau } from '@/composables/useEtatDuReseau'

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
 * **Pas d'import quand le serveur ne répond pas.** Le navigateur garde l'échec
 * d'un `import()` pour toute la vie du document, sans plus rien demander au
 * réseau : Chrome 141 rejette chaque nouvel essai de la même adresse, réseau
 * revenu compris (`NavigationHorsLigneTest`). Un graphique essayé sans réseau
 * sur une copie restait donc « indisponible hors ligne » jusqu'au
 * rechargement de toute l'application. Tant que `joignable` est faux (une
 * copie montrée, un échec du réseau), un graphique que la page n'a pas encore
 * chargé n'est pas demandé : il dit d'emblée qu'il est indisponible, et se
 * demande à la réponse suivante du serveur (`surUneReponseDuServeur` : une
 * visite, `http()`, le rejeu de la file, la sonde). Un graphique déjà chargé
 * dans le document se dessine, réseau ou non.
 *
 * **Un graphique en échec se redemande à la réponse suivante du serveur**,
 * une fois par réponse et seulement après un échec : le rechargement complet
 * d'une copie garde la page montée (`router.reload` impose `preserveState`),
 * et le composant asynchrone garde son échec tant qu'il n'est pas remonté.
 * L'enveloppe le remonte par sa clé quand `GraphiqueIndisponible` l'a
 * prévenue (`ECHEC_DU_GRAPHIQUE`) ; un graphique dessiné ne se remonte
 * jamais. Elle passe au graphique ses props, attributs et créneaux, telle
 * quelle.
 *
 * @param {() => Promise<unknown>} chargeur l'import du composant du graphique
 */
export const graphiqueAsynchrone = (chargeur) => {
    /** Le morceau s'est chargé une fois dans ce document : le reprendre ne demande plus rien au réseau. */
    let charge = false

    const Graphique = defineAsyncComponent({
        loader: () =>
            chargeur().then((module) => {
                charge = true

                return module
            }),
        errorComponent: GraphiqueIndisponible,
    })

    return defineComponent({
        // Un nom à elle : une recherche par le nom du graphique trouve le graphique, pas son enveloppe.
        name: 'GraphiqueAsynchrone',
        inheritAttrs: false,
        setup(_props, { attrs, slots }) {
            const { joignable } = useEtatDuReseau()
            const montage = ref(0)
            const attendreLeServeur = ref(!charge && !joignable.value)
            let enEchec = false

            provide(ECHEC_DU_GRAPHIQUE, () => {
                enEchec = true
            })

            const arreter = surUneReponseDuServeur(() => {
                if (attendreLeServeur.value) {
                    attendreLeServeur.value = false
                    enEchec = false
                } else if (enEchec) {
                    enEchec = false
                    montage.value += 1
                }
            })

            onBeforeUnmount(arreter)

            return () =>
                attendreLeServeur.value
                    ? h(GraphiqueIndisponible)
                    : h(Graphique, { ...attrs, key: montage.value }, slots)
        },
    })
}
