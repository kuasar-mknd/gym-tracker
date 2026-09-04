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
            /**
             * Le worker est écrit dans public/build. Laissé à lui-même il est
             * donc enregistré depuis /build/sw.js, ce qui lui donne la portée
             * /build/ : il ne contrôle aucune page de l'application.
             *
             * buildBase décide de l'URL d'enregistrement, et valait '/build/' —
             * le navigateur refusait donc la portée '/' demandée, quatre fois
             * par chargement dans la console. Ajouter une route /sw.js portant
             * Service-Worker-Allowed ne suffisait pas : rien ne l'enregistrait.
             * C'est cette URL-là qu'il faut demander.
             */
            base: '/',
            buildBase: '/',
            scope: '/',
            /**
             * woff2 is not in the default glob, so the self-hosted faces were
             * emitted, fingerprinted, and then left out of the precache — the
             * app would have kept fetching them over the network and shown
             * nothing but fallback type offline. The point of self-hosting is
             * that the second visit needs no network at all.
             */
            injectManifest: {
                globPatterns: ['**/*.{js,css,html,ico,png,svg,webp,woff2}'],
                /**
                 * Les entrées du precache sont relatives au dossier de sortie
                 * (`assets/…`) et le worker est servi depuis /sw.js : sans ce
                 * préfixe elles pointaient sur /assets/…, 404 pour chacune, et
                 * Workbox annulait toute l'installation. Le worker ne s'est
                 * jamais activé en production depuis que /sw.js existe (#1683).
                 */
                manifestTransforms: [
                    (entries) => ({
                        // Le plugin ajoute lui-même `manifest.webmanifest` sans passer
                        // par modifyURLPrefix : la transformation couvre tout.
                        manifest: entries.map((entry) =>
                            entry.url.startsWith('/') ? entry : { ...entry, url: `/build/${entry.url}` },
                        ),
                    }),
                ],
            },
            manifest: {
                name: 'Gym Tracker',
                short_name: 'GymTracker',
                description: 'Suivez vos entraînements et progressez efficacement.',
                start_url: '/',
                scope: '/',
                theme_color: '#0f172a',
                background_color: '#0f172a',
                display: 'standalone',
                orientation: 'portrait',
                icons: [
                    {
                        src: '/logo.svg',
                        sizes: '192x192 512x512',
                        type: 'image/svg+xml',
                        purpose: 'any maskable',
                    },
                ],
            },
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
