import '../css/app.css'
import './bootstrap'

// Initialize theme early to prevent flash of wrong theme
import { initTheme } from '@/composables/useTheme'
initTheme()

import { createInertiaApp, router } from '@inertiajs/vue3'
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers'
import { createApp, h } from 'vue'
import { ZiggyVue } from 'ziggy-js'
import * as Sentry from '@sentry/vue'
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

        /*
         * Le DSN vient du serveur, pas du build.
         *
         * `import.meta.env.VITE_SENTRY_DSN_PUBLIC` etait lu ici, et fourni nulle
         * part : ni le Dockerfile ni la CI ne le passaient, donc la condition
         * etait fausse dans chaque image et Sentry n'a jamais demarre (#1444).
         *
         * Le brancher au build aurait cuit le DSN dans l'image publiee — dépôt
         * public, image publique : toute installation aurait envoyé ses erreurs
         * au même projet. `window.SENTRY_CONFIG` est pose par app.blade.php a
         * partir d'une variable d'environnement, donc propre a chaque
         * deploiement. Le bloc etait deja rendu, et lu par personne.
         */
        const sentryConfig = window.SENTRY_CONFIG

        if (sentryConfig?.dsn) {
            Sentry.init({
                app,
                dsn: sentryConfig.dsn,
                environment: sentryConfig.environment,
                integrations: [Sentry.browserTracingIntegration(), Sentry.replayIntegration()],
                tracesSampleRate: 0.1, // 10% sampling for performance to stay in free tier
                replaysSessionSampleRate: 0.0, // Don't sample normal sessions
                replaysOnErrorSampleRate: 1.0, // BUT record 100% of sessions that result in an error
            })

            // Set user context if logged in
            const user = props.initialPage.props.auth?.user
            if (user) {
                Sentry.setUser({
                    id: user.id,
                    email: user.email,
                    username: user.name,
                })
            }
        }

        // Register custom directives
        app.directive('press', vPress)

        return app.mount(el)
    },
    progress: {
        color: '#4B5563',
    },
})
