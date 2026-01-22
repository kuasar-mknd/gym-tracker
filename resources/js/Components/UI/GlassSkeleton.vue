<script setup>
defineProps({
    width: {
        type: String,
        default: '100%',
    },
    height: {
        type: String,
        default: '1rem',
    },
    variant: {
        type: String,
        default: 'text', // text, circle, card
        validator: (value) => ['text', 'circle', 'card'].includes(value),
    },
    borderRadius: {
        type: String,
        default: '0.5rem',
    },
})
</script>

<template>
    <div
        class="glass-skeleton relative overflow-hidden"
        :class="{
            'rounded-full': variant === 'circle',
            'rounded-2xl': variant === 'card' || variant === 'text',
        }"
        :style="{
            width: width,
            height: height,
            borderRadius: variant === 'circle' ? '50%' : borderRadius,
        }"
    >
        <!-- Optional shim layer if not handled by CSS animation purely -->
    </div>
</template>
