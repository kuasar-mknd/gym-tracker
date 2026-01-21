<script setup>
/**
 * SwipeableRow.vue
 *
 * A touch-enabled swipeable row component that reveals actions on swipe.
 * - Swipe left: Delete action (red)
 * - Swipe right: Duplicate action (blue)
 * - Includes haptic feedback for native mobile feel
 */
import { ref, computed } from 'vue'
import { vibrate } from '@/composables/useHaptics'

const props = defineProps({
    /** Whether the component is disabled */
    disabled: {
        type: Boolean,
        default: false,
    },
    /** Threshold percentage to reveal action (0-1) */
    revealThreshold: {
        type: Number,
        default: 0.25,
    },
    /** Threshold percentage to trigger action (0-1) */
    triggerThreshold: {
        type: Number,
        default: 0.5,
    },
})

const emit = defineEmits(['delete', 'duplicate'])

// State
const offset = ref(0)
const startX = ref(0)
const isDragging = ref(false)
const containerWidth = ref(300)
const hasTriggeredHaptic = ref(false)

// Computed
const translateX = computed(() => `translateX(${offset.value}px)`)

const leftActionVisible = computed(() => offset.value > containerWidth.value * props.revealThreshold)
const rightActionVisible = computed(() => offset.value < -containerWidth.value * props.revealThreshold)

const leftActionTriggered = computed(() => offset.value > containerWidth.value * props.triggerThreshold)
const rightActionTriggered = computed(
    () => Math.abs(offset.value) > containerWidth.value * props.triggerThreshold && offset.value < 0,
)

// Touch handlers
function onTouchStart(e) {
    if (props.disabled) return

    const touch = e.touches[0]
    startX.value = touch.clientX - offset.value
    isDragging.value = true
    hasTriggeredHaptic.value = false

    // Get container width
    containerWidth.value = e.currentTarget.offsetWidth
}

function onTouchMove(e) {
    if (!isDragging.value || props.disabled) return

    const touch = e.touches[0]
    const newOffset = touch.clientX - startX.value

    // Limit the drag distance
    const maxOffset = containerWidth.value * 0.6
    offset.value = Math.max(-maxOffset, Math.min(maxOffset, newOffset))

    // Haptic feedback at threshold
    if (!hasTriggeredHaptic.value && (leftActionTriggered.value || rightActionTriggered.value)) {
        vibrate('toggle')
        hasTriggeredHaptic.value = true
    }

    // Reset haptic flag if back below threshold
    if (hasTriggeredHaptic.value && !leftActionTriggered.value && !rightActionTriggered.value) {
        hasTriggeredHaptic.value = false
    }
}

function onTouchEnd() {
    if (!isDragging.value || props.disabled) return

    isDragging.value = false

    // Check if action should be triggered
    if (leftActionTriggered.value) {
        vibrate('success')
        emit('duplicate')
    } else if (rightActionTriggered.value) {
        vibrate('warning')
        emit('delete')
    }

    // Reset position with animation
    offset.value = 0
}
</script>

<template>
    <div class="relative overflow-hidden rounded-xl">
        <!-- Background actions -->
        <div class="absolute inset-0 flex">
            <!-- Left action (Duplicate - Blue) -->
            <div
                class="flex flex-1 items-center justify-start bg-blue-500 pl-4 text-white transition-opacity"
                :class="leftActionVisible ? 'opacity-100' : 'opacity-0'"
            >
                <span class="material-symbols-outlined text-2xl">content_copy</span>
                <span v-if="leftActionTriggered" class="ml-2 text-sm font-bold">Dupliquer</span>
            </div>

            <!-- Right action (Delete - Red) -->
            <div
                class="flex flex-1 items-center justify-end bg-red-500 pr-4 text-white transition-opacity"
                :class="rightActionVisible ? 'opacity-100' : 'opacity-0'"
            >
                <span v-if="rightActionTriggered" class="mr-2 text-sm font-bold">Supprimer</span>
                <span class="material-symbols-outlined text-2xl">delete</span>
            </div>
        </div>

        <!-- Swipeable content -->
        <div
            class="relative z-10 touch-pan-y"
            :class="{ 'transition-transform duration-200 ease-out': !isDragging }"
            :style="{ transform: translateX }"
            @touchstart="onTouchStart"
            @touchmove="onTouchMove"
            @touchend="onTouchEnd"
            @touchcancel="onTouchEnd"
        >
            <slot />
        </div>
    </div>
</template>
