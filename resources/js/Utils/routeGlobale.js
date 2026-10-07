import { route } from 'ziggy-js'

/**
 * Pose `route()` en globale, pour les `<script setup>` qui l'appellent sans
 * l'importer (#1969).
 *
 * `@routes` n'écrit plus dans la page que la table des routes (`const Ziggy`) :
 * la fonction y arrivait en script en ligne de 21 Ko, dans chaque page complète,
 * alors que le bundle la porte déjà pour `ZiggyVue`. Appelée sans
 * configuration, la fonction du paquet lit cette table globale.
 *
 * `main.js` l'appelle avant de monter l'application : aucune page ne peut
 * appeler `route()` avant.
 */
export const installerLaRouteGlobale = () => {
    globalThis.route = route
}
