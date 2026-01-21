/**
 * v-press - Press Effect Directive
 *
 * Adds a scale-down effect on touch/click with haptic feedback.
 * Usage: <button v-press>Click me</button>
 * Options: <button v-press="{ scale: 0.95, haptic: true }">Click me</button>
 */
import { vibrate } from '@/composables/useHaptics'

const defaultOptions = {
    scale: 0.95,
    haptic: true,
    duration: 100,
}

export const vPress = {
    mounted(el, binding) {
        const options = { ...defaultOptions, ...binding.value }

        // Store original transition
        const originalTransition = el.style.transition

        // Add transition for smooth scale effect
        el.style.transition = `${originalTransition ? originalTransition + ', ' : ''}transform ${options.duration}ms ease-out`
        el.style.willChange = 'transform'

        const handlePressStart = () => {
            el.style.transform = `scale(${options.scale})`
            if (options.haptic) {
                vibrate('tap')
            }
        }

        const handlePressEnd = () => {
            el.style.transform = 'scale(1)'
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
            originalTransition,
        }
    },

    unmounted(el) {
        if (el._pressHandlers) {
            const { handlePressStart, handlePressEnd, originalTransition } = el._pressHandlers

            el.removeEventListener('touchstart', handlePressStart)
            el.removeEventListener('touchend', handlePressEnd)
            el.removeEventListener('touchcancel', handlePressEnd)
            el.removeEventListener('mousedown', handlePressStart)
            el.removeEventListener('mouseup', handlePressEnd)
            el.removeEventListener('mouseleave', handlePressEnd)

            el.style.transition = originalTransition
            el.style.willChange = ''

            delete el._pressHandlers
        }
    },
}
