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

// Expose router for testing (Dusk)
window.Inertia = router

const appName = import.meta.env.VITE_APP_NAME || 'GymTracker'

createInertiaApp({
    title: (title) => `${title} - ${appName}`,
    resolve: (name) => resolvePageComponent(`./Pages/${name}.vue`, import.meta.glob('./Pages/**/*.vue')),
    setup({ el, App, props, plugin }) {
        const app = createApp({ render: () => h(App, props) })
            .use(plugin)
            .use(ZiggyVue, props.initialPage.props.ziggy)

        // Sentry disabled for debugging
        if (import.meta.env.VITE_SENTRY_DSN_PUBLIC) {
            Sentry.init({
                app,
                dsn: import.meta.env.VITE_SENTRY_DSN_PUBLIC,
                integrations: [Sentry.browserTracingIntegration(), Sentry.replayIntegration()],
                tracesSampleRate: 1.0,
                replaysSessionSampleRate: 0.1,
                replaysOnErrorSampleRate: 1.0,
            })
        }

        // Register custom directives
        app.directive('press', vPress)

        return app.mount(el)
    },
    progress: {
        color: '#4B5563',
    },
})
