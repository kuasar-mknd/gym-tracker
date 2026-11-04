import { describe, it, expect, vi, beforeAll, beforeEach, afterEach } from 'vitest'
import { mount } from '@vue/test-utils'

import { dansUnGeste, installerLaSortieWebKit, retirerLaSortieWebKit, sorties } from '../utils/sortieAudioWebKit'

/**
 * Les bips du minuteur d'intervalles s'entendent sur un iPhone, une
 * interruption d'iOS comprise (#2156), sur la vraie page et le vrai composable.
 *
 * Le minuteur gardait sa propre sortie, antérieure à `useSortieAudio` (#2153) :
 * il ne reprenait qu'une sortie `suspended`, jamais `interrupted` (l'état que
 * WebKit donne à une sortie qu'un appel ou Siri ont prise), ne remplaçait pas
 * une sortie `closed`, programmait chaque bip même sur une sortie arrêtée, qui
 * partait alors à contretemps au geste suivant, et ne démarrait pas quand le
 * navigateur refusait d'ouvrir la sortie. Il passe désormais par
 * `useSortieAudio` : « Démarrer », qui est aussi le geste de la reprise après
 * une pause, ouvre ou reprend la sortie, et chaque bip se joue sur elle.
 *
 * La sortie factice applique la règle de WebKit (`tests/js/utils/sortieAudioWebKit.js`) :
 * neuve, elle est suspendue jusqu'à un `resume()` dans un geste ; reprise hors
 * geste, elle le reste, et rien ne s'y entend.
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

/**
 * Quatre secondes de travail, deux de repos, deux tours, sans échauffement :
 * onze bips en dix secondes. Le décompte des trois dernières secondes du
 * travail, celui de la dernière du repos, une note plus haute à chaque
 * changement de phase, et la fin, sur le dernier changement.
 */
const PRESET = { id: 3, name: 'Court', work_seconds: 4, rest_seconds: 2, rounds: 2, warmup_seconds: 0 }
const NOTES = [440, 440, 440, 880, 440, 880, 440, 440, 440, 880, 1200]
const FINS = [0.1, 0.1, 0.1, 0.4, 0.1, 0.4, 0.1, 0.1, 0.1, 0.4, 0.6]

beforeAll(() => {
    globalThis.route = (name, param) => `/${name}/${param ?? ''}`
})

/** Chaque page montée par un cas, démontée après lui : une page laissée montée garde ses minuteries. */
let montees

beforeEach(() => {
    montees = []
    vi.useFakeTimers()
    installerLaSortieWebKit()
})

afterEach(() => {
    montees.forEach((wrapper) => wrapper.unmount())
    vi.restoreAllMocks()
    vi.useRealTimers()
    retirerLaSortieWebKit()
})

const bouton = (wrapper, nom) => wrapper.findAll('button').find((b) => b.attributes('aria-label') === nom)

/** La page, le préréglage chargé, le minuteur prêt et pas encore lancé. */
const ouvrirLePreset = async () => {
    const wrapper = mount(IntervalTimer, {
        props: { timers: [structuredClone(PRESET)] },
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

const demarrer = (wrapper) => dansUnGeste(() => bouton(wrapper, 'Démarrer').trigger('click'))
const mettreEnPause = (wrapper) => dansUnGeste(() => bouton(wrapper, 'Mettre en pause').trigger('click'))
const reinitialiser = (wrapper) => dansUnGeste(() => bouton(wrapper, 'Réinitialiser').trigger('click'))

/** Fait passer `ms` millisecondes de minuterie, puis laisse la page se redessiner. */
const avancer = async (wrapper, ms) => {
    vi.advanceTimersByTime(ms)
    await wrapper.vm.$nextTick()
}

/** Chaque son démarré sur une sortie, dans l'ordre. */
const tousLesSons = () => sorties.flatMap((sortie) => sortie.sons)

const notesAudibles = () =>
    tousLesSons()
        .filter((son) => son.audible)
        .map((son) => son.frequence)

describe('les bips du minuteur d’intervalles sur un iPhone (#2156)', () => {
    it('« Démarrer » ouvre la sortie et la reprend dans son geste, et chaque bip du préréglage s’entend, à sa note et à sa durée', async () => {
        const wrapper = await ouvrirLePreset()
        expect(sorties).toHaveLength(0)

        await demarrer(wrapper)

        expect(sorties).toHaveLength(1)
        expect(sorties[0].creeeDansUnGeste).toBe(true)
        expect(sorties[0].reprises).toEqual([{ dansUnGeste: true, etat: 'suspended' }])
        expect(sorties[0].state).toBe('running')

        await avancer(wrapper, 10_000)

        expect(wrapper.text()).toContain('TERMINÉ')
        expect(sorties).toHaveLength(1)
        expect(sorties[0].sons.map((son) => son.frequence)).toEqual(NOTES)
        expect(sorties[0].sons.map((son) => son.fin)).toEqual(FINS)
        expect(sorties[0].sons.every((son) => son.audible && !son.aContretemps)).toBe(true)
    })

    it('après une interruption d’iOS, « Mettre en pause » puis « Démarrer » reprennent la sortie dans le geste, et les bips suivants s’entendent', async () => {
        const wrapper = await ouvrirLePreset()
        await demarrer(wrapper)
        await avancer(wrapper, 1_000)
        expect(notesAudibles()).toEqual([440])

        // Un appel, Siri : iOS prend la sortie. Le bip demandé pendant l'interruption ne s'entend pas.
        sorties[0].interrompre()
        await avancer(wrapper, 1_000)

        await mettreEnPause(wrapper)
        await avancer(wrapper, 5_000)
        await demarrer(wrapper)

        expect(sorties).toHaveLength(1)
        expect(sorties[0].reprises.at(-1)).toEqual({ dansUnGeste: true, etat: 'interrupted' })
        expect(sorties[0].state).toBe('running')

        await avancer(wrapper, 8_000)

        expect(wrapper.text()).toContain('TERMINÉ')
        // Celui de l'interruption est abandonné ; les neuf qui le suivent s'entendent, aucun à contretemps.
        expect(notesAudibles()).toEqual([NOTES[0], ...NOTES.slice(2)])
        expect(tousLesSons().some((son) => son.aContretemps)).toBe(false)
    })

    it('après une interruption d’iOS, « Réinitialiser » puis « Démarrer » reprennent la même sortie, et le préréglage repart en entier', async () => {
        const wrapper = await ouvrirLePreset()
        await demarrer(wrapper)
        await avancer(wrapper, 1_000)
        sorties[0].interrompre()

        await reinitialiser(wrapper)
        await demarrer(wrapper)

        expect(sorties).toHaveLength(1)
        expect(sorties[0].reprises.at(-1)).toEqual({ dansUnGeste: true, etat: 'interrupted' })

        await avancer(wrapper, 10_000)

        expect(notesAudibles()).toEqual([440, ...NOTES])
        expect(tousLesSons().some((son) => son.aContretemps)).toBe(false)
    })

    it('ne fait partir aucun bip à contretemps quand la sortie s’arrête pendant le décompte', async () => {
        const wrapper = await ouvrirLePreset()
        await demarrer(wrapper)
        await avancer(wrapper, 1_000)

        // Le navigateur arrête la sortie : les trois bips suivants (440, 440, puis 880) sont demandés sur elle.
        sorties[0].suspendre()
        await avancer(wrapper, 3_000)

        await mettreEnPause(wrapper)
        await avancer(wrapper, 2_000)
        await demarrer(wrapper)
        await avancer(wrapper, 6_000)

        expect(wrapper.text()).toContain('TERMINÉ')
        expect(tousLesSons().some((son) => son.aContretemps)).toBe(false)
        expect(notesAudibles()).toEqual([NOTES[0], ...NOTES.slice(4)])
    })

    it('recrée à « Démarrer » une sortie fermée, et y sonne', async () => {
        const wrapper = await ouvrirLePreset()
        await demarrer(wrapper)
        await avancer(wrapper, 1_000)
        sorties[0].close()

        await mettreEnPause(wrapper)
        await demarrer(wrapper)

        expect(sorties).toHaveLength(2)
        expect(sorties[1].creeeDansUnGeste).toBe(true)
        expect(sorties[1].state).toBe('running')

        await avancer(wrapper, 9_000)

        expect(sorties[0].sons).toHaveLength(1)
        expect(sorties[1].sons.map((son) => son.frequence)).toEqual(NOTES.slice(1))
        expect(sorties[1].sons.every((son) => son.audible)).toBe(true)
    })

    it('démarre quand même quand le navigateur refuse d’ouvrir la sortie, et va à son terme sans son ni erreur remontée', async () => {
        const avertissement = vi.spyOn(console, 'warn').mockImplementation(() => {})
        const erreur = vi.spyOn(console, 'error')
        window.AudioContext = class SortieRefusee {
            constructor() {
                throw new DOMException('Aucune sortie audio.', 'NotSupportedError')
            }
        }

        const wrapper = await ouvrirLePreset()
        await demarrer(wrapper)

        expect(wrapper.text()).toContain('TRAVAIL')
        expect(bouton(wrapper, 'Mettre en pause')).toBeDefined()

        await avancer(wrapper, 10_000)

        expect(wrapper.text()).toContain('TERMINÉ')
        expect(avertissement).toHaveBeenCalledWith('La sortie audio n’a pas pu s’ouvrir.', expect.any(DOMException))
        expect(erreur).not.toHaveBeenCalled()
    })

    it('rend la sortie quand la page part, une seule fois, sans laisser de minuterie', async () => {
        const wrapper = await ouvrirLePreset()
        await demarrer(wrapper)
        await avancer(wrapper, 2_000)

        wrapper.unmount()
        montees = []

        expect(sorties).toHaveLength(1)
        expect(sorties[0].fermetures).toBe(1)
        expect(sorties[0].state).toBe('closed')
        expect(vi.getTimerCount()).toBe(0)
    })
})
