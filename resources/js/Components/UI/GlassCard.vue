<script setup>
/**
 * GlassCard.vue
 *
 * A reusable container component that applies the consistent "Liquid Glass"
 * aesthetic to its content. It serves as the primary structural element for
 * grouping related information or actions throughout the application.
 *
 * The card supports multiple visual variants, configurable padding and
 * border-radius, and optional hover effects (translation and shadow).
 */

/**
 * Component Props
 *
 * @property {String} variant - The visual style of the card.
 *   - 'default': Standard translucent glass look.
 *   - 'iridescent': A multi-color animated border effect.
 *   - 'glow-orange': A soft orange drop shadow.
 *   - 'glow-violet': A soft violet drop shadow.
 *   - 'solid': An opaque white background.
 * @property {String} as - The HTML element or Vue component to render as the root node (default: 'div').
 * @property {String} padding - Tailwind utility classes for the card's inner padding (default: 'p-5 sm:p-6').
 * @property {String} rounded - Tailwind utility classes for the card's border radius (default: 'rounded-3xl').
 * @property {Boolean} hover - Enables hover effects: translates the card up slightly and adds a stronger drop shadow (default: false).
 */
defineProps({
    variant: {
        type: String,
        default: 'default', // default | iridescent | glow-orange | glow-violet | solid
    },
    as: {
        type: String,
        default: 'div',
    },
    padding: {
        type: String,
        default: 'p-5 sm:p-6',
    },
    rounded: {
        type: String,
        default: 'rounded-3xl',
    },
    hover: {
        type: Boolean,
        default: false,
    },
})
</script>

<template>
    <component
        :is="as"
        :class="[
            'glass-panel-light animate-fade-in transition-all duration-300',
            padding,
            rounded,
            {
                'cursor-pointer hover:-translate-y-1 hover:shadow-lg': hover,
                'iridescent-card': variant === 'iridescent',
                'shadow-glow-orange': variant === 'glow-orange',
                'shadow-glow-violet': variant === 'glow-violet',
                'bg-white/90 backdrop-blur-none dark:bg-slate-800/90': variant === 'solid',
            },
        ]"
    >
        <slot />
    </component>
</template>
