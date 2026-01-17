import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';

/** @type {import('tailwindcss').Config} */
export default {
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
        './resources/js/**/*.vue',
    ],

    theme: {
        extend: {
            fontFamily: {
                sans: ['Space Grotesk', 'Inter', ...defaultTheme.fontFamily.sans],
                display: ['Archivo', 'sans-serif'],
                condensed: ['Barlow Condensed', 'sans-serif'],
                fat: ['Archivo Black', 'sans-serif'],
            },
            colors: {
                // New Liquid Glass Light palette
                'electric-orange': '#FF5500',
                'vivid-violet': '#8800FF',
                'hot-pink': '#FF0080',
                'neon-green': '#CCFF00',
                'cyan-pure': '#00E5FF',
                'magenta-pure': '#F5009B',
                'lime-pure': '#C0EB00',
                'pearl-white': '#F8FAFF',
                'surface-glass': 'rgba(255, 255, 255, 0.65)',
                'glass-border': 'rgba(255, 255, 255, 0.8)',
                'text-main': '#0F172A',
                'text-muted': '#64748B',

                // Category/Muscle accent colors
                'plate-red': '#EF4444',
                'plate-blue': '#3B82F6',
                'plate-yellow': '#EAB308',
                'plate-green': '#22C55E',

                // Legacy mappings for compatibility
                accent: {
                    primary: '#FF5500', // Now electric-orange
                    success: '#22C55E',
                    warning: '#EAB308',
                    danger: '#EF4444',
                    info: '#00E5FF',
                },
            },
            backdropBlur: {
                glass: '20px',
                'glass-strong': '24px',
            },
            borderRadius: {
                DEFAULT: '0.5rem',
                lg: '1rem',
                xl: '1.5rem',
                '2xl': '2rem',
                '3xl': '2.5rem',
            },
            spacing: {
                touch: '44px',
                nav: '70px',
                safe: 'env(safe-area-inset-bottom, 0px)',
            },
            animation: {
                'fade-in': 'fade-in 0.3s ease-out',
                'slide-up': 'slide-up 0.3s cubic-bezier(0.4, 0, 0.2, 1)',
                'scale-in': 'scale-in 0.2s cubic-bezier(0.4, 0, 0.2, 1)',
                'pulse-slow': 'pulse 3s ease-in-out infinite',
                'pulse-glow': 'pulse-glow 8s infinite alternate',
                'liquid-pulse': 'liquid-pulse 2s infinite',
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
                'pulse-glow': {
                    '0%': { opacity: '0.5', transform: 'scale(1)' },
                    '100%': { opacity: '0.8', transform: 'scale(1.1)' },
                },
                'liquid-pulse': {
                    '0%': { boxShadow: '0 0 0 0 rgba(204, 255, 0, 0.7)' },
                    '70%': { boxShadow: '0 0 0 15px rgba(204, 255, 0, 0)' },
                    '100%': { boxShadow: '0 0 0 0 rgba(204, 255, 0, 0)' },
                },
            },
            boxShadow: {
                'glow-orange': '0 0 20px rgba(255, 85, 0, 0.25), 0 10px 40px rgba(255, 85, 0, 0.15)',
                'glow-violet': '0 0 20px rgba(136, 0, 255, 0.25), 0 10px 40px rgba(136, 0, 255, 0.15)',
                'glow-pink': '0 0 20px rgba(255, 0, 128, 0.25), 0 10px 40px rgba(255, 0, 128, 0.15)',
                'glow-cyan': '0 0 20px rgba(0, 229, 255, 0.4)',
                'glow-magenta': '0 0 20px rgba(245, 0, 155, 0.4)',
                neon: '0 10px 30px rgba(204, 255, 0, 0.5)',
                'neon-blue': '0 10px 30px rgba(0, 240, 255, 0.4)',
                'neon-pink': '0 10px 30px rgba(255, 0, 153, 0.4)',
                'glass-inset': 'inset 0 0 20px rgba(255, 255, 255, 0.8)',
                'glass-out': '0 8px 32px rgba(136, 0, 255, 0.1)',
                'glass-card': '0 8px 32px 0 rgba(31, 38, 135, 0.07)',
                'plate-shadow': '2px 0 4px rgba(0,0,0,0.2)',
            },
            backgroundImage: {
                'gradient-main': 'linear-gradient(135deg, #FF5500 0%, #FF0080 50%, #8800FF 100%)',
                'gradient-orange-violet': 'linear-gradient(135deg, #FF5500 0%, #8800FF 100%)',
                'gradient-cyan-magenta': 'linear-gradient(135deg, #00E5FF 0%, #F5009B 100%)',
            },
        },
    },

    plugins: [forms],
};
