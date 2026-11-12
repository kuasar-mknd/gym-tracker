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
import GlassIcon from '@/Components/UI/GlassIcon.vue'
import GlassToggle from '@/Components/UI/GlassToggle.vue'

/** Combien de temps « Repos terminé » reste affiché avant que la carte se ferme. */
const DELAI_DE_FERMETURE_MS = 4000

/**
 * Component Props
 *
 * @property {Number} duration - The initial duration of the timer in seconds (default: 90).
 * @property {Boolean} autoStart - Whether to start the timer immediately upon mounting (default: true).
 *
 * Le composant se monte seul (tests/js/components/restTimer.test.js) : ce qui
 * suit vaut pour tout parent, pas seulement pour la page de séance. Celle-ci
 * ne passe pas `autoStart`, et le remonte à chaque repos (`:key="timerRun"`).
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
     * partant : un parent qui le démonte à sa fermeture lui passe donc la sienne,
     * sans quoi le bip se couperait aussitôt parti.
     */
    sortieAudio: {
        type: Object,
        default: null,
    },
})

/**
 * Component Emits
 *
 * @event close - Fired when the user closes the timer, or a few seconds
 *   after the end of the rest, que la carte annonce entre-temps
 *   (« Repos terminé », `role="status"`). Un parent ferme la carte sur `close`.
 */
const emit = defineEmits(['close', 'update:autoRestTimer'])

// --- State ---

/** Remaining time in seconds. */
const timeLeft = ref(props.duration)

/** Whether the timer is currently running. */
const isActive = ref(false)

/** Interval ID for the timer loop. */
const timer = ref(null)

/** Timestamp (ms) when the timer should finish. */
const endTime = ref(null)

/** Le repos est fini : la carte le dit, quelques secondes, avant de se fermer. */
const termine = ref(false)

/** Le minuteur qui ferme la carte une fois la fin annoncée. */
const fermeture = ref(null)

/** Où sonne la fin : la sortie de la page, ou la sienne quand il est monté seul. */
const sortieAudio = props.sortieAudio ?? useSortieAudio()

/**
 * Le remplissage de la barre de progression, en pourcentage.
 *
 * Bornée, parce que le temps restant peut dépasser la durée sur laquelle la
 * barre se mesure : une durée qui raccourcit pendant un repos ne touche pas un
 * décompte en cours (l'observateur de `duration`, plus bas), et le bouton
 * « +30s », retiré depuis, faisait de même. Le remplissage débordait de sa
 * piste, et la barre annonçait un aria-valuenow au-dessus de son propre
 * aria-valuemax, une valeur qu'un lecteur d'écran peut tenir pour absurde.
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

/**
 * Starts the countdown timer.
 *
 * La garde tient l'invariant d'un seul intervalle à la fois, contrat du
 * composant monté seul (#1513). Aucun chemin d'aujourd'hui ne la franchit : le
 * montage ne démarre qu'une fois, et la bascule ne démarre qu'à l'arrêt, quand
 * `pauseTimer()` a déjà vidé `timer`. Un second intervalle ferait tomber deux
 * secondes par seconde : elle reste, sans coût.
 */
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
 * Bascule entre la marche et la pause.
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

/** Termine le repos tout de suite, dans un geste qui reprend la sortie avant d'y sonner. */
const skipTimer = () => {
    triggerHaptic('tap')
    sortieAudio.preparer()
    finishTimer()
    close()
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
 * La fin du repos : le retour haptique, le bip, puis l'annonce « Repos terminé ».
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

    termine.value = true
    fermeture.value = setTimeout(() => emit('close'), DELAI_DE_FERMETURE_MS)
}

/** Closes the timer component. */
const close = () => {
    triggerHaptic('tap')
    pauseTimer()
    clearTimeout(fermeture.value)
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
    clearTimeout(fermeture.value)
    pauseTimer()
})

/*
 * Une durée qui change pendant que le minuteur attend se prend ; pendant un
 * décompte, elle ne le touche pas. La page de séance remonte le minuteur à
 * chaque repos et n'y passe jamais : c'est le contrat du composant monté seul,
 * qu'un parent peut garder monté (#1513), et que
 * tests/js/components/restTimer.test.js éprouve.
 */
watch(
    () => props.duration,
    (newVal) => {
        if (!isActive.value) {
            clearTimeout(fermeture.value)
            termine.value = false
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
                        <div
                            role="status"
                            class="text-xs font-bold tracking-wider uppercase"
                            :class="termine ? 'text-text-main' : 'text-text-main/60'"
                            dusk="rest-timer-status"
                        >
                            {{ termine ? 'Repos terminé' : 'Repos en cours' }}
                        </div>
                        <div class="text-text-main text-3xl font-black tabular-nums" role="timer" aria-atomic="true">
                            {{ formatTime(timeLeft) }}
                        </div>
                    </div>

                    <div class="flex shrink-0 items-center gap-2">
                        <button
                            v-if="!termine"
                            @click="toggleTimer"
                            class="bg-accent-primary focus-visible:ring-accent-primary text-text-on-accent shadow-accent-primary/20 flex h-11 w-11 shrink-0 items-center justify-center rounded-full shadow-lg transition hover:brightness-110 focus-visible:ring-2 focus-visible:outline-none active:scale-95"
                            :title="isActive ? 'Pause' : 'Démarrer le minuteur'"
                            :aria-label="isActive ? 'Pause' : 'Démarrer le minuteur'"
                        >
                            <!-- Les ligatures de la police d'icônes, pas un dessin à la main (#2122). -->
                            <GlassIcon :name="isActive ? 'pause' : 'play_arrow'" size="sm" />
                        </button>

                        <button
                            @click="close"
                            dusk="close-timer-x"
                            class="focus-visible:ring-accent-primary bg-surface-sunken/50 text-text-muted hover:bg-surface-sunken flex h-11 w-11 shrink-0 items-center justify-center rounded-full transition focus-visible:ring-2 focus-visible:outline-none active:scale-95"
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

                <div v-if="!termine" class="mt-4 flex gap-2">
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
