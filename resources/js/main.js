import '../css/app.css'
import { jeton } from '@/Utils/couleurs'

import { createInertiaApp, http, router } from '@inertiajs/vue3'
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers'
import { createApp, h } from 'vue'
import { ZiggyVue } from 'ziggy-js'
import { installerLeRapporteurDErreurs } from '@/Utils/rapporteurDErreurs'
import { installerLaGardeDeLHistorique } from '@/Utils/historiqueDuCompte'
import { inscrireLeWorker } from '@/Utils/miseAJourDuWorker'
import { installerLaRouteGlobale } from '@/Utils/routeGlobale'
import { vPress } from './directives/vPress'
import { registerSW } from 'virtual:pwa-register'

/*
 * Le worker, et ce qu'une nouvelle version fait d'une page ouverte : rien sans
 * un geste, puis la navigation suivante en entier, si le serveur répond
 * (#1967). Voir le module.
 */
if (typeof window !== 'undefined') {
    inscrireLeWorker({ registerSW, routeur: router, http })

    /*
     * Une page de compte ne se relit plus dans l'historique d'un onglet après
     * le départ du compte, quel que soit l'onglet où il est parti (#1965).
     * Avant `createInertiaApp`, qui lit l'historique dès son démarrage.
     */
    installerLaGardeDeLHistorique({ routeur: router })
}

// Expose router for testing (Dusk)
window.Inertia = router

// Les pages appellent route() en globale ; @routes n'écrit plus que la table.
installerLaRouteGlobale()

const appName = import.meta.env.VITE_APP_NAME || 'GymTracker'

createInertiaApp({
    title: (title) => `${title} - ${appName}`,
    resolve: (name) => resolvePageComponent(`./Pages/${name}.vue`, import.meta.glob('./Pages/**/*.vue')),
    setup({ el, App, props, plugin }) {
        const app = createApp({ render: () => h(App, props) })
            .use(plugin)
            /**
             * Sans configuration : ZiggyVue lit la table globale que `@routes`
             * écrit dans la page. La passer en prop Inertia envoyait toute la
             * table une seconde fois — 34 Ko par page, puis de nouveau en JSON
             * à chaque navigation Inertia — pour une table identique.
             */
            .use(ZiggyVue)

        // Les erreurs du navigateur restent chez nous : voir le rapporteur.
        installerLeRapporteurDErreurs(app)

        // Register custom directives
        app.directive('press', vPress)

        return app.mount(el)
    },
    progress: {
        /*
         * La barre de chargement d'Inertia. Elle veut une valeur, pas une
         * classe — on la prend donc dans la charte au démarrage, une seule fois.
         */
        color: jeton('text-muted'),
    },
})
