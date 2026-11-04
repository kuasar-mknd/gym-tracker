import { describe, it, expect, vi, beforeAll, beforeEach, afterEach } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'

import { dansUnGeste, installerLaSortieWebKit, retirerLaSortieWebKit, sorties } from '../utils/sortieAudioWebKit'
import {
    cacherLaPage,
    demandes,
    installerLeVerrouDEcran,
    montrerLaPage,
    retirerLeVerrouDEcran,
    verrous,
    verrousActifs,
} from '../utils/verrouDEcranSimule'

/**
 * Le minuteur d'intervalles affiche et sonne juste après un passage en
 * arrière-plan (#2113), sur la vraie page, avec le vrai composable, la sortie
 * audio factice de WebKit et le verrou d'écran factice.
 *
 * Le minuteur ôtait une seconde à chaque tic : la page suspendue, il repartait
 * d'où il s'était figé, et rien ne retenait l'écran allumé. Il lit désormais
 * l'horloge, et garde l'écran allumé tant qu'il tourne. Sur un iPhone, la
 * sortie audio peut rester interrompue après l'arrière-plan : une pause suivie
 * de « Démarrer » la reprend (#2156), et le minuteur repart de l'heure juste.
 */

vi.mock('@inertiajs/vue3', async () => {
    const { reactive } = await vi.importActual('vue')

    return {
        Head: { template: '<div />' },
        router: { delete: vi.fn() },
        useForm: (data) => reactive({ ...data, processing: false, errors: {}, post: vi.fn(), patch: vi.fn() }),
        // Une page que le serveur a rendue, sans marque de copie hors ligne (#2048).
        usePage: () => ({ props: {} }),
    }
})

import IntervalTimer from '@/Pages/Tools/IntervalTimer.vue'
import { passesSlot } from './pageStubs'

/** Le Tabata de l'issue : 10 s d'échauffement, puis huit tours de 20 s de travail et 10 s de repos. */
const TABATA = { id: 8, name: 'Tabata', work_seconds: 20, rest_seconds: 10, rounds: 8, warmup_seconds: 10 }

beforeAll(() => {
    globalThis.route = (name, param) => `/${name}/${param ?? ''}`
})

/** Chaque page montée par un cas, démontée après lui : une page laissée montée garde ses minuteries et ses écouteurs. */
let montees

beforeEach(() => {
    montees = []
    vi.useFakeTimers()
    installerLaSortieWebKit()
    installerLeVerrouDEcran()
})

afterEach(() => {
    montees.forEach((wrapper) => wrapper.unmount())
    vi.useRealTimers()
    retirerLaSortieWebKit()
    retirerLeVerrouDEcran()
})

const bouton = (wrapper, nom) => wrapper.findAll('button').find((b) => b.attributes('aria-label') === nom)

const ouvrirLeTabata = async () => {
    const wrapper = mount(IntervalTimer, {
        props: { timers: [structuredClone(TABATA)] },
        global: {
            mocks: { route: globalThis.route },
            directives: { press: {} },
            stubs: { AuthenticatedLayout: passesSlot, GlassCard: passesSlot },
        },
    })
    montees.push(wrapper)

    await wrapper.findAll('button')[1].trigger('click')
    await bouton(wrapper, 'Charger et lancer').trigger('click')

    return wrapper
}

/** Ce que la page affiche : la phase, l'horloge et le tour. */
const affichage = (wrapper) => {
    const texte = wrapper.text()

    return {
        phase: ['ÉCHAUFFEMENT', 'TRAVAIL', 'REPOS', 'TERMINÉ', 'PRÊT'].find((nom) => texte.includes(nom)),
        horloge: texte.match(/\d{2}:\d{2}/)?.[0],
        tour: texte.match(/(\d+)\s*\/\s*8/)?.[1],
    }
}

const avancer = async (wrapper, ms) => {
    vi.advanceTimersByTime(ms)
    await wrapper.vm.$nextTick()
}

/** L'écran éteint ou l'application en arrière-plan : la page cachée, l'horloge qui avance sans aucune minuterie, puis le retour. */
const passerEnArrierePlan = async (wrapper, ms, pendant = () => {}) => {
    cacherLaPage()
    vi.setSystemTime(Date.now() + ms)
    pendant()
    montrerLaPage()
    await flushPromises()
    await wrapper.vm.$nextTick()
}

const notesAudibles = () =>
    sorties
        .flatMap((sortie) => sortie.sons)
        .filter((son) => son.audible)
        .map((son) => son.frequence)

describe('le minuteur d’intervalles après l’arrière-plan (#2113)', () => {
    it('demande le verrou d’écran dans le geste de « Démarrer », et le rend en pause', async () => {
        const wrapper = await ouvrirLeTabata()
        let pendantLeGeste

        await dansUnGeste(() => {
            const clic = bouton(wrapper, 'Démarrer').trigger('click')
            pendantLeGeste = demandes.length

            return clic
        })
        await flushPromises()

        expect(pendantLeGeste).toBe(1)
        expect(verrousActifs()).toEqual([verrous[0]])

        await dansUnGeste(() => bouton(wrapper, 'Mettre en pause').trigger('click'))

        expect(verrousActifs()).toEqual([])
    })

    it('revenu d’une minute en arrière-plan, affiche la phase, le temps et le tour de l’horloge, redemande le verrou, et sonne juste ensuite', async () => {
        const wrapper = await ouvrirLeTabata()
        await dansUnGeste(() => bouton(wrapper, 'Démarrer').trigger('click'))
        await avancer(wrapper, 6_000)
        expect(affichage(wrapper)).toEqual({ phase: 'ÉCHAUFFEMENT', horloge: '00:04', tour: '1' })

        await passerEnArrierePlan(wrapper, 60_000)

        expect(affichage(wrapper)).toEqual({ phase: 'REPOS', horloge: '00:04', tour: '2' })
        expect(verrous[0].relachePar).toBe('systeme')
        expect(verrousActifs()).toEqual([verrous[1]])
        expect(notesAudibles()).toEqual([])

        await avancer(wrapper, 4_000)

        expect(affichage(wrapper)).toEqual({ phase: 'TRAVAIL', horloge: '00:20', tour: '3' })
        expect(notesAudibles()).toEqual([440, 440, 440, 880])
    })

    it('sur un iPhone, la sortie interrompue pendant l’arrière-plan reprend à « Démarrer », et le minuteur repart de l’heure juste', async () => {
        const wrapper = await ouvrirLeTabata()
        await dansUnGeste(() => bouton(wrapper, 'Démarrer').trigger('click'))
        await avancer(wrapper, 6_000)

        await passerEnArrierePlan(wrapper, 60_000, () => sorties[0].interrompre())
        await avancer(wrapper, 1_000)

        // Le bip de 67 s est demandé sur la sortie interrompue : il ne s'entend pas, et ne partira pas à contretemps.
        expect(affichage(wrapper)).toEqual({ phase: 'REPOS', horloge: '00:03', tour: '2' })
        expect(notesAudibles()).toEqual([])

        await dansUnGeste(() => bouton(wrapper, 'Mettre en pause').trigger('click'))
        await avancer(wrapper, 30_000)
        await dansUnGeste(() => bouton(wrapper, 'Démarrer').trigger('click'))
        await avancer(wrapper, 3_000)

        expect(sorties).toHaveLength(1)
        expect(affichage(wrapper)).toEqual({ phase: 'TRAVAIL', horloge: '00:20', tour: '3' })
        expect(notesAudibles()).toEqual([440, 440, 880])
        expect(sorties[0].sons.some((son) => son.aContretemps)).toBe(false)
    })

    it('revenu après la fin, se dit terminé sans sonner, et ne garde ni minuterie ni verrou', async () => {
        const wrapper = await ouvrirLeTabata()
        await dansUnGeste(() => bouton(wrapper, 'Démarrer').trigger('click'))
        await avancer(wrapper, 2_000)

        // 10 + 8 × 20 + 7 × 10 = 240 s de séance.
        await passerEnArrierePlan(wrapper, 10 * 60_000)

        expect(affichage(wrapper)).toEqual({ phase: 'TERMINÉ', horloge: '00:00', tour: '8' })
        expect(notesAudibles()).toEqual([])
        expect(vi.getTimerCount()).toBe(0)
        expect(verrousActifs()).toEqual([])
        expect(bouton(wrapper, 'Démarrer')).toBeDefined()
    })

    it('quitté en cours de décompte, rend la minuterie, le verrou et la sortie', async () => {
        const wrapper = await ouvrirLeTabata()
        await dansUnGeste(() => bouton(wrapper, 'Démarrer').trigger('click'))
        await flushPromises()
        await avancer(wrapper, 3_000)

        wrapper.unmount()
        montees = []

        expect(vi.getTimerCount()).toBe(0)
        expect(verrous).toHaveLength(1)
        expect(verrous[0]).toMatchObject({ released: true, relachePar: 'page' })
        expect(sorties[0].state).toBe('closed')
    })
})
