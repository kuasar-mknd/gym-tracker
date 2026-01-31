<script setup>
/**
 * SwipeableRow.vue
 *
 * A generic gesture-controlled row component.
 * - Swipe right (positive offset): Reveals `action-left` slot
 * - Swipe left (negative offset): Reveals `action-right` slot
 * - Supports "snap" to keep actions visible.
 *
 * Refactored for "Liquid Glass" aesthetic:
 * - Transparent backgrounds to allow GlassCard slots to shine.
 * - Dynamic opacity for actions to prevent bleed-through.
 */
import { ref, computed } from 'vue'
import { triggerHaptic } from '@/composables/useHaptics'

const props = defineProps({
    disabled: { type: Boolean, default: false },
    /** Width of the action area in pixels to snap to */
    actionThreshold: { type: Number, default: 80 },
})

const emit = defineEmits(['click'])

// State
const offset = ref(0)
const startX = ref(0)
const isDragging = ref(false)
const containerWidth = ref(0)

// Computed
const style = computed(() => ({
    transform: `translateX(${offset.value}px)`,
    transition: isDragging.value ? 'none' : 'transform 0.3s cubic-bezier(0.2, 0.8, 0.2, 1)',
}))

// Opacity for the actions layer to prevent them from showing through semi-transparent glass
const actionStyle = computed(() => ({
    opacity: Math.min(1, Math.abs(offset.value) / 20),
    transition: isDragging.value ? 'none' : 'opacity 0.3s cubic-bezier(0.2, 0.8, 0.2, 1)',
}))

// Methods
function onTouchStart(e) {
    if (props.disabled) return
    const touch = e.touches[0]
    startX.value = touch.clientX - offset.value
    isDragging.value = true
    containerWidth.value = e.currentTarget.offsetWidth
}

function onTouchMove(e) {
    if (!isDragging.value) return
    const touch = e.touches[0]
    const currentX = touch.clientX
    let newOffset = currentX - startX.value

    // Resistance effect when over-dragging
    if (newOffset > props.actionThreshold) {
        newOffset = props.actionThreshold + (newOffset - props.actionThreshold) * 0.2
    } else if (newOffset < -props.actionThreshold) {
        newOffset = -props.actionThreshold + (newOffset + props.actionThreshold) * 0.2
    }

    offset.value = newOffset
}

function onTouchEnd() {
    isDragging.value = false
    const threshold = props.actionThreshold

    // Snap logic
    if (offset.value > threshold * 0.5) {
        // Snap open left
        offset.value = threshold
        triggerHaptic('selection')
    } else if (offset.value < -threshold * 0.5) {
        // Snap open right
        offset.value = -threshold
        triggerHaptic('selection')
    } else {
        // Snap close
        offset.value = 0
    }
}

// Reset if clicked outside or programmatically
function close() {
    offset.value = 0
}

defineExpose({ close })
</script>

<template>
    <div class="relative overflow-hidden rounded-3xl">
        <!-- Background Actions Layer -->
        <div class="absolute inset-0 flex w-full" :style="actionStyle">
            <!-- Left Action Slot (revealed when swiping right) -->
            <div class="flex w-1/2 items-center justify-start pl-4" v-if="$slots['action-left']">
                <slot name="action-left" />
            </div>

            <!-- Right Action Slot (revealed when swiping left) -->
            <div class="ml-auto flex w-1/2 items-center justify-end pr-4" v-if="$slots['action-right']">
                <slot name="action-right" />
            </div>
        </div>

        <!-- Foreground Content Layer -->
        <div
            class="relative z-10 touch-pan-y rounded-3xl border border-white/20 bg-white/10 backdrop-blur-md transition-transform active:scale-[0.99]"
            :style="style"
            @touchstart="onTouchStart"
            @touchmove="onTouchMove"
            @touchend="onTouchEnd"
            @click="$emit('click')"
        >
            <slot />
        </div>
    </div>
</template>
