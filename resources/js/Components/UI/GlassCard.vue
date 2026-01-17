<script setup>
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
                'bg-white/90 backdrop-blur-none': variant === 'solid',
            },
        ]"
    >
        <slot />
    </component>
</template>
