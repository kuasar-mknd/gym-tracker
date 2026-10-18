<script>
/**
 * Ce que l'enveloppe d'un graphique (`Utils/graphiqueAsynchrone.js`) donne à
 * ce composant : la prévenir qu'il a pris la place du graphique, qu'elle
 * remontera à la réponse suivante du serveur.
 */
export const ECHEC_DU_GRAPHIQUE = Symbol('échec du graphique')
</script>

<script setup>
import { inject } from 'vue'
import IndisponibleHorsLigne from '@/Components/UI/IndisponibleHorsLigne.vue'

/**
 * Ce que montre un graphique dont le morceau ne se charge pas (#2048,
 * partie E) : le composant d'erreur commun des graphiques
 * (`Utils/graphiqueAsynchrone.js`). Les graphiques restent hors du precache
 * (décision 9 du propriétaire) : sans réseau, une page gardée les montre
 * ainsi, le reste de la page lisible.
 */
defineProps({
    /** L'erreur que `defineAsyncComponent` passe à son composant d'erreur : lue nulle part, déclarée pour ne pas finir en attribut. */
    error: {
        type: [Error, Object],
        default: null,
    },
})

inject(ECHEC_DU_GRAPHIQUE, null)?.()
</script>

<template>
    <div class="flex h-full min-h-24 items-center justify-center" data-graphique-indisponible>
        <IndisponibleHorsLigne texte="Graphique indisponible hors ligne" />
    </div>
</template>
