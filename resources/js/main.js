import '../css/app.css'
import { jeton } from '@/Utils/couleurs'

import { createInertiaApp, router } from '@inertiajs/vue3'
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers'
import { createApp, h } from 'vue'
import { ZiggyVue } from 'ziggy-js'
import { installerLeRapporteurDErreurs } from '@/Utils/rapporteurDErreurs'
import { vPress } from './directives/vPress'
import { registerSW } from 'virtual:pwa-register'

// Register Service Worker
if (typeof window !== 'undefined') {
    /**
     * registerType est 'autoUpdate', mais fournir onNeedRefresh fait basculer
     * vite-plugin-pwa en mode prompt : il cesse d'appliquer la mise à jour et
     * te laisse la main. Ici la main écrivait dans la console et n'appelait
     * jamais updateSW, donc une nouvelle version restait indéfiniment en
     * attente — visible uniquement en réinstallant l'app.
     *
     * updateSW(true) applique la version en attente et recharge.
     */
    const updateSW = registerSW({
        immediate: true,
        onRegisteredSW(_url, registration) {
            // Le navigateur ne cherche un nouveau worker que sur une
            // navigation. Une PWA installée est suspendue, pas fermée : sans
            // ceci elle peut ne jamais regarder.
            if (registration) {
                setInterval(() => registration.update(), 60 * 60 * 1000)
            }
        },
        onNeedRefresh() {
            updateSW(true)
        },
    })
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
