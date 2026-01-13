<script setup>
defineProps({
    variant: {
        type: String,
        default: 'default', // default | strong | glow
    },
    as: {
        type: String,
        default: 'div',
    },
    padding: {
        type: String,
        default: 'p-4 sm:p-6',
    },
})
</script>

<template>
    <component
        :is="as"
        :class="[
            'glass-card animate-fade-in',
            padding,
            {
                'glass-card-strong': variant === 'strong',
                'shadow-glass-glow': variant === 'glow',
            },
        ]"
    >
        <slot />
    </component>
</template>
