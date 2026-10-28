<!--
  Components/Workout/RestTimer.vue

  A floating countdown timer component used during workouts to track rest periods between sets.

  Features:
  - Visual progress bar indicating remaining time.
  - Controls to pause/resume and skip.
  - Audio and Haptic feedback upon completion.
  - Minimizable/Closeable interface.
  - Draggable or fixed positioning (currently fixed).
  - "Liquid Glass" aesthetics (Apple Human Interface Guidelines).
-->
<script setup>
import { ref, onMounted, onUnmounted, computed, watch } from 'vue'
import { triggerHaptic } from '@/composables/useHaptics'
import { useSortieAudio } from '@/composables/useSortieAudio'
import GlassToggle from '@/Components/UI/GlassToggle.vue'

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
        default: true,
    },

    /**
     * Le reglage de l'utilisateur, rendu ici pour qu'il se change sans quitter
     * la seance. Le composant ne l'ecrit pas lui-meme : il l'annonce, et la
     * page decide quoi en faire — c'est ce qui le garde ignorant d'Inertia, et
     * donc montable seul.
     */
    autoRestTimer: {
        type: Boolean,
        default: true,
    },

    /**
     * Le réglage ne peut pas partir, et l'identifiant du texte qui dit
     * pourquoi : sur une copie hors ligne, rien ne part vers le serveur
     * (#2048). La page le décide, comme le réglage lui-même.
     */
    reglageDesactive: {
        type: Boolean,
        default: false,
    },
    raisonDuReglage: {
        type: String,
        default: undefined,
    },

    /**
     * La sortie audio de la page (`useSortieAudio`, #2112), ouverte dans le
     * geste qui a lancé le repos : la fin y sonne. La page la tient parce que
     * le minuteur se démonte à chaque fin, bip compris. Monté seul, sans
     * elle, le minuteur ouvre la sienne dans ses propres gestes et la rend en
     * partant.
     */
    sortieAudio: {
        type: Object,
        default: null,
    },
})

/**
 * Component Emits
 *
 * @event finished - Fired when the timer reaches 0.
 * @event close - Fired when the user manually closes the timer.
 */
const emit = defineEmits(['finished', 'close', 'update:autoRestTimer'])

// --- State ---

/** Remaining time in seconds. */
const timeLeft = ref(props.duration)

/** Whether the timer is currently running. */
const isActive = ref(false)

/** Interval ID for the timer loop. */
const timer = ref(null)

/** Timestamp (ms) when the timer should finish. */
const endTime = ref(null)

/** Où sonne la fin : la sortie de la page, ou la sienne quand il est monté seul. */
const sortieAudio = props.sortieAudio ?? useSortieAudio()

/**
 * Calculated progress percentage for the visual bar.
 *
 * Clamped, because the +30s button pushes timeLeft past the duration the bar is
 * scaled against: the fill overflowed its track, and the progressbar reported an
 * aria-valuenow above its own aria-valuemax — a value a screen reader is
 * entitled to treat as nonsense.
 */
const progress = computed(() => {
    if (!props.duration) {
        return 0
    }

    return Math.min(100, Math.max(0, (timeLeft.value / props.duration) * 100))
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
    if (timer.value) {
        clearInterval(timer.value)
        timer.value = null
    }
    isActive.value = false
}

/**
 * Toggles between start and pause states.
 *
 * La reprise est un geste : la sortie y repart, qu'iOS ait pu interrompre
 * pendant la pause (#2112).
 */
const toggleTimer = () => {
    triggerHaptic('toggle')
    if (isActive.value) {
        pauseTimer()
    } else {
        sortieAudio.preparer()
        startTimer()
    }
}

/** Immediately finishes the timer, dans un geste qui reprend la sortie avant d'y sonner. */
const skipTimer = () => {
    triggerHaptic('tap')
    sortieAudio.preparer()
    finishTimer()
}

/**
 * Le bip de fin : un la aigu (880 Hz) d'une demi-seconde, qui s'éteint.
 *
 * @param {AudioContext} contexte
 */
const jouerLeBipDeFin = (contexte) => {
    const oscillator = contexte.createOscillator()
    const gainNode = contexte.createGain()

    oscillator.connect(gainNode)
    gainNode.connect(contexte.destination)

    oscillator.type = 'sine'
    oscillator.frequency.setValueAtTime(880, contexte.currentTime)
    gainNode.gain.setValueAtTime(0.1, contexte.currentTime)
    gainNode.gain.exponentialRampToValueAtTime(0.0001, contexte.currentTime + 0.5)

    oscillator.start()
    oscillator.stop(contexte.currentTime + 0.5)
}

/**
 * Handles timer completion logic.
 * Triggers haptic feedback, plays sound, and emits 'finished' event.
 *
 * La fin naturelle arrive dans le rappel de la minuterie, hors de tout geste :
 * le bip se joue sur la sortie que le geste du départ a ouverte, jamais sur une
 * sortie créée ici, que WebKit laisserait muette (#2112). Le retour haptique
 * passe par `navigator.vibrate`, que Safari iOS n'a pas : sur iPhone, c'est le
 * son seul qui prévient.
 */
const finishTimer = () => {
    pauseTimer()
    timeLeft.value = 0

    // Haptic feedback
    triggerHaptic('timer')

    sortieAudio.jouer(jouerLeBipDeFin)

    emit('finished')
}

/** Closes the timer component. */
const close = () => {
    triggerHaptic('tap')
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
    <div
        class="animate-bounce-in z-flottant right-sure-4 bottom-sure-36 left-sure-4 sm:right-sure-4 fixed sm:left-auto sm:w-80"
    >
        <!-- Liquid Glass Card -->
        <div
            class="border-surface-card/20 bg-surface-card/10 overflow-hidden rounded-3xl border shadow-2xl backdrop-blur-md transition duration-300"
        >
            <!-- Progress bar -->
            <div
                class="bg-surface-sunken/50 h-1 w-full"
                role="progressbar"
                :aria-valuenow="progress"
                aria-valuemin="0"
                aria-valuemax="100"
                :aria-valuetext="formatTime(timeLeft)"
            >
                <div
                    class="bg-accent-primary h-full transition duration-1000 ease-linear"
                    :style="{ width: `${progress}%` }"
                ></div>
            </div>

            <div class="p-4">
                <div class="flex items-center justify-between gap-3">
                    <div class="min-w-0">
                        <div class="text-text-main/60 text-xs font-bold tracking-wider uppercase">Repos en cours</div>
                        <div class="text-text-main text-3xl font-black tabular-nums" role="timer" aria-atomic="true">
                            {{ formatTime(timeLeft) }}
                        </div>
                    </div>

                    <div class="flex shrink-0 items-center gap-2">
                        <button
                            @click="toggleTimer"
                            class="bg-accent-primary focus-visible:ring-accent-primary text-text-on-accent shadow-accent-primary/20 flex h-11 w-11 items-center justify-center rounded-full shadow-lg transition hover:brightness-110 focus-visible:ring-2 focus-visible:outline-none active:scale-95"
                            :title="isActive ? 'Pause' : 'Démarrer le minuteur'"
                            :aria-label="isActive ? 'Pause' : 'Démarrer le minuteur'"
                        >
                            <svg v-if="isActive" class="h-5 w-5" fill="currentColor" viewBox="0 0 24 24">
                                <path d="M6 4h4v16H6V4zm8 0h4v16h4V4z" />
                                <path fill="none" d="M0 0h24v24H0z" />
                            </svg>
                            <svg v-else class="h-5 w-5" fill="currentColor" viewBox="0 0 24 24">
                                <path d="M8 5v14l11-7z" />
                            </svg>
                        </button>

                        <button
                            @click="close"
                            dusk="close-timer-x"
                            class="focus-visible:ring-accent-primary bg-surface-sunken/50 text-text-muted hover:bg-surface-sunken flex h-11 w-11 items-center justify-center rounded-full transition focus-visible:ring-2 focus-visible:outline-none active:scale-95"
                            aria-label="Fermer le minuteur"
                            title="Fermer le minuteur"
                        >
                            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                    stroke-width="2"
                                    d="M6 18L18 6M6 6l12 12"
                                />
                            </svg>
                        </button>
                    </div>
                </div>

                <div class="mt-4 flex gap-2">
                    <!-- Custom "Glass" button for skip to ensure style consistency -->
                    <button
                        @click="skipTimer"
                        dusk="skip-rest-timer"
                        class="focus-visible:ring-accent-primary border-surface-card/20 bg-surface-card/20 text-text-main hover:bg-surface-card/30 min-h-touch flex flex-1 items-center justify-center rounded-xl border px-4 py-2 text-sm font-bold transition focus-visible:ring-2 focus-visible:outline-none active:scale-95"
                        title="Passer le repos"
                        aria-label="Passer le repos"
                    >
                        Passer
                    </button>
                </div>

                <div class="border-surface-card/20 mt-3 border-t pt-3">
                    <GlassToggle
                        :model-value="autoRestTimer"
                        label="Démarrage automatique"
                        size="sm"
                        dusk="auto-rest-timer"
                        :disabled="reglageDesactive"
                        :aria-describedby="reglageDesactive ? raisonDuReglage : undefined"
                        @update:model-value="emit('update:autoRestTimer', $event)"
                    />
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
