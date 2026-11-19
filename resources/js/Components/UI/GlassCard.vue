<script setup>
/**
 * GlassCard.vue
 *
 * A reusable container component that applies the consistent "Liquid Glass"
 * aesthetic to its content. It serves as the primary structural element for
 * grouping related information or actions throughout the application.
 *
 * La carte prend l'un des trois matériaux du verre (`verre-fin`,
 * `verre-regulier`, `verre-epais`, app.css) ou une surface pleine, le rayon du
 * rôle `carte`, un rembourrage réglable et un survol facultatif.
 */

/**
 * Component Props
 *
 * @property {String} variant - The visual style of the card.
 *   - 'default': verre régulier, la carte posée dans le flux.
 *   - 'fin': verre fin, une surface secondaire que le fond laisse voir.
 *   - 'epais': verre épais, une carte qui flotte par-dessus du contenu.
 *   - 'solid': An opaque white background.
 * @property {String} as - The HTML element or Vue component to render as the root node (default: 'div').
 * @property {String} padding - Tailwind utility classes for the card's inner padding (default: 'p-5 sm:p-6').
 * @property {String} rounded - Tailwind utility classes for the card's border radius (default: 'rounded-carte').
 * @property {Boolean} hover - Enables hover effects: translates the card up slightly and adds a stronger drop shadow (default: false).
 */
defineProps({
    variant: {
        type: String,
        default: 'default', // default | fin | epais | solid
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
        default: 'rounded-carte',
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
            'animate-fade-in transition duration-300',
            padding,
            rounded,
            {
                'cursor-pointer hover:-translate-y-1 hover:shadow-lg': hover,
                'verre-regulier': variant === 'default',
                'verre-fin': variant === 'fin',
                'verre-epais': variant === 'epais',
                'verre-regulier bg-surface-card/90 backdrop-blur-none': variant === 'solid',
            },
        ]"
    >
        <slot />
    </component>
</template>
