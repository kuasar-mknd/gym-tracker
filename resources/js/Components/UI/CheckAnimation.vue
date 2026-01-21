<script setup>
/**
 * CheckAnimation.vue
 *
 * An animated SVG checkmark for success feedback.
 * Animates from hidden to visible with a draw effect.
 */
import { ref, onMounted } from 'vue'

defineProps({
    size: {
        type: Number,
        default: 24,
    },
    color: {
        type: String,
        default: 'currentColor',
    },
    delay: {
        type: Number,
        default: 0,
    },
})

const isAnimated = ref(false)

onMounted(() => {
    setTimeout(() => {
        isAnimated.value = true
    }, 50)
})
</script>

<template>
    <svg
        :width="size"
        :height="size"
        viewBox="0 0 24 24"
        fill="none"
        :stroke="color"
        stroke-width="3"
        stroke-linecap="round"
        stroke-linejoin="round"
        class="check-animation"
        :class="{ animated: isAnimated }"
        :style="{ animationDelay: `${delay}ms` }"
    >
        <path d="M5 13l4 4L19 7" class="check-path" />
    </svg>
</template>

<style scoped>
.check-animation {
    overflow: visible;
}

.check-path {
    stroke-dasharray: 24;
    stroke-dashoffset: 24;
    transition: stroke-dashoffset 0.3s cubic-bezier(0.65, 0, 0.35, 1);
}

.check-animation.animated .check-path {
    stroke-dashoffset: 0;
}
</style>
