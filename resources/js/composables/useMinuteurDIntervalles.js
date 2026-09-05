import { computed, onMounted, onUnmounted, ref } from 'vue'

/**
 * Le minuteur d'intervalles : une machine à quatre phases (échauffement,
 * travail, repos, terminé) qu'un seul `tick` par seconde fait avancer, les
 * bips du décompte et des transitions, et l'habillage de la phase en cours.
 * La page ne garde que ses onglets et ses préréglages.
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

    const audioCtx = ref(null)

    const getAudioCtx = () => {
        if (!audioCtx.value) {
            const AudioContext = window.AudioContext || window.webkitAudioContext
            if (AudioContext) {
                audioCtx.value = new AudioContext()
            }
        }
        // Resume if suspended (browser policy)
        if (audioCtx.value && audioCtx.value.state === 'suspended') {
            audioCtx.value.resume()
        }
        return audioCtx.value
    }

    const playBeep = (freq = 440, duration = 200) => {
        try {
            const ctx = getAudioCtx()
            if (!ctx) return

            const osc = ctx.createOscillator()
            const gain = ctx.createGain()

            osc.connect(gain)
            gain.connect(ctx.destination)

            osc.type = 'sine'
            osc.frequency.value = freq

            osc.start()
            gain.gain.exponentialRampToValueAtTime(0.00001, ctx.currentTime + duration / 1000)
            osc.stop(ctx.currentTime + duration / 1000)
        } catch (e) {
            console.error('Audio error', e)
        }
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

    const startTimer = () => {
        // Initialize audio context on user gesture
        getAudioCtx()

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

    onUnmounted(() => {
        stopInterval()
        if (audioCtx.value) {
            audioCtx.value.close()
        }
    })

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
