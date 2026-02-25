<!--
  Components/Workout/RestTimer.vue

  A floating countdown timer component used during workouts to track rest periods between sets.

  Features:
  - Visual progress bar indicating remaining time.
  - Controls to add time (+30s), pause/resume, and skip.
  - Audio and Haptic feedback upon completion.
  - Minimizable/Closeable interface.
  - Draggable or fixed positioning (currently fixed).
  - "Liquid Glass" aesthetics (Apple Human Interface Guidelines).
-->
<script setup>
import { ref, onMounted, onUnmounted, computed, watch } from 'vue'

/**
 * Component Props
 *
 * @property {Number} duration - The initial duration of the timer in seconds (default: 90).
 * @property {Boolean} autoStart - Whether to start the timer immediately upon mounting (default: false).
 */
const props = defineProps({
    duration: {
        type: Number,
        default: 90, // seconds
    },
    autoStart: {
        type: Boolean,
        default: false,
    },
})

/**
 * Component Emits
 *
 * @event finished - Fired when the timer reaches 0.
 * @event close - Fired when the user manually closes the timer.
 */
const emit = defineEmits(['finished', 'close'])

// --- State ---

/** Remaining time in seconds. */
const timeLeft = ref(props.duration)

/** Whether the timer is currently running. */
const isActive = ref(false)

/** Interval ID for the timer loop. */
const timer = ref(null)

/** Timestamp (ms) when the timer should finish. */
const endTime = ref(null)

/** Calculated progress percentage for the visual bar. */
const progress = computed(() => {
    return (timeLeft.value / props.duration) * 100
})

/**
 * Formats seconds into MM:SS format.
 * @param {Number} seconds
 * @return {String} Formatted time string.
 */
const formatTime = (seconds) => {
    const mins = Math.floor(seconds / 60)
    const secs = seconds % 60
    return `${mins}:${secs.toString().padStart(2, '0')}`
}

/** Starts the countdown timer. */
const startTimer = () => {
    if (timer.value) return
    isActive.value = true
    endTime.value = Date.now() + timeLeft.value * 1000

    timer.value = setInterval(() => {
        updateTimer()
    }, 1000)
}

/** Updates the timeLeft based on the endTime. */
const updateTimer = () => {
    const now = Date.now()
    if (now >= endTime.value) {
        finishTimer()
    } else {
        timeLeft.value = Math.ceil((endTime.value - now) / 1000)
    }
}

/** Pauses the countdown timer. */
const pauseTimer = () => {
    clearInterval(timer.value)
    timer.value = null
    isActive.value = false
}

/** Toggles between start and pause states. */
const toggleTimer = () => {
    if (isActive.value) {
        pauseTimer()
    } else {
        startTimer()
    }
}

/**
 * Adds extra time to the current timer.
 * @param {Number} seconds - Amount of seconds to add.
 */
const addTime = (seconds) => {
    timeLeft.value += seconds
    if (isActive.value && endTime.value) {
        endTime.value += seconds * 1000
    }
}

/** Immediately finishes the timer. */
const skipTimer = () => {
    finishTimer()
}

/**
 * Handles timer completion logic.
 * Triggers haptic feedback, plays sound, and emits 'finished' event.
 */
const finishTimer = () => {
    pauseTimer()
    timeLeft.value = 0

    // Haptic feedback if available
    if ('vibrate' in navigator) {
        navigator.vibrate([200, 100, 200])
    }

    // Play a subtle sound if possible
    try {
        const audioCtx = new (window.AudioContext || window.webkitAudioContext)()
        const oscillator = audioCtx.createOscillator()
        const gainNode = audioCtx.createGain()

        oscillator.connect(gainNode)
        gainNode.connect(audioCtx.destination)

        oscillator.type = 'sine'
        oscillator.frequency.setValueAtTime(880, audioCtx.currentTime)
        gainNode.gain.setValueAtTime(0.1, audioCtx.currentTime)
        gainNode.gain.exponentialRampToValueAtTime(0.0001, audioCtx.currentTime + 0.5)

        oscillator.start()
        oscillator.stop(audioCtx.currentTime + 0.5)
    } catch (e) {
        console.warn('Could not play notification sound')
    }

    emit('finished')
}

/** Closes the timer component. */
const close = () => {
    pauseTimer()
    emit('close')
}

/** Handles document visibility changes to sync the timer when app returns from background. */
const handleVisibilityChange = () => {
    if (document.visibilityState === 'visible' && isActive.value) {
        updateTimer()
    }
}

// Lifecycle Hooks
onMounted(() => {
    document.addEventListener('visibilitychange', handleVisibilityChange)
    if (props.autoStart) {
        startTimer()
    }
})

onUnmounted(() => {
    document.removeEventListener('visibilitychange', handleVisibilityChange)
    pauseTimer()
})

// Watchers
watch(
    () => props.duration,
    (newVal) => {
        if (!isActive.value) {
            timeLeft.value = newVal
        }
    },
)
</script>

<template>
    <div class="animate-bounce-in fixed right-4 bottom-36 left-4 z-60 sm:right-4 sm:left-auto sm:w-80">
        <!-- Liquid Glass Card -->
        <div
            class="relative overflow-hidden rounded-3xl border border-white/20 bg-white/10 shadow-2xl backdrop-blur-md transition-all duration-300 dark:bg-black/40"
        >
            <!-- Progress bar -->
            <div class="h-1 w-full bg-slate-200/50 dark:bg-white/10">
                <div
                    class="bg-accent-primary h-full transition-all duration-1000 ease-linear"
                    :style="{ width: `${progress}%` }"
                ></div>
            </div>

            <div class="p-4">
                <div class="flex items-center justify-between">
                    <div>
                        <div class="text-xs font-bold tracking-wider text-slate-900/60 uppercase dark:text-white/60">
                            Repos en cours
                        </div>
                        <div class="text-3xl font-black text-slate-900 tabular-nums dark:text-white">
                            {{ formatTime(timeLeft) }}
                        </div>
                    </div>

                    <div class="flex gap-2">
                        <button
                            @click="addTime(30)"
                            class="flex h-10 w-10 items-center justify-center rounded-full bg-white/40 text-slate-900 transition hover:bg-white/60 active:scale-95 dark:bg-white/10 dark:text-white dark:hover:bg-white/20"
                            title="+30s"
                        >
                            <span class="text-xs font-bold">+30s</span>
                        </button>

                        <button
                            @click="toggleTimer"
                            class="bg-accent-primary flex h-10 w-10 items-center justify-center rounded-full text-black shadow-lg shadow-orange-500/20 transition hover:brightness-110 active:scale-95"
                        >
                            <svg v-if="isActive" class="h-5 w-5" fill="currentColor" viewBox="0 0 24 24">
                                <path d="M6 4h4v16H6V4zm8 0h4v16h4V4z" />
                                <path fill="none" d="M0 0h24v24H0z" />
                            </svg>
                            <svg v-else class="h-5 w-5" fill="currentColor" viewBox="0 0 24 24">
                                <path d="M8 5v14l11-7z" />
                            </svg>
                        </button>
                    </div>
                </div>

                <div class="mt-4 flex gap-2">
                    <!-- Custom "Glass" button for skip to ensure style consistency -->
                    <button
                        @click="skipTimer"
                        class="flex flex-1 items-center justify-center rounded-xl border border-white/20 bg-white/20 px-4 py-2 text-sm font-bold text-slate-900 transition hover:bg-white/30 active:scale-95 dark:bg-white/10 dark:text-white dark:hover:bg-white/20"
                    >
                        Passer
                    </button>
                    <button
                        @click="close"
                        class="rounded-xl bg-slate-200/50 px-3 py-2 text-xs font-bold text-slate-600 transition hover:bg-slate-200 active:scale-95 dark:bg-white/5 dark:text-white/60 dark:hover:bg-white/10"
                    >
                        Fermer
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>

<style scoped>
.animate-bounce-in {
    animation: bounceIn 0.5s cubic-bezier(0.68, -0.55, 0.265, 1.55);
}

@keyframes bounceIn {
    0% {
        transform: scale(0.3);
        opacity: 0;
    }
    50% {
        transform: scale(1.05);
    }
    70% {
        transform: scale(0.9);
    }
    100% {
        transform: scale(1);
        opacity: 1;
    }
}
</style>
