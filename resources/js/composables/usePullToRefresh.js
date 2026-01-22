import { ref, onMounted, onUnmounted } from 'vue'
import { router } from '@inertiajs/vue3'
import { triggerHaptic } from '@/composables/useHaptics'

export function usePullToRefresh(options = {}) {
    const {
        threshold = 150,
        onRefresh = () => router.reload({ preserveScroll: true }),
        containerRef = null, // Optional: scroll container if not window
    } = options

    const isRefreshing = ref(false)
    const pullDistance = ref(0)
    const isPulling = ref(false)

    let startY = 0
    let currentY = 0

    const getScrollTop = () => {
        if (containerRef?.value) return containerRef.value.scrollTop
        return window.scrollY
    }

    const onTouchStart = (e) => {
        if (getScrollTop() <= 0) {
            startY = e.touches[0].clientY
            isPulling.value = true
        } else {
            isPulling.value = false
        }
    }

    const onTouchMove = (e) => {
        if (!isPulling.value) return

        // If user scrolled down and then back up, and continues pulling
        if (getScrollTop() > 0) {
            isPulling.value = false
            pullDistance.value = 0
            return
        }

        currentY = e.touches[0].clientY
        const delta = currentY - startY

        // Only allow pulling down
        if (delta > 0) {
            // Add resistance
            pullDistance.value = Math.pow(delta, 0.8)

            // Prevent default only if we are significantly pulling
            if (delta > 20 && e.cancelable) {
                // e.preventDefault() // Careful with this, might block normal scroll sometimes
            }
        } else {
            pullDistance.value = 0
        }
    }

    const onTouchEnd = async () => {
        if (!isPulling.value) return

        if (pullDistance.value > threshold) {
            isRefreshing.value = true
            pullDistance.value = threshold // Snap to threshold
            triggerHaptic('time') // Haptic feedback on trigger

            try {
                await onRefresh()
            } finally {
                setTimeout(() => {
                    isRefreshing.value = false
                    pullDistance.value = 0
                }, 500)
            }
        } else {
            pullDistance.value = 0
        }

        isPulling.value = false
    }

    onMounted(() => {
        const target = containerRef?.value || window
        target.addEventListener('touchstart', onTouchStart, { passive: true })
        target.addEventListener('touchmove', onTouchMove, { passive: false }) // passive: false needed for preventDefault if used
        target.addEventListener('touchend', onTouchEnd)
    })

    onUnmounted(() => {
        const target = containerRef?.value || window
        target.removeEventListener('touchstart', onTouchStart)
        target.removeEventListener('touchmove', onTouchMove)
        target.removeEventListener('touchend', onTouchEnd)
    })

    return {
        isRefreshing,
        pullDistance,
        isPulling,
    }
}
