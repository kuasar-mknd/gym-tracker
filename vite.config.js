import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import vue from '@vitejs/plugin-vue';
import tailwindcss from '@tailwindcss/vite';
import { VitePWA } from 'vite-plugin-pwa';

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
            // Le worker est écrit dans public/build, donc servi depuis
            // /build/sw.js — sa portée par défaut serait /build/ et il ne
            // contrôlerait aucune page de l'app. base + scope l'enregistrent
            // pour la racine ; la route /sw.js le sert avec l'en-tête
            // Service-Worker-Allowed que le navigateur exige pour l'accepter.
            base: '/',
            buildBase: '/build/',
            scope: '/',
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
                        purpose: 'any maskable'
                    }
                ],
            }
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
                    if (id.includes('node_modules') && (id.includes('/vue/') || id.includes('@inertiajs'))) {
                        return 'vue-core';
                    }
                },
            },
        },
        chunkSizeWarningLimit: 1000,
    },
});
