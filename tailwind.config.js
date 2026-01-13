import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';

/** @type {import('tailwindcss').Config} */
export default {
    darkMode: 'class',

    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
        './resources/js/**/*.vue',
    ],

    theme: {
        extend: {
            fontFamily: {
                sans: ['Inter', 'Figtree', ...defaultTheme.fontFamily.sans],
            },
            colors: {
                glass: {
                    light: 'rgba(255, 255, 255, 0.08)',
                    DEFAULT: 'rgba(255, 255, 255, 0.12)',
                    strong: 'rgba(255, 255, 255, 0.18)',
                    border: 'rgba(255, 255, 255, 0.15)',
                },
                accent: {
                    primary: '#818cf8',
                    success: '#34d399',
                    warning: '#fbbf24',
                    danger: '#f87171',
                    info: '#60a5fa',
                },
                dark: {
                    900: '#0f0f23',
                    800: '#16213e',
                    700: '#1a1a2e',
                    600: '#252545',
                },
            },
            backdropBlur: {
                glass: '20px',
                'glass-strong': '40px',
            },
            borderRadius: {
                'glass': '1.25rem',
                'glass-lg': '1.5rem',
            },
            spacing: {
                'touch': '44px',
                'nav': '70px',
                'safe': 'env(safe-area-inset-bottom, 0px)',
            },
            animation: {
                'fade-in': 'fade-in 0.3s ease-out',
                'slide-up': 'slide-up 0.3s cubic-bezier(0.4, 0, 0.2, 1)',
                'scale-in': 'scale-in 0.2s cubic-bezier(0.4, 0, 0.2, 1)',
                'pulse-slow': 'pulse 3s ease-in-out infinite',
            },
            keyframes: {
                'fade-in': {
                    from: { opacity: '0' },
                    to: { opacity: '1' },
                },
                'slide-up': {
                    from: { opacity: '0', transform: 'translateY(20px)' },
                    to: { opacity: '1', transform: 'translateY(0)' },
                },
                'scale-in': {
                    from: { opacity: '0', transform: 'scale(0.95)' },
                    to: { opacity: '1', transform: 'scale(1)' },
                },
            },
            boxShadow: {
                'glass': '0 4px 30px rgba(0, 0, 0, 0.1)',
                'glass-glow': '0 4px 20px rgba(102, 126, 234, 0.4)',
            },
        },
    },

    plugins: [forms],
};
