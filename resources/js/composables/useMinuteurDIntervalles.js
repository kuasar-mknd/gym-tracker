import { computed, onMounted, onUnmounted, ref } from 'vue'
import { useSortieAudio } from '@/composables/useSortieAudio'

/**
 * Le minuteur d'intervalles : une machine à quatre phases (échauffement,
 * travail, repos, terminé) qu'un seul `tick` par seconde fait avancer, les
 * bips du décompte et des transitions, et l'habillage de la phase en cours.
 * La page ne garde que ses onglets et ses préréglages.
 *
 * Les bips passent par `useSortieAudio` (#2156) : « Démarrer », qui est aussi
 * le geste de la reprise après une pause, ouvre ou reprend la sortie (neuve,
 * `suspended`, `interrupted` après un appel ou Siri, ou recréée si elle est
 * `closed`), et chaque bip s'y joue ensuite depuis la minuterie, sans jamais
 * partir à contretemps sur une sortie arrêtée. La sortie se ferme au démontage
 * du composant qui appelle le minuteur.
 */
export function useMinuteurDIntervalles() {
    const timerConfig = ref({
        name: 'Tabata',
        work: 20,
        rest: 10,
        rounds: 8,
        warmup: 10,
    })

    const status = ref('idle') // 'idle', 'running', 'paused', 'finished'
    const phase = ref('idle') // 'idle', 'warmup', 'work', 'rest', 'finished'
    const timeLeft = ref(0)
    const currentRound = ref(1)
    let intervalId = null

    const { preparer, jouer } = useSortieAudio()

    /**
     * Un bip : une sinusoïde à `freq` Hz, qui s'éteint en `duration` ms. 440 Hz
     * pendant 100 ms pour chacune des trois dernières secondes d'une phase,
     * 880 Hz pendant 400 ms au changement de phase, 1 200 Hz pendant 600 ms à
     * la fin. Sans sortie ouverte par un geste, ou sur une sortie qui ne
     * repart pas à temps, il ne se joue pas ; un son qui échoue ne fait rien
     * tomber (`useSortieAudio`).
     *
     * @param {number} freq
     * @param {number} duration
     */
    const playBeep = (freq = 440, duration = 200) => {
        jouer((ctx) => {
            const osc = ctx.createOscillator()
            const gain = ctx.createGain()

            osc.connect(gain)
            gain.connect(ctx.destination)

            osc.type = 'sine'
            osc.frequency.value = freq

            osc.start()
            gain.gain.exponentialRampToValueAtTime(0.00001, ctx.currentTime + duration / 1000)
            osc.stop(ctx.currentTime + duration / 1000)
        })
    }

    const stopInterval = () => {
        if (intervalId) {
            clearInterval(intervalId)
            intervalId = null
        }
    }

    const resetRunner = () => {
        stopInterval()
        status.value = 'idle'
        phase.value = 'idle'
        timeLeft.value = timerConfig.value.warmup > 0 ? timerConfig.value.warmup : timerConfig.value.work
        currentRound.value = 1
    }

    /** Prend une configuration (nom, travail, repos, tours, échauffement) et se remet au départ. */
    const charger = (config) => {
        timerConfig.value = config
        resetRunner()
    }

    const finishTimer = () => {
        status.value = 'finished'
        phase.value = 'finished'
        timeLeft.value = 0
        stopInterval()
        playBeep(1200, 600) // Finish beep
    }

    const handlePhaseTransition = () => {
        playBeep(880, 400) // Phase change beep

        if (phase.value === 'warmup') {
            phase.value = 'work'
            timeLeft.value = timerConfig.value.work
        } else if (phase.value === 'work') {
            if (currentRound.value < timerConfig.value.rounds) {
                phase.value = 'rest'
                timeLeft.value = timerConfig.value.rest
            } else {
                finishTimer()
            }
        } else if (phase.value === 'rest') {
            currentRound.value++
            phase.value = 'work'
            timeLeft.value = timerConfig.value.work
        }
    }

    const tick = () => {
        if (timeLeft.value > 1) {
            timeLeft.value--
            if (timeLeft.value <= 3) {
                playBeep(440, 100) // Countdown beep
            }
        } else {
            handlePhaseTransition()
        }
    }

    /**
     * Démarre, ou reprend après une pause. C'est le geste de « Démarrer » :
     * la sortie s'y ouvre ou s'y reprend, avant toute autre chose, et
     * `preparer` ne lève jamais, si bien qu'un navigateur qui refuse la sortie
     * laisse le minuteur partir, sans son.
     */
    const startTimer = () => {
        preparer()

        if (status.value === 'finished') {
            resetRunner()
        }

        if (status.value === 'idle') {
            if (timerConfig.value.warmup > 0) {
                phase.value = 'warmup'
                timeLeft.value = timerConfig.value.warmup
            } else {
                phase.value = 'work'
                timeLeft.value = timerConfig.value.work
            }
        }

        status.value = 'running'
        intervalId = setInterval(tick, 1000)
    }

    const pauseTimer = () => {
        status.value = 'paused'
        stopInterval()
    }

    const toggleTimer = () => {
        if (status.value === 'running') {
            pauseTimer()
        } else {
            startTimer()
        }
    }

    // Format time mm:ss
    const formattedTime = computed(() => {
        const minutes = Math.floor(timeLeft.value / 60)
        const seconds = timeLeft.value % 60
        return `${minutes.toString().padStart(2, '0')}:${seconds.toString().padStart(2, '0')}`
    })

    const phaseColor = computed(() => {
        switch (phase.value) {
            case 'warmup':
                return 'text-accent-info-deep'
            case 'work':
                return 'text-accent-primary-deep'
            case 'rest':
                return 'text-accent-state-deep'
            default:
                return 'text-text-main'
        }
    })

    const phaseBg = computed(() => {
        switch (phase.value) {
            case 'warmup':
                return 'bg-accent-info/10 border-accent-info/20'
            case 'work':
                return 'bg-accent-primary/10 border-accent-primary/20'
            case 'rest':
                return 'bg-accent-state/10 border-accent-state/20'
            default:
                return 'bg-surface-sunken border-surface-sunken'
        }
    })

    const phaseLabel = computed(() => {
        switch (phase.value) {
            case 'warmup':
                return 'ÉCHAUFFEMENT'
            case 'work':
                return 'TRAVAIL'
            case 'rest':
                return 'REPOS'
            case 'finished':
                return 'TERMINÉ'
            default:
                return 'PRÊT'
        }
    })

    onMounted(resetRunner)

    onUnmounted(stopInterval)

    return {
        timerConfig,
        status,
        phase,
        timeLeft,
        currentRound,
        formattedTime,
        phaseColor,
        phaseBg,
        phaseLabel,
        charger,
        resetRunner,
        toggleTimer,
        startTimer,
        pauseTimer,
    }
}
