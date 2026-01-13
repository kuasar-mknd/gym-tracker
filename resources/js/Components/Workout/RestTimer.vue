<script setup>
import { ref, onMounted, onUnmounted, computed, watch } from 'vue'
import GlassCard from '@/Components/UI/GlassCard.vue'
import GlassButton from '@/Components/UI/GlassButton.vue'

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

const emit = defineEmits(['finished', 'close'])

const timeLeft = ref(props.duration)
const isActive = ref(false)
const timer = ref(null)

const progress = computed(() => {
    return (timeLeft.value / props.duration) * 100
})

const formatTime = (seconds) => {
    const mins = Math.floor(seconds / 60)
    const secs = seconds % 60
    return `${mins}:${secs.toString().padStart(2, '0')}`
}

const startTimer = () => {
    if (timer.value) return
    isActive.value = true
    timer.value = setInterval(() => {
        if (timeLeft.value > 0) {
            timeLeft.value--
        } else {
            finishTimer()
        }
    }, 1000)
}

const pauseTimer = () => {
    clearInterval(timer.value)
    timer.value = null
    isActive.value = false
}

const toggleTimer = () => {
    if (isActive.value) {
        pauseTimer()
    } else {
        startTimer()
    }
}

const addTime = (seconds) => {
    timeLeft.value += seconds
}

const skipTimer = () => {
    finishTimer()
}

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

const close = () => {
    pauseTimer()
    emit('close')
}

onMounted(() => {
    if (props.autoStart) {
        startTimer()
    }
})

onUnmounted(() => {
    pauseTimer()
})

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
    <div class="animate-bounce-in fixed bottom-24 left-4 right-4 z-50 sm:left-auto sm:right-4 sm:w-80">
        <GlassCard
            class="overflow-hidden border-accent-primary/20 bg-black/80 shadow-2xl backdrop-blur-xl"
            padding="p-0"
        >
            <!-- Progress bar -->
            <div class="h-1 w-full bg-white/5">
                <div
                    class="h-full bg-accent-primary transition-all duration-1000 ease-linear"
                    :style="{ width: `${progress}%` }"
                ></div>
            </div>

            <div class="p-4">
                <div class="flex items-center justify-between">
                    <div>
                        <div class="text-xs font-bold uppercase tracking-wider text-white/40">Repos en cours</div>
                        <div class="text-3xl font-black tabular-nums text-white">
                            {{ formatTime(timeLeft) }}
                        </div>
                    </div>

                    <div class="flex gap-2">
                        <button
                            @click="addTime(30)"
                            class="flex h-10 w-10 items-center justify-center rounded-full bg-white/5 text-white transition hover:bg-white/10 active:scale-95"
                            title="+30s"
                        >
                            <span class="text-xs font-bold">+30s</span>
                        </button>

                        <button
                            @click="toggleTimer"
                            class="flex h-10 w-10 items-center justify-center rounded-full bg-accent-primary text-black transition hover:brightness-110 active:scale-95"
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
                    <GlassButton @click="skipTimer" variant="secondary" size="sm" class="flex-1"> Passer </GlassButton>
                    <button
                        @click="close"
                        class="rounded-xl bg-white/5 px-3 py-2 text-xs font-bold text-white/60 transition hover:bg-white/10"
                    >
                        Fermer
                    </button>
                </div>
            </div>
        </GlassCard>
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
