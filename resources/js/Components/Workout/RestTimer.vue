<!--
  Components/Workout/RestTimer.vue

  Le repos de la séance, dans la barre d'outils (#2434) : le bouton « Repos »
  devient un anneau et le décompte pendant qu'il court. Un toucher ouvre une
  petite feuille : Pause, Passer, Fermer, et la série qui suit. Le démarrage
  automatique se règle dans les paramètres de la séance.
-->
<script setup>
import { ref, nextTick, onMounted, onUnmounted, computed, watch } from 'vue'
import { triggerHaptic } from '@/composables/useHaptics'
import { useSortieAudio } from '@/composables/useSortieAudio'
import GlassButton from '@/Components/UI/GlassButton.vue'
import Modal from '@/Components/UI/Modal.vue'
import AnneauDuRepos from '@/Components/Workout/AnneauDuRepos.vue'

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

/** La feuille des commandes est ouverte (Pause, Passer, Fermer). */
const feuilleOuverte = ref(false)
const racine = ref(null)

/** Pause et Passer disparaissent à la fin du repos : le focus qu'ils portaient passe à « Fermer ». */
const boutonBascule = ref(null)
const boutonPasser = ref(null)
const boutonFermer = ref(null)

/** La page démonte la carte sur `close` : le focus qu'elle portait, ou perdu, passe au contenu principal. */
const fermerLaCarte = () => {
    const actif = document.activeElement
    const perdu = actif === null || actif === document.body || racine.value?.contains(actif) === true

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
    const focusSurUnBoutonRetire = [boutonBascule.value?.$el, boutonPasser.value?.$el].includes(document.activeElement)

    pauseTimer()
    timeLeft.value = 0

    // Haptic feedback
    triggerHaptic('timer')

    sortieAudio.jouer(jouerLeBipDeFin)

    termine.value = true
    fermeture.value = setTimeout(fermerLaCarte, DELAI_DE_FERMETURE_MS)

    if (rendreLeFocus && focusSurUnBoutonRetire) {
        nextTick(() => boutonFermer.value?.$el.focus())
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
    <div ref="racine" class="contents">
        <GlassButton
            variant="secondary"
            class="bouton-de-barre bureau:w-full flex-auto"
            :class="enPauseDemandee ? 'opacity-70' : ''"
            dusk="rest-timer"
            @click="feuilleOuverte = true"
        >
            <AnneauDuRepos
                :fraction="progress / 100"
                class="bureau:size-5 size-6 @max-[19rem]:hidden"
                dusk="anneau-de-la-barre"
            />
            <span class="sr-only">Repos </span>
            <span class="font-semibold tabular-nums" role="timer" aria-atomic="true">{{ formatTime(timeLeft) }}</span>
        </GlassButton>

        <p class="sr-only" role="status" dusk="rest-timer-status">
            {{ termine ? 'Repos terminé' : enPauseDemandee ? 'Repos en pause' : '' }}
        </p>

        <Modal
            :show="feuilleOuverte"
            position="feuille"
            max-width="sm"
            aria-labelledby="repos-titre"
            @close="feuilleOuverte = false"
        >
            <div class="pb-feuille-6 px-6 pt-1" dusk="feuille-du-repos">
                <h3 id="repos-titre" class="titre-plat mb-2">Repos</h3>
                <p class="text-text-main mb-1 text-5xl font-medium tabular-nums" aria-hidden="true">
                    {{ formatTime(timeLeft) }}
                </p>
                <p v-if="ensuite" class="text-text-muted mb-4 text-sm" dusk="rest-timer-ensuite">{{ ensuite }}</p>
                <div
                    class="bg-surface-sunken mb-6 h-1 w-full overflow-hidden rounded-full"
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
                <div class="flex flex-col gap-2">
                    <GlassButton
                        v-if="!termine"
                        ref="boutonBascule"
                        :aria-label="enPauseDemandee ? 'Reprendre le repos' : 'Mettre le repos en pause'"
                        dusk="toggle-rest-timer"
                        @click="toggleTimer"
                    >
                        {{ enPauseDemandee ? 'Reprendre' : 'Pause' }}
                    </GlassButton>
                    <GlassButton
                        v-if="!termine"
                        ref="boutonPasser"
                        variant="accent"
                        aria-label="Passer le repos"
                        dusk="skip-rest-timer"
                        @click="skipTimer"
                    >
                        Passer
                    </GlassButton>
                    <GlassButton
                        ref="boutonFermer"
                        variant="ghost"
                        aria-label="Fermer le minuteur"
                        dusk="close-timer-x"
                        @click="close"
                    >
                        Fermer
                    </GlassButton>
                </div>
            </div>
        </Modal>
    </div>
</template>
