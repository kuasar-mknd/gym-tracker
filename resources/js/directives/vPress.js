/**
 * v-press - Press Effect Directive
 *
 * Adds a scale-down effect on touch/click with haptic feedback.
 * Usage: <button v-press>Click me</button>
 * Options: <button v-press="{ scale: 0.95, haptic: true }">Click me</button>
 */
import { triggerHaptic } from '@/composables/useHaptics'

const defaultOptions = {
    scale: 0.95,
    haptic: 'tap',
}

export const vPress = {
    mounted(el, binding) {
        // Support both v-press, v-press="'success'" and v-press="{ scale: 0.9 }"
        const bindingOptions =
            typeof binding.value === 'object'
                ? binding.value
                : typeof binding.value === 'string'
                  ? { haptic: binding.value }
                  : {}

        const options = { ...defaultOptions, ...bindingOptions }

        let isTouched = false
        let isPressing = false
        let touchTimeout = null

        const handlePressStart = (e) => {
            try {
                if (el.disabled || el.classList.contains('disabled')) return

                // Prevent double triggering on mobile (touch + mouse)
                if (e?.type === 'mousedown' && isTouched) return
                if (e?.type === 'touchstart') {
                    isTouched = true
                    if (touchTimeout) clearTimeout(touchTimeout)
                }

                isPressing = true
                el.style.transform = `scale(${options.scale})`

                if (options.haptic) {
                    triggerHaptic(typeof options.haptic === 'string' ? options.haptic : 'tap')
                }
            } catch (err) {
                // Ignore directive errors to prevent breaking app mount
            }
        }

        const handlePressEnd = () => {
            try {
                if (!isPressing) return
                isPressing = false

                el.style.transform = ''

                // Reset touched flag after a short delay to allow mouse events to be ignored
                if (isTouched) {
                    touchTimeout = setTimeout(() => {
                        isTouched = false
                    }, 500)
                }
            } catch (err) {
                // Ignore
            }
        }

        // Touch events (mobile)
        el.addEventListener('touchstart', handlePressStart, { passive: true })
        el.addEventListener('touchend', handlePressEnd, { passive: true })
        el.addEventListener('touchcancel', handlePressEnd, { passive: true })

        // Mouse events (desktop)
        el.addEventListener('mousedown', handlePressStart)
        el.addEventListener('mouseup', handlePressEnd)
        el.addEventListener('mouseleave', handlePressEnd)

        // Store handlers for cleanup
        el._pressHandlers = {
            handlePressStart,
            handlePressEnd,
        }
    },

    unmounted(el) {
        if (el._pressHandlers) {
            const { handlePressStart, handlePressEnd } = el._pressHandlers

            el.removeEventListener('touchstart', handlePressStart)
            el.removeEventListener('touchend', handlePressEnd)
            el.removeEventListener('touchcancel', handlePressEnd)
            el.removeEventListener('mousedown', handlePressStart)
            el.removeEventListener('mouseup', handlePressEnd)
            el.removeEventListener('mouseleave', handlePressEnd)

            delete el._pressHandlers
        }
    },
}
