import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { defineComponent, h } from 'vue'

import { useMinuteurDIntervalles } from '@/composables/useMinuteurDIntervalles'
import {
    cacherLaPage,
    demandes,
    installerLeVerrouDEcran,
    montrerLaPage,
    retirerLeVerrouDEcran,
    verrouDEcran,
    verrous,
    verrousActifs,
} from '../utils/verrouDEcranSimule'

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

/**
 * Chaque hôte monté par un cas, démonté après lui s'il ne l'est pas déjà : un
 * minuteur laissé monté garde ses minuteries et son écoute de la page, et
 * répondrait au retour de la page d'un cas suivant.
 */
let hotes = []

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
    hotes.push(wrapper)
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
    hotes.filter((hote) => !hote.vm.$.isUnmounted).forEach((hote) => hote.unmount())
    hotes = []
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

    it('sonne la fin une seule fois, au bout du dernier travail, même quand le repos qui le précède finit une seconde avant', () => {
        const { minuteur } = monter()
        minuteur.charger({ ...sprint, warmup: 0, work: 1, rest: 1, rounds: 2 })

        minuteur.startTimer()
        vi.advanceTimersByTime(3000)

        expect(minuteur.status.value).toBe('finished')
        expect(bips.map((osc) => osc.frequency.value)).toEqual([880, 880, 880, 1200])
    })

    /*
     * Un repos de 0 s, que le formulaire et le serveur permettent, ne dure rien
     * (#2113) : le travail suivant enchaîne, sur un seul bip de changement. Au
     * compte des tics, il durait une seconde, « REPOS 00:00 » entre deux bips
     * de changement, et décalait la séance d'une seconde par tour.
     */
    it('enchaîne les travaux sans repos quand le repos dure 0 s', () => {
        const { minuteur } = monter()
        minuteur.charger({ ...sprint, warmup: 0, work: 2, rest: 0, rounds: 2 })

        minuteur.startTimer()
        vi.advanceTimersByTime(2000)

        expect(minuteur.phaseLabel.value).toBe('TRAVAIL')
        expect(minuteur.currentRound.value).toBe(2)
        expect(minuteur.formattedTime.value).toBe('00:02')

        vi.advanceTimersByTime(2000)

        expect(minuteur.status.value).toBe('finished')
        expect(bips.map((osc) => osc.frequency.value)).toEqual([440, 880, 440, 880, 1200])
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

/**
 * Le minuteur lit l'horloge (#2113). Il avançait en ôtant une seconde à chaque
 * tic, sans retenir ni son départ ni sa fin : une page suspendue (l'écran
 * verrouillé sur iOS, l'application en arrière-plan) ou une minuterie espacée
 * (un onglet caché dans Chrome, un onglet gelé sur Android) le figeait, et il
 * repartait d'où il s'était figé, phase, tour et temps restant faux. Il retient
 * désormais le temps de marche (le départ, moins les pauses) et en tire la
 * phase, le tour et le temps restant à chaque minuterie et au retour de la
 * page ; un bip qui a une seconde de retard ou plus ne se rejoue pas. Et il
 * garde l'écran allumé tant qu'il tourne (`navigator.wakeLock`), sans quoi
 * l'écran se verrouille au bout du délai réglé sur l'appareil.
 *
 * Une suspension se joue en avançant l'horloge sans jouer les minuteries
 * (`vi.setSystemTime`), la page cachée puis rendue visible ; le verrou d'écran
 * et la visibilité de la page sont ceux du faux de `tests/js/utils/verrouDEcranSimule.js`.
 */
describe('le minuteur d’intervalles lit l’horloge et garde l’écran allumé (#2113)', () => {
    const tabata = { name: 'Tabata', work: 20, rest: 10, rounds: 8, warmup: 10 }

    /** L'écran verrouillé : la page se cache, l'horloge avance de `ms` sans qu'aucune minuterie ne parte, puis la page revient. */
    const suspendre = (ms) => {
        cacherLaPage()
        vi.setSystemTime(Date.now() + ms)
        montrerLaPage()
    }

    const notes = () => bips.map((osc) => osc.frequency.value)

    beforeEach(() => installerLeVerrouDEcran())
    afterEach(() => retirerLeVerrouDEcran())

    it('suspendu une minute, se recale sur l’horloge au retour de la page : phase, tour et temps restant', () => {
        const { minuteur } = monter()
        minuteur.charger(tabata)
        minuteur.startTimer()
        vi.advanceTimersByTime(6000)
        expect(minuteur.formattedTime.value).toBe('00:04')

        suspendre(60_000)

        // 66 s après le départ : échauffement 10, travail 20, repos 10, travail 20, puis 6 s du second repos.
        expect(minuteur.phaseLabel.value).toBe('REPOS')
        expect(minuteur.formattedTime.value).toBe('00:04')
        expect(minuteur.currentRound.value).toBe(2)
        expect(minuteur.status.value).toBe('running')
    })

    it('ne rejoue pas les bips manqués pendant la suspension, puis sonne chacun à son heure', () => {
        const { minuteur } = monter()
        minuteur.charger(tabata)
        minuteur.startTimer()
        vi.advanceTimersByTime(6000)

        suspendre(60_000)
        expect(notes()).toEqual([])

        vi.advanceTimersByTime(1000)
        expect(notes()).toEqual([440])
        expect(minuteur.formattedTime.value).toBe('00:03')

        vi.advanceTimersByTime(3000)
        expect(notes()).toEqual([440, 440, 440, 880])
        expect(minuteur.phaseLabel.value).toBe('TRAVAIL')
        expect(minuteur.currentRound.value).toBe(3)
        expect(minuteur.formattedTime.value).toBe('00:20')
    })

    it('lit l’horloge quand sa minuterie part en retard, retenue par l’arrière-plan, au lieu d’ôter une seconde', () => {
        const { minuteur } = monter()
        minuteur.charger(tabata)
        minuteur.startTimer()
        vi.advanceTimersByTime(5000)

        // Un onglet caché espace ses minuteries : la suivante part trente secondes plus tard, sans évènement de la page.
        vi.setSystemTime(Date.now() + 30_000)
        vi.advanceTimersToNextTimer()

        expect(minuteur.phaseLabel.value).toBe('REPOS')
        expect(minuteur.formattedTime.value).toBe('00:04')
        expect(minuteur.currentRound.value).toBe(1)
        expect(notes()).toEqual([])
    })

    it('ne compte pas le temps passé en pause, même suspendu, ni ne perd la fraction de seconde où la pause a pris', () => {
        const { minuteur } = monter()
        minuteur.charger({ ...sprint, warmup: 0, work: 10 })
        minuteur.startTimer()
        vi.advanceTimersByTime(4300)
        minuteur.toggleTimer()
        expect(minuteur.status.value).toBe('paused')

        suspendre(5 * 60_000)

        expect(minuteur.status.value).toBe('paused')
        expect(minuteur.formattedTime.value).toBe('00:06')

        minuteur.toggleTimer()
        vi.advanceTimersByTime(699)
        expect(minuteur.formattedTime.value).toBe('00:06')

        // 4,3 s de marche avant la pause : la seconde suivante tombe 0,7 s après la reprise, pas une seconde entière.
        vi.advanceTimersByTime(1)
        expect(minuteur.formattedTime.value).toBe('00:05')
    })

    /*
     * L'aperçu « Lancer » du formulaire n'est pas validé : un nombre de tours
     * démesuré y part tel quel. L'étape d'un instant se calcule, sans liste
     * des tours dressée d'avance, qui bloquerait la page ; et un retour
     * dix jours plus tard ne lit que les étapes de la dernière seconde.
     */
    it('lance un préréglage démesuré sans rien dresser d’avance, et le retrouve juste dix jours plus tard', () => {
        const { minuteur } = monter()
        minuteur.charger({ name: 'Sans fin', work: 1, rest: 1, rounds: 1_000_000_000, warmup: 0 })
        minuteur.startTimer()
        vi.advanceTimersByTime(3000)

        expect(minuteur.phaseLabel.value).toBe('REPOS')
        expect(minuteur.currentRound.value).toBe(2)

        suspendre(10 * 24 * 3_600_000)

        // 864 003 s de marche, des tours de 2 s : le repos du tour 432 002 commence à l'instant même du retour.
        // Seul ce changement-là sonne, à l'heure ; les 864 000 d'avant ne se rejouent pas.
        expect(minuteur.phaseLabel.value).toBe('REPOS')
        expect(minuteur.currentRound.value).toBe(432_002)
        expect(minuteur.formattedTime.value).toBe('00:01')
        expect(notes()).toEqual([880, 880, 880, 880])

        vi.advanceTimersByTime(1000)
        expect(minuteur.phaseLabel.value).toBe('TRAVAIL')
        expect(minuteur.currentRound.value).toBe(432_003)
        expect(notes()).toEqual([880, 880, 880, 880, 880])
    })

    it('revenu après la fin, se dit terminé, sans sonner ni laisser de minuterie, et ne redemande pas le verrou', async () => {
        const { minuteur } = monter()
        minuteur.charger(sprint)
        minuteur.startTimer()
        vi.advanceTimersByTime(1000)
        await flushPromises()
        // La dernière seconde de l'échauffement a sonné ; la séance dure 10 s.
        expect(notes()).toEqual([440])

        suspendre(60_000)
        await flushPromises()

        expect(minuteur.status.value).toBe('finished')
        expect(minuteur.phaseLabel.value).toBe('TERMINÉ')
        expect(minuteur.formattedTime.value).toBe('00:00')
        expect(minuteur.currentRound.value).toBe(2)
        expect(notes()).toEqual([440])
        expect(vi.getTimerCount()).toBe(0)
        expect(demandes).toHaveLength(1)
        expect(verrousActifs()).toEqual([])
    })

    it('garde l’écran allumé tant qu’il tourne : demandé au départ, relâché en pause, redemandé à la reprise, relâché à la fin', async () => {
        const { minuteur } = monter()
        minuteur.charger({ ...sprint, warmup: 0, work: 3, rounds: 1 })

        minuteur.startTimer()
        await flushPromises()
        expect(demandes).toEqual([{ type: 'screen', visibilite: 'visible' }])
        expect(verrousActifs()).toEqual([verrous[0]])

        minuteur.toggleTimer()
        expect(verrous[0]).toMatchObject({ released: true, relachePar: 'page' })

        minuteur.toggleTimer()
        await flushPromises()
        expect(verrousActifs()).toEqual([verrous[1]])

        vi.advanceTimersByTime(3000)
        expect(minuteur.status.value).toBe('finished')
        expect(verrous[1]).toMatchObject({ released: true, relachePar: 'page' })
        expect(verrousActifs()).toEqual([])
    })

    it('relâche le verrou à la réinitialisation et au démontage', async () => {
        const { wrapper, minuteur } = monter()
        minuteur.charger(tabata)

        minuteur.startTimer()
        await flushPromises()
        minuteur.resetRunner()
        expect(verrous[0]).toMatchObject({ released: true, relachePar: 'page' })

        minuteur.startTimer()
        await flushPromises()
        wrapper.unmount()

        expect(verrous[1]).toMatchObject({ released: true, relachePar: 'page' })
        expect(verrousActifs()).toEqual([])
        expect(vi.getTimerCount()).toBe(0)
    })

    it('redemande au retour de la page le verrou que le navigateur a relâché en la cachant, et pas en pause', async () => {
        const { minuteur } = monter()
        minuteur.charger(tabata)
        minuteur.startTimer()
        await flushPromises()

        cacherLaPage()
        expect(verrous[0]).toMatchObject({ released: true, relachePar: 'systeme' })
        montrerLaPage()
        await flushPromises()

        expect(demandes).toHaveLength(2)
        expect(verrousActifs()).toEqual([verrous[1]])

        minuteur.toggleTimer()
        cacherLaPage()
        montrerLaPage()
        await flushPromises()

        expect(demandes).toHaveLength(2)
        expect(verrousActifs()).toEqual([])
    })

    it('tourne jusqu’au bout quand le navigateur refuse le verrou d’écran, ou ne l’a pas', async () => {
        verrouDEcran.refuser = true
        const refuse = monter().minuteur
        refuse.charger({ ...sprint, warmup: 0, work: 2, rounds: 1 })
        refuse.startTimer()
        await flushPromises()
        vi.advanceTimersByTime(2000)

        expect(demandes).toHaveLength(1)
        expect(verrous).toEqual([])
        expect(refuse.status.value).toBe('finished')

        installerLeVerrouDEcran({ sansApi: true })
        const sansApi = monter().minuteur
        sansApi.charger({ ...sprint, warmup: 0, work: 2, rounds: 1 })
        sansApi.startTimer()
        vi.advanceTimersByTime(2000)

        expect(sansApi.status.value).toBe('finished')
    })
})
