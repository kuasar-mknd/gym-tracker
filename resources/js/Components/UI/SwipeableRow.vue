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
import { ref, computed, watch } from 'vue'
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
const startY = ref(0)
const isDragging = ref(false)
const lockDirection = ref(null) // null, 'horizontal', 'vertical'
const containerWidth = ref(0)

// Element refs for the actions layer, used to open the row on keyboard focus
const actionsLayer = ref(null)
const leftAction = ref(null)
const rightAction = ref(null)

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
    startX.value = touch.clientX
    startY.value = touch.clientY
    isDragging.value = true
    lockDirection.value = null
    containerWidth.value = e.currentTarget.offsetWidth
}

function onTouchMove(e) {
    if (!isDragging.value || lockDirection.value === 'vertical') return

    const touch = e.touches[0]
    const deltaX = touch.clientX - startX.value
    const deltaY = touch.clientY - startY.value

    // Determine direction on first significant movement (10px)
    if (!lockDirection.value) {
        if (Math.abs(deltaX) > 10 || Math.abs(deltaY) > 10) {
            if (Math.abs(deltaX) > Math.abs(deltaY)) {
                lockDirection.value = 'horizontal'
            } else {
                lockDirection.value = 'vertical'
                return
            }
        } else {
            return
        }
    }

    if (lockDirection.value === 'horizontal') {
        // Prevent vertical scroll when swiping horizontally
        if (e.cancelable) e.preventDefault()

        let newOffset = deltaX

        // Resistance effect when over-dragging
        if (newOffset > props.actionThreshold) {
            newOffset = props.actionThreshold + (newOffset - props.actionThreshold) * 0.2
        } else if (newOffset < -props.actionThreshold) {
            newOffset = -props.actionThreshold + (newOffset + props.actionThreshold) * 0.2
        }

        offset.value = newOffset
    }
}

function onTouchEnd() {
    isDragging.value = false
    const threshold = props.actionThreshold

    if (lockDirection.value === 'horizontal') {
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
    } else {
        offset.value = 0
    }

    lockDirection.value = null
}

/**
 * The actions sit behind the row at opacity 0 until a swipe drags the content
 * aside, but they never leave the tab order — so a keyboard or screen reader
 * user lands on a completely invisible button, and on Workouts/Index that
 * button deletes the session (WCAG 2.4.7).
 *
 * Hiding them from assistive tech would be the wrong fix: swiping is the only
 * delete affordance those rows expose, so inerting the layer would remove the
 * feature rather than repair it. Opening the row on focus keeps the single
 * path and makes the control visible exactly when it becomes reachable.
 */
function onActionFocus(event) {
    if (leftAction.value?.contains(event.target)) {
        offset.value = props.actionThreshold
    } else if (rightAction.value?.contains(event.target)) {
        offset.value = -props.actionThreshold
    }
}

function onActionBlur(event) {
    // relatedTarget is whatever is gaining focus; keep the row open while Tab
    // moves between two actions on the same row.
    if (!actionsLayer.value?.contains(event.relatedTarget)) {
        offset.value = 0
    }
}

// Disabling the row also removes its actions, so snap shut first — otherwise
// the content would stay translated with nothing revealed behind it.
watch(
    () => props.disabled,
    (isDisabled) => {
        if (isDisabled) {
            offset.value = 0
        }
    },
)

// Reset if clicked outside or programmatically
function close() {
    offset.value = 0
}

defineExpose({ close })
</script>

<template>
    <div class="relative overflow-hidden rounded-3xl">
        <!-- Background Actions Layer. Dropped entirely when the row is disabled:
             the gesture that reveals it is off, so leaving it mounted would only
             leave invisible buttons in the tab order. -->
        <div
            v-if="!disabled"
            ref="actionsLayer"
            class="absolute inset-0 flex w-full"
            :style="actionStyle"
            @focusin="onActionFocus"
            @focusout="onActionBlur"
        >
            <!-- Left Action Slot (revealed when swiping right) -->
            <div ref="leftAction" class="flex w-1/2 items-center justify-start pl-4" v-if="$slots['action-left']">
                <slot name="action-left" />
            </div>

            <!-- Right Action Slot (revealed when swiping left) -->
            <div
                ref="rightAction"
                class="ml-auto flex w-1/2 items-center justify-end pr-4"
                v-if="$slots['action-right']"
            >
                <slot name="action-right" />
            </div>
        </div>

        <!-- Foreground Content Layer -->
        <div
            class="relative z-10 touch-pan-y rounded-3xl border border-white/20 bg-white/10 backdrop-blur-md transition-transform active:scale-[0.99] dark:border-slate-700/30 dark:bg-slate-800/20"
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
