import { fileURLToPath } from 'node:url'
import { defineConfig } from 'vite'
import laravel from 'laravel-vite-plugin'
import vue from '@vitejs/plugin-vue'
import tailwindcss from '@tailwindcss/vite'
import { VitePWA } from 'vite-plugin-pwa'
import { FICHIER_DE_LA_PAGE_HORS_LIGNE, greffonDeLaPageHorsLigne } from './resources/js/sw/pageHorsLigne.js'
import { iconesDuPrecache } from './resources/js/sw/iconesDuPrecache.js'
import { greffonDeLaFermetureDuPrecache } from './resources/js/sw/fermetureDuPrecache.js'

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
        // Écrite avant que le worker ne se construise, pour que le precache la prenne (#1966).
        greffonDeLaPageHorsLigne(),
        // Ce que l'entrée et les pages de la salle demandent, lu dans le manifeste construit (#2048).
        greffonDeLaFermetureDuPrecache(),
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
                /*
                 * Ce que l'installation télécharge, et rien de plus.
                 *
                 * Le glob prenait tout : 142 fichiers, 1 249 Kio, dont les 200
                 * Kio du graphique et le morceau de chaque page jamais visitée
                 * (#1814). Les actifs portent leur hachage et un an de cache
                 * immuable — le navigateur les garde déjà.
                 *
                 * Les motifs ne prennent plus que la page « hors ligne », les
                 * polices latines et celle des icônes (Material Symbols, dont
                 * `cloud_off`, #2048). Le code vient de la fermeture de
                 * l'entrée et des pages de la salle (`pagesGardees.js`), que
                 * `greffonDeLaFermetureDuPrecache` lit dans le manifeste
                 * construit : chacune s'ouvre sans réseau depuis sa copie,
                 * sauf ses graphiques, nommés « indisponibles hors ligne »
                 * (resources/js/sw/fermetureDuPrecache.js). Des motifs par nom
                 * (`assets/Show-*.js`…) laissaient à la séance une partie de
                 * ses morceaux, et sa feuille. Le reste se met en cache à la
                 * première visite, dans le worker. Les icônes de public/
                 * viennent à part, par `additionalManifestEntries` : ce glob
                 * ne lit que public/build.
                 *
                 * Le seul document précaché est la page « hors ligne », qui ne
                 * porte rien d'un compte (resources/js/sw/navigationsHorsLigne.js,
                 * #1966) ; les copies des pages sont l'affaire de la page.
                 */
                globPatterns: [
                    FICHIER_DE_LA_PAGE_HORS_LIGNE,
                    'assets/*latin-*.woff2',
                    'assets/material-symbols-outlined-*.woff2',
                ],
                globIgnores: ['**/*latin-ext-*.woff2'],
                additionalManifestEntries: iconesDuPrecache(fileURLToPath(new URL('./public', import.meta.url))),
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
