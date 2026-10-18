import { defineAsyncComponent } from 'vue'
import GraphiqueIndisponible from '@/Components/UI/GraphiqueIndisponible.vue'

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
 * @param {() => Promise<unknown>} chargeur l'import du composant du graphique
 */
export const graphiqueAsynchrone = (chargeur) =>
    defineAsyncComponent({ loader: chargeur, errorComponent: GraphiqueIndisponible })
