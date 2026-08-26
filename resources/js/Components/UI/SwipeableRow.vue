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
import { ref, computed, watch, useSlots } from 'vue'
import { triggerHaptic } from '@/composables/useHaptics'

const slots = useSlots()

const props = defineProps({
    disabled: { type: Boolean, default: false },
    /** Width of the action area in pixels to snap to */
    actionThreshold: { type: Number, default: 80 },
})

defineEmits(['click'])

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

const hasLeftAction = computed(() => Boolean(slots['action-left']))
const hasRightAction = computed(() => Boolean(slots['action-right']))

/**
 * An action is exactly as wide as the strip the content has uncovered.
 *
 * It used to be half the row, which the content then sat on top of — and the
 * content is glass, `bg-surface-card/10` over a row at `bg-surface-card/80`. A red delete
 * panel behind that does not stay behind it: it came through as a pink wash
 * across the middle of the row, over the values. Fading the layer in with the
 * drag was meant to hide that, but the fade completes long before the row is
 * open, so by the time it mattered the opacity was already 1.
 *
 * Sized to the offset, the two layers never overlap and there is nothing to
 * bleed. It stays at least the snap width so the action is fully drawn at rest.
 */
const actionWidth = computed(() => `${Math.max(props.actionThreshold, Math.abs(offset.value))}px`)

/**
 * Only the action the swipe is actually uncovering is drawn.
 *
 * Sizing them to the offset stops an action bleeding through the content it
 * sits beside, but not one on the side the content moved *towards*: a right
 * action stays anchored to the right edge, and a swipe to the right slides the
 * glass over it rather than away from it. On a row whose only action is the
 * delete, dragging the wrong way therefore washed the values red without
 * uncovering anything.
 */
const sideStyle = (side) => ({
    opacity: (side === 'left' ? offset.value > 0 : offset.value < 0) ? Math.min(1, Math.abs(offset.value) / 20) : 0,
    transition: isDragging.value ? 'none' : 'opacity 0.3s cubic-bezier(0.2, 0.8, 0.2, 1)',
})

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

        // A drag towards a side with no action uncovers nothing, so the row does
        // not follow it. It used to: a set row has only a delete, and pulling it
        // right slid the whole row off its card to reveal bare background.
        let newOffset = deltaX

        if ((newOffset > 0 && !hasLeftAction.value) || (newOffset < 0 && !hasRightAction.value)) {
            newOffset = 0
        }

        // Resistance effect when over-dragging
        if (newOffset > props.actionThreshold) {
            newOffset = props.actionThreshold + (newOffset - props.actionThreshold) * 0.2
        } else if (newOffset < -props.actionThreshold) {
            newOffset = -props.actionThreshold + (newOffset + props.actionThreshold) * 0.2
        }

        offset.value = newOffset
    }
}

/**
 * A gesture the system takes away has to leave the row where it found it.
 *
 * `touchcancel` fires when the browser or the OS claims the touch — a call
 * arriving, the notification shade pulled down, the back gesture from the edge.
 * Nothing listened for it, so the row stayed frozen mid-swipe: translated
 * sideways, `isDragging` still true and therefore no transition to carry it
 * back, and the actions half-revealed under content the user never meant to
 * move.
 */
function onTouchCancel() {
    isDragging.value = false
    offset.value = 0
    lockDirection.value = null
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
            class="absolute inset-0"
            :style="actionStyle"
            @focusin="onActionFocus"
            @focusout="onActionBlur"
        >
            <!-- Left Action Slot (revealed when swiping right) -->
            <div
                ref="leftAction"
                class="absolute inset-y-0 left-0 flex items-stretch overflow-hidden"
                :style="{ width: actionWidth, ...sideStyle('left') }"
                v-if="hasLeftAction"
            >
                <slot name="action-left" />
            </div>

            <!-- Right Action Slot (revealed when swiping left) -->
            <div
                ref="rightAction"
                class="absolute inset-y-0 right-0 flex items-stretch overflow-hidden"
                :style="{ width: actionWidth, ...sideStyle('right') }"
                v-if="hasRightAction"
            >
                <slot name="action-right" />
            </div>
        </div>

        <!-- Foreground Content Layer -->
        <div
            class="border-surface-card/20 bg-surface-card/10 relative z-10 touch-pan-y rounded-3xl border transition-transform active:scale-[0.99]"
            :style="style"
            @touchstart="onTouchStart"
            @touchmove="onTouchMove"
            @touchend="onTouchEnd"
            @touchcancel="onTouchCancel"
            @click="$emit('click')"
        >
            <slot />
        </div>
    </div>
</template>
