/**
 * useHaptics - Mobile Haptic Feedback Composable
 *
 * Provides standardized vibration patterns for mobile interactions.
 * Falls back gracefully on devices without vibration support.
 */

/**
 * @typedef {'tap' | 'toggle' | 'success' | 'error' | 'warning' | 'timer' | 'selection'} HapticType
 */

const patterns = {
    tap: [5],
    toggle: [15],
    selection: [3],
    success: [50, 30, 50],
    error: [100, 50, 100, 50, 100],
    warning: [30, 20, 30],
    timer: [200, 100, 200],
}

/**
 * Check if the Vibration API is supported
 * @returns {boolean}
 */
export function isHapticsSupported() {
    return 'vibrate' in navigator
}

/**
 * Trigger a haptic feedback
 * @param {HapticType} type - The type of haptic pattern to play
 * @returns {boolean} - Whether the vibration was triggered
 */
export function triggerHaptic(type = 'tap') {
    if (!isHapticsSupported()) {
        return false
    }

    try {
        const pattern = patterns[type] || patterns.tap
        return navigator.vibrate(pattern)
    } catch (e) {
        // Fallback for cases where vibrate might be blocked by browser policy
        // or headless environment even if it exists in navigator object
        return false
    }
}

/**
 * Stop any ongoing vibration
 * @returns {boolean}
 */
export function stopVibration() {
    if (!isHapticsSupported()) {
        return false
    }
    return navigator.vibrate(0)
}

/**
 * Vue composable for haptic feedback
 */
export function useHaptics() {
    return {
        isSupported: isHapticsSupported(),
        triggerHaptic,
        stopVibration,
        patterns,
    }
}
export default {
    triggerHaptic,
    stopVibration,
    isHapticsSupported,
    patterns,
}
