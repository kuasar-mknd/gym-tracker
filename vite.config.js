import { defineConfig } from 'vite'
import laravel from 'laravel-vite-plugin'
import vue from '@vitejs/plugin-vue'
import tailwindcss from '@tailwindcss/vite'
import { VitePWA } from 'vite-plugin-pwa'

export default defineConfig({
    plugins: [
        tailwindcss(),
        laravel({
            input: 'resources/js/main.js',
            refresh: true,
        }),
        vue({
            template: {
                transformAssetUrls: {
                    base: null,
                    includeAbsolute: false,
                },
            },
        }),
        VitePWA({
            strategies: 'injectManifest',
            srcDir: 'resources/js',
            filename: 'sw.js',
            registerType: 'autoUpdate',
            injectRegister: 'auto',
            /*
             * Le worker et le manifeste sortent dans public/, pas dans
             * public/build : servis en statique à la racine, ils couvrent la
             * portée / sans en-tête ni route Laravel, et aucun des deux
             * n'ouvre plus de session.
             */
            outDir: 'public',
            base: '/',
            buildBase: '/',
            scope: '/',
            injectManifest: {
                globDirectory: 'public/build',
                // woff2 n'est pas dans le glob par défaut : les polices auto-hébergées
                // sortaient du precache et le mode hors-ligne les perdait.
                globPatterns: ['**/*.{js,css,html,ico,png,svg,webp,woff2}'],
                manifestTransforms: [
                    (entries) => ({
                        manifest: entries.map((entry) =>
                            entry.url === 'manifest.webmanifest'
                                ? { ...entry, url: '/manifest.webmanifest' }
                                : entry.url.startsWith('/')
                                  ? entry
                                  : { ...entry, url: `/build/${entry.url}` },
                        ),
                    }),
                ],
            },
            // Le manifeste est un fichier versionné, public/manifest.webmanifest.
            manifest: false,
        }),
    ],
    server: {
        host: '0.0.0.0',
        https: false,
        hmr: {
            host: 'localhost',
        },
    },
    build: {
        rollupOptions: {
            output: {
                manualChunks(id) {
                    /**
                     * Only the runtime every page genuinely needs.
                     *
                     * A `return 'vendor'` catch-all here put ALL of node_modules
                     * in one chunk that main.js imports, so the 263 KB of
                     * chart.js rode along on every page — the workout screen
                     * included, which draws no charts at all. Naming chart.js
                     * its own chunk did not help: a manual chunk is still a
                     * static edge from the entry that reaches it. Letting
                     * Rollup split the rest by actual use is what makes the
                     * charts load with the pages that draw them.
                     */
                    /*
                     * `/vue/` seul est trop large : il attrape tout paquet qui
                     * expose un sous-chemin Vue — `@formkit/drag-and-drop/vue`
                     * s'est ainsi retrouve dans le morceau que CHAQUE page
                     * charge, alors qu'une seule s'en sert.
                     */
                    if (id.includes('node_modules/vue/') || id.includes('node_modules/@inertiajs')) {
                        return 'vue-core'
                    }
                },
            },
        },
        chunkSizeWarningLimit: 1000,
    },
})
