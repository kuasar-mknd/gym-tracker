import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import vue from '@vitejs/plugin-vue';
import { VitePWA } from 'vite-plugin-pwa';

export default defineConfig({
    plugins: [
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
});
