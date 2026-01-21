import '../css/app.css'
import './bootstrap'

// Initialize theme early to prevent flash of wrong theme
import { initTheme } from '@/composables/useTheme'
initTheme()

import { createInertiaApp } from '@inertiajs/vue3'
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers'
import { createApp, h } from 'vue'
import { ZiggyVue } from 'ziggy-js'

const appName = import.meta.env.VITE_APP_NAME || 'GymTracker'

createInertiaApp({
    title: (title) => `${title} - ${appName}`,
    resolve: (name) => resolvePageComponent(`./Pages/${name}.vue`, import.meta.glob('./Pages/**/*.vue')),
    setup({ el, App, props, plugin }) {
        const app = createApp({ render: () => h(App, props) })
            .use(plugin)
            .use(ZiggyVue)

        // Register custom directives
        import('./directives/vPress').then((m) => {
            app.directive('press', m.vPress)
        })

        return app.mount(el)
    },
    progress: {
        color: '#4B5563',
    },
})
