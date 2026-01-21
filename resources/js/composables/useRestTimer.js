import { ref, computed, onUnmounted } from 'vue'
import { vibrate } from './useHaptics'

/**
 * useRestTimer - Smart Rest Timer Composable
 *
 * Manages a countdown timer with haptic feedback, notifications,
 * and screen wake lock for optimal gym experience.
 */

/** @type {import('vue').Ref<number | null>} */
const endTime = ref(null)

/** @type {import('vue').Ref<number>} */
const defaultDuration = ref(90) // 90 seconds default

/** @type {import('vue').Ref<number>} */
const remainingSeconds = ref(0)

/** @type {import('vue').Ref<boolean>} */
const isRunning = ref(false)

/** @type {number | null} */
let intervalId = null

/** @type {WakeLockSentinel | null} */
let wakeLock = null

/**
 * Request a wake lock to keep the screen on
 */
async function requestWakeLock() {
    if ('wakeLock' in navigator) {
        try {
            wakeLock = await navigator.wakeLock.request('screen')
            console.log('[RestTimer] Wake lock acquired')
        } catch (err) {
            console.warn('[RestTimer] Wake lock failed:', err)
        }
    }
}

/**
 * Release the wake lock
 */
async function releaseWakeLock() {
    if (wakeLock) {
        await wakeLock.release()
        wakeLock = null
        console.log('[RestTimer] Wake lock released')
    }
}

/**
 * Show a push notification when timer ends (if app is in background)
 */
function showTimerEndNotification() {
    if ('Notification' in window && Notification.permission === 'granted') {
        if (document.hidden) {
            new Notification('⏱️ Temps de repos terminé !', {
                body: "C'est reparti ! 💪",
                icon: '/logo.svg',
                tag: 'rest-timer',
                requireInteraction: true,
            })
        }
    }
}

/**
 * Update the remaining time
 */
function tick() {
    if (!endTime.value) {
        return
    }

    const now = Date.now()
    const remaining = Math.max(0, Math.ceil((endTime.value - now) / 1000))
    remainingSeconds.value = remaining

    if (remaining <= 0) {
        onTimerEnd()
    }
}

/**
 * Called when timer reaches 0
 */
function onTimerEnd() {
    stop()
    vibrate('timer')
    showTimerEndNotification()
}

/**
 * Start the timer
 * @param {number} [seconds] - Duration in seconds (uses default if not provided)
 */
function start(seconds) {
    const duration = seconds ?? defaultDuration.value
    endTime.value = Date.now() + duration * 1000
    remainingSeconds.value = duration
    isRunning.value = true

    // Clear any existing interval
    if (intervalId) {
        clearInterval(intervalId)
    }

    // Start ticking
    intervalId = setInterval(tick, 250)

    // Keep screen on
    requestWakeLock()

    // Light haptic to confirm start
    vibrate('tap')
}

/**
 * Stop the timer
 */
function stop() {
    if (intervalId) {
        clearInterval(intervalId)
        intervalId = null
    }
    endTime.value = null
    remainingSeconds.value = 0
    isRunning.value = false
    releaseWakeLock()
}

/**
 * Add time to the running timer
 * @param {number} seconds - Seconds to add (can be negative)
 */
function addTime(seconds) {
    if (endTime.value) {
        endTime.value += seconds * 1000
        remainingSeconds.value += seconds
        vibrate('tap')
    }
}

/**
 * Set the default duration
 * @param {number} seconds
 */
function setDefaultDuration(seconds) {
    defaultDuration.value = seconds
}

/**
 * Format remaining seconds as MM:SS
 */
const formattedTime = computed(() => {
    const mins = Math.floor(remainingSeconds.value / 60)
    const secs = remainingSeconds.value % 60
    return `${mins}:${secs.toString().padStart(2, '0')}`
})

/**
 * Progress percentage (0-100)
 */
const progress = computed(() => {
    if (!endTime.value || !defaultDuration.value) return 0
    const elapsed = defaultDuration.value - remainingSeconds.value
    return Math.min(100, (elapsed / defaultDuration.value) * 100)
})

/**
 * Vue composable for rest timer
 */
export function useRestTimer() {
    // Cleanup on unmount
    onUnmounted(() => {
        if (intervalId) {
            clearInterval(intervalId)
        }
        releaseWakeLock()
    })

    return {
        // State
        isRunning,
        remainingSeconds,
        defaultDuration,
        formattedTime,
        progress,

        // Actions
        start,
        stop,
        addTime,
        setDefaultDuration,
    }
}
