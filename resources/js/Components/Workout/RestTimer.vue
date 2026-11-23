<!--
  Components/Workout/RestTimer.vue

  La carte « Repos » de la séance (maquette de navigation v3) : dans le flux de
  la page, sous l'en-tête, pas par-dessus les exercices. À gauche le décompte,
  qu'un appui met en pause et reprend ; à droite la série qui suit et la jauge ;
  dessous « Passer » et « Fermer ». Le démarrage automatique se règle dans les
  paramètres de la séance.
-->
<script setup>
import { ref, nextTick, onMounted, onUnmounted, computed, watch } from 'vue'
import { triggerHaptic } from '@/composables/useHaptics'
import { useSortieAudio } from '@/composables/useSortieAudio'

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

    /** La série qui suit, dite sous le décompte (« Ensuite, série 3 sur 4 ») : vide, rien n'est dit. */
    ensuite: {
        type: String,
        default: '',
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

    /**
     * Un repos repris en route (#2434) : la page l'avait laissé en partant, et
     * le minuteur remonté part de ce qu'il reste, sur la durée d'origine
     * (la barre de progression se mesure sur elle). Sans lui, il part de
     * `duration`, comme avant.
     */
    secondesRestantes: {
        type: Number,
        default: null,
    },

    /** Le repos repris était en pause : le minuteur attend le geste de reprise. */
    enPause: {
        type: Boolean,
        default: false,
    },
})

/**
 * Component Emits
 *
 * @event close - Fired when the user closes the timer, or a few seconds
 *   after the end of the rest, que la carte annonce entre-temps
 *   (« Repos terminé », `role="status"`). Un parent ferme la carte sur `close`.
 */
const emit = defineEmits(['close', 'pause', 'reprise'])

// --- State ---

/** Remaining time in seconds. */
const timeLeft = ref(props.secondesRestantes ?? props.duration)

/** Whether the timer is currently running. */
const isActive = ref(false)

/** Le repos attend la reprise : mis en pause d'un appui, ou repris en pause. */
const enPauseDemandee = ref(props.enPause)

/** Interval ID for the timer loop. */
const timer = ref(null)

/** Timestamp (ms) when the timer should finish. */
const endTime = ref(null)

/** Le repos est fini : la carte le dit, quelques secondes, avant de se fermer. */
const termine = ref(false)

/** Le minuteur qui ferme la carte une fois la fin annoncée. */
const fermeture = ref(null)

/** Passer disparaît à la fin du repos : le focus qu'il portait passe à « Fermer ». */
const boutonPasser = ref(null)
const boutonBascule = ref(null)
const boutonFermer = ref(null)
const carte = ref(null)

/** La page démonte la carte sur `close` : le focus qu'elle portait, ou perdu, passe au contenu principal. */
const fermerLaCarte = () => {
    const actif = document.activeElement
    const perdu = actif === null || actif === document.body || carte.value?.contains(actif) === true

    emit('close')

    if (perdu) {
        document.getElementById('main-content')?.focus({ preventScroll: true })
    }
}

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
        enPauseDemandee.value = true
        emit('pause', timeLeft.value)
    } else {
        sortieAudio.preparer()
        startTimer()
        enPauseDemandee.value = false
        emit('reprise', timeLeft.value)
    }
}

/** Termine le repos tout de suite, dans un geste qui reprend la sortie avant d'y sonner. */
const skipTimer = () => {
    triggerHaptic('tap')
    sortieAudio.preparer()
    finishTimer(false)
    clearTimeout(fermeture.value)
    fermerLaCarte()
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
const finishTimer = (rendreLeFocus = true) => {
    const focusSurUnBoutonRetire = [boutonBascule.value, boutonPasser.value].includes(document.activeElement)

    pauseTimer()
    timeLeft.value = 0

    // Haptic feedback
    triggerHaptic('timer')

    sortieAudio.jouer(jouerLeBipDeFin)

    termine.value = true
    fermeture.value = setTimeout(fermerLaCarte, DELAI_DE_FERMETURE_MS)

    if (rendreLeFocus && focusSurUnBoutonRetire) {
        nextTick(() => boutonFermer.value?.focus())
    }
}

/** Closes the timer component. */
const close = () => {
    triggerHaptic('tap')
    pauseTimer()
    clearTimeout(fermeture.value)
    fermerLaCarte()
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
    if (props.autoStart && !props.enPause) {
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
    <div ref="carte" class="carte-portable verre-regulier animate-slide-up flex flex-col gap-3 rounded-carte bg-surface-card/90 p-4">
        <div class="flex items-center justify-between gap-4">
            <button
                ref="boutonBascule"
                type="button"
                class="focus-visible:ring-accent-primary -m-1 min-w-0 rounded-xl p-1 text-left focus-visible:ring-2 focus-visible:outline-none active:scale-95"
                :aria-label="enPauseDemandee ? 'Reprendre le repos' : 'Mettre le repos en pause'"
                :disabled="termine"
                dusk="toggle-rest-timer"
                @click="toggleTimer"
            >
                <span
                    role="status"
                    class="text-text-muted block text-sm"
                    :class="termine ? 'text-text-main font-medium' : ''"
                    dusk="rest-timer-status"
                >
                    {{ termine ? 'Repos terminé' : enPauseDemandee ? 'Repos en pause' : 'Repos' }}
                </span>
                <span class="text-text-main block text-3xl font-medium tabular-nums" role="timer" aria-atomic="true">
                    {{ formatTime(timeLeft) }}
                </span>
            </button>

            <div class="flex min-w-0 flex-col items-end gap-2">
                <span v-if="ensuite" class="text-text-muted text-right text-sm" dusk="rest-timer-ensuite">
                    {{ ensuite }}
                </span>
                <div
                    class="bg-surface-sunken h-1 w-24 overflow-hidden rounded-full"
                    role="progressbar"
                    :aria-valuenow="progress"
                    aria-valuemin="0"
                    aria-valuemax="100"
                    :aria-valuetext="formatTime(timeLeft)"
                >
                    <div
                        class="bg-accent-primary h-full rounded-full transition duration-1000 ease-linear"
                        :style="{ width: `${progress}%` }"
                    ></div>
                </div>
            </div>
        </div>

        <div class="flex gap-2">
            <button
                v-if="!termine"
                ref="boutonPasser"
                type="button"
                dusk="skip-rest-timer"
                class="focus-visible:ring-accent-primary border-border text-text-main hover:bg-surface-sunken min-h-touch flex flex-1 items-center justify-center rounded-xl border px-4 py-2 text-sm font-semibold transition focus-visible:ring-2 focus-visible:outline-none active:scale-95"
                title="Passer le repos"
                aria-label="Passer le repos"
                @click="skipTimer"
            >
                Passer
            </button>
            <button
                ref="boutonFermer"
                type="button"
                dusk="close-timer-x"
                class="focus-visible:ring-accent-primary text-text-muted hover:bg-surface-sunken min-h-touch flex flex-1 items-center justify-center rounded-xl px-4 py-2 text-sm font-semibold transition focus-visible:ring-2 focus-visible:outline-none active:scale-95"
                aria-label="Fermer le minuteur"
                title="Fermer le minuteur"
                @click="close"
            >
                Fermer
            </button>
        </div>
    </div>
</template>
