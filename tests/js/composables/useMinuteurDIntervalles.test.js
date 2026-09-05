import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { mount } from '@vue/test-utils'
import { defineComponent, h } from 'vue'

import { useMinuteurDIntervalles } from '@/composables/useMinuteurDIntervalles'

/** Chaque oscillateur demandé, dans l'ordre : sa fréquence dit quel bip c'était. */
let bips

class FausseSortieAudio {
    constructor() {
        this.state = 'running'
        this.currentTime = 0
        this.destination = {}
        this.fermee = false
        FausseSortieAudio.instances.push(this)
    }
    createOscillator() {
        const osc = { type: null, frequency: { value: null }, connect() {}, start() {}, stop() {} }
        bips.push(osc)
        return osc
    }
    createGain() {
        return { connect() {}, gain: { exponentialRampToValueAtTime() {} } }
    }
    resume() {}
    close() {
        this.fermee = true
    }
}

/** Le composable pose ses crochets de cycle de vie : il lui faut un composant hôte. */
const monter = () => {
    let minuteur
    const wrapper = mount(
        defineComponent({
            setup() {
                minuteur = useMinuteurDIntervalles()
                return () => h('div')
            },
        }),
    )
    return { wrapper, minuteur }
}

const sprint = { name: 'Sprint', work: 3, rest: 2, rounds: 2, warmup: 2 }

beforeEach(() => {
    vi.useFakeTimers()
    bips = []
    FausseSortieAudio.instances = []
    window.AudioContext = FausseSortieAudio
})

afterEach(() => {
    vi.useRealTimers()
    delete window.AudioContext
})

describe('le minuteur d’intervalles', () => {
    it('part sur l’échauffement s’il y en a un, sinon sur le travail, et se dit prêt', () => {
        const { minuteur } = monter()

        expect(minuteur.formattedTime.value).toBe('00:10')
        expect(minuteur.phaseLabel.value).toBe('PRÊT')

        minuteur.charger({ ...sprint, warmup: 0, work: 90 })

        expect(minuteur.formattedTime.value).toBe('01:30')
        expect(minuteur.currentRound.value).toBe(1)
    })

    it('enchaîne échauffement, travail, repos et travail jusqu’au dernier tour, puis s’arrête', () => {
        const { minuteur } = monter()
        minuteur.charger(sprint)

        minuteur.startTimer()
        expect(minuteur.phaseLabel.value).toBe('ÉCHAUFFEMENT')
        expect(minuteur.status.value).toBe('running')

        vi.advanceTimersByTime(2000)
        expect(minuteur.phaseLabel.value).toBe('TRAVAIL')
        expect(minuteur.timeLeft.value).toBe(3)

        vi.advanceTimersByTime(3000)
        expect(minuteur.phaseLabel.value).toBe('REPOS')
        expect(minuteur.currentRound.value).toBe(1)

        vi.advanceTimersByTime(2000)
        expect(minuteur.phaseLabel.value).toBe('TRAVAIL')
        expect(minuteur.currentRound.value).toBe(2)

        vi.advanceTimersByTime(3000)
        expect(minuteur.phaseLabel.value).toBe('TERMINÉ')
        expect(minuteur.status.value).toBe('finished')
        expect(minuteur.formattedTime.value).toBe('00:00')

        vi.advanceTimersByTime(10000)
        expect(minuteur.phaseLabel.value).toBe('TERMINÉ')
    })

    it('se met en pause sans perdre sa place, et repart de là', () => {
        const { minuteur } = monter()
        minuteur.charger({ ...sprint, warmup: 0, work: 10 })

        minuteur.toggleTimer()
        vi.advanceTimersByTime(4000)
        expect(minuteur.timeLeft.value).toBe(6)

        minuteur.toggleTimer()
        expect(minuteur.status.value).toBe('paused')
        vi.advanceTimersByTime(5000)
        expect(minuteur.timeLeft.value).toBe(6)

        minuteur.toggleTimer()
        vi.advanceTimersByTime(1000)
        expect(minuteur.timeLeft.value).toBe(5)
        expect(minuteur.status.value).toBe('running')
    })

    it('revient au départ sur demande, et repart du début quand on le relance après la fin', () => {
        const { minuteur } = monter()
        minuteur.charger({ ...sprint, warmup: 0, work: 2, rounds: 1 })

        minuteur.startTimer()
        vi.advanceTimersByTime(1000)
        minuteur.resetRunner()
        expect(minuteur.status.value).toBe('idle')
        expect(minuteur.phaseLabel.value).toBe('PRÊT')
        vi.advanceTimersByTime(5000)
        expect(minuteur.timeLeft.value).toBe(2)

        minuteur.startTimer()
        vi.advanceTimersByTime(2000)
        expect(minuteur.status.value).toBe('finished')

        minuteur.startTimer()
        expect(minuteur.status.value).toBe('running')
        expect(minuteur.phaseLabel.value).toBe('TRAVAIL')
        expect(minuteur.timeLeft.value).toBe(2)
        expect(minuteur.currentRound.value).toBe(1)
    })

    it('habille la phase en cours, et la fin comme le repos initial', () => {
        const { minuteur } = monter()
        minuteur.charger({ ...sprint, warmup: 1, work: 1, rest: 1, rounds: 2 })

        expect(minuteur.phaseColor.value).toBe('text-text-main')
        expect(minuteur.phaseBg.value).toBe('bg-surface-sunken border-surface-sunken')

        minuteur.startTimer()
        expect(minuteur.phaseColor.value).toBe('text-accent-info-deep')
        vi.advanceTimersByTime(1000)
        expect(minuteur.phaseBg.value).toBe('bg-accent-primary/10 border-accent-primary/20')
        vi.advanceTimersByTime(1000)
        expect(minuteur.phaseColor.value).toBe('text-accent-state-deep')
        vi.advanceTimersByTime(2000)
        expect(minuteur.phaseLabel.value).toBe('TERMINÉ')
        expect(minuteur.phaseColor.value).toBe('text-text-main')
    })

    it('bipe sur les trois dernières secondes, plus haut au changement de phase et plus haut encore à la fin', () => {
        const { minuteur } = monter()
        minuteur.charger({ ...sprint, warmup: 0, work: 4, rounds: 1 })

        minuteur.startTimer()
        vi.advanceTimersByTime(4000)

        expect(bips.map((osc) => osc.frequency.value)).toEqual([440, 440, 440, 880, 1200])
    })

    it('court sans bruit là où le navigateur n’a pas de sortie audio', () => {
        delete window.AudioContext
        const { minuteur } = monter()
        minuteur.charger({ ...sprint, warmup: 0, work: 2, rounds: 1 })

        minuteur.startTimer()
        vi.advanceTimersByTime(2000)

        expect(minuteur.status.value).toBe('finished')
        expect(bips).toEqual([])
    })

    it('rend l’intervalle et la sortie audio quand la page part', () => {
        const { wrapper, minuteur } = monter()
        minuteur.charger({ ...sprint, warmup: 0, work: 10 })
        minuteur.startTimer()
        vi.advanceTimersByTime(1000)

        wrapper.unmount()
        vi.advanceTimersByTime(5000)

        expect(minuteur.timeLeft.value).toBe(9)
        expect(FausseSortieAudio.instances).toHaveLength(1)
        expect(FausseSortieAudio.instances[0].fermee).toBe(true)
    })
})
