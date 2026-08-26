<script setup>
defineProps({
    variant: {
        type: String,
        /*
         * Quatre degres, du plus insistant au plus efface :
         *
         *   primary    — plein, vert d'etat sur encre. L'action de la page.
         *   default    — la carte de verre. Une action ordinaire.
         *   secondary  — contour seul, fond transparent. Le second choix.
         *   ghost      — rien jusqu'au survol. Ce qui ne doit pas attirer.
         *
         * `secondary` n'etait PAS implementee : elle n'apparaissait pas dans
         * l'objet de classes, donc ses dix boutons — six « Annuler » — rendaient
         * exactement comme `default`. Deux degres declares, un seul visible.
         */
        default: 'default', // default | primary | secondary | neon | gradient-border | danger | ghost
    },
    size: {
        type: String,
        default: 'md', // sm | md | lg | xl
    },
    loading: {
        type: Boolean,
        default: false,
    },
    disabled: {
        type: Boolean,
        default: false,
    },
    type: {
        type: String,
        default: 'button',
    },
    icon: {
        type: String,
        default: null,
    },
    ariaLabel: {
        type: String,
        default: null,
    },
})

const sizeClasses = {
    sm: 'min-h-[36px] px-4 py-2 text-sm rounded-xl',
    md: 'min-h-touch px-5 py-2.5 text-base rounded-xl',
    lg: 'min-h-[52px] px-6 py-3 text-lg rounded-2xl',
    xl: 'min-h-[64px] px-8 py-4 text-xl rounded-2xl',
}
</script>

<template>
    <button
        v-press
        :type="type"
        :disabled="disabled || loading"
        :aria-busy="loading"
        :aria-label="ariaLabel || $attrs['aria-label']"
        :title="ariaLabel"
        :class="[
            'glass-button focus-visible:ring-accent-primary transition-all focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:outline-none',
            sizeClasses[size],
            {
                'glass-button-primary': variant === 'primary',
                'glass-button-neon shadow-neon': variant === 'neon',
                'glass-button-gradient-border': variant === 'gradient-border',
                'border-accent-danger/30 bg-accent-danger/10 text-accent-danger-deep hover:bg-accent-danger/20':
                    variant === 'danger',
                'border-border-strong hover:bg-surface-sunken bg-transparent shadow-none backdrop-blur-none':
                    variant === 'secondary',
                'hover:bg-surface-card/50 border-transparent bg-transparent shadow-none': variant === 'ghost',
                'cursor-not-allowed opacity-50': disabled,
                'cursor-wait': loading,
            },
        ]"
    >
        <!-- Loading Spinner -->
        <svg
            v-if="loading"
            class="mr-2 h-5 w-5 animate-spin"
            xmlns="http://www.w3.org/2000/svg"
            fill="none"
            viewBox="0 0 24 24"
            aria-hidden="true"
        >
            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
            <path
                class="opacity-75"
                fill="currentColor"
                d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"
            />
        </svg>

        <!-- Icon (Material Symbols) -->
        <span
            v-if="icon && !loading"
            class="material-symbols-outlined mr-1 text-current"
            :style="{ fontSize: size === 'sm' ? '18px' : size === 'lg' ? '28px' : '24px' }"
            aria-hidden="true"
        >
            {{ icon }}
        </span>

        <slot />
    </button>
</template>
