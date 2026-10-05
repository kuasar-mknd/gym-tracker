import '../css/app.css'
import { jeton } from '@/Utils/couleurs'

import { createInertiaApp, http, router } from '@inertiajs/vue3'
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers'
import { createApp, h } from 'vue'
import { ZiggyVue } from 'ziggy-js'
import { installerLeRapporteurDErreurs } from '@/Utils/rapporteurDErreurs'
import { installerLaGardeDeLHistorique } from '@/Utils/historiqueDuCompte'
import { inscrireLeWorker } from '@/Utils/miseAJourDuWorker'
import { vPress } from './directives/vPress'
import { registerSW } from 'virtual:pwa-register'

/*
 * Le worker, et ce qu'une nouvelle version fait d'une page ouverte : rien sans
 * un geste, puis la navigation suivante en entier, si le serveur répond
 * (#1967). Voir le module.
 */
if (typeof window !== 'undefined') {
    inscrireLeWorker({ registerSW, routeur: router, http })
}

// Expose router for testing (Dusk)
window.Inertia = router

const appName = import.meta.env.VITE_APP_NAME || 'GymTracker'

createInertiaApp({
    title: (title) => `${title} - ${appName}`,
    resolve: (name) => resolvePageComponent(`./Pages/${name}.vue`, import.meta.glob('./Pages/**/*.vue')),
    setup({ el, App, props, plugin }) {
        const app = createApp({ render: () => h(App, props) })
            .use(plugin)
            /**
             * No config: ZiggyVue reads the global the @routes directive
             * defines in the page. Passing the Inertia prop meant shipping the
             * whole route table a second time — 34 KB per page, and again as
             * JSON on every Inertia navigation — for a table identical to the
             * one already inlined.
             */
            .use(ZiggyVue)

        // Les erreurs du navigateur restent chez nous : voir le rapporteur.
        installerLeRapporteurDErreurs(app)

        // Une page de compte rendue de mémoire après la déconnexion ne se montre pas (#1965).
        installerLaGardeDeLHistorique({ routeur: router, pageInitiale: props.initialPage })

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
