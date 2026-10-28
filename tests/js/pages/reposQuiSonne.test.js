import { describe, it, expect, vi, beforeAll, beforeEach, afterEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'

import {
    dansUnGeste,
    installerLaSortieWebKit,
    retirerLaSortieWebKit,
    sonsAudibles,
    sorties,
} from '../utils/sortieAudioWebKit'

/**
 * La fin du repos sonne sur un iPhone (#2112), sur la vraie page de séance et
 * le vrai minuteur.
 *
 * La fin naturelle d'un repos arrive dans le rappel d'une minuterie, hors de
 * tout geste ; WebKit n'y démarre aucune sortie audio. Le minuteur créait
 * pourtant la sienne à ce moment-là, une par repos, jamais reprise ni fermée :
 * sur iPhone, rien ne s'entendait, et chaque repos laissait une sortie
 * ouverte. La page tient désormais une seule sortie, ouverte ou reprise dans
 * le geste qui lance le repos (une série validée, « Démarrer un repos »), et
 * dans ceux du minuteur (la reprise après une pause, « Passer ») ; le bip de
 * fin s'y joue, et la page la rend en partant.
 *
 * La sortie factice applique la règle de WebKit (`tests/js/utils/sortieAudioWebKit.js`) :
 * créée ou reprise hors geste, elle reste suspendue, et rien ne s'y entend.
 */

const patch = vi.fn()

const pageStub = vi.hoisted(() => ({
    props: {
        auth: { user: { id: 1, default_rest_time: 120, auto_rest_timer: true } },
        flash: undefined,
    },
}))

vi.mock('@/Utils/SyncService', () => ({
    default: {
        post: vi.fn(() => Promise.resolve({ data: { data: { id: 999 } } })),
        patch: (...args) => patch(...args),
        delete: vi.fn(() => Promise.resolve({ data: {} })),
        get: vi.fn(),
        identifiantsEnAttente: () => [],
        entreesDuCompte: () => [],
        rejeuxDepuisLaDerniereVisite: 0,
        failedRequests: () => [],
        clearFailedRequests: vi.fn(),
        mettreEnFile: vi.fn(() => 'q-serie'),
        modifierEnFile: vi.fn(() => false),
        retirerDeLaFile: vi.fn(),
        processQueue: () => Promise.resolve(),
        attendreLesEcritures: () => Promise.resolve(),
        enAttente: () => 0,
        ecrituresEnCours: () => 0,
    },
}))

vi.mock('@formkit/drag-and-drop/vue', () => ({ dragAndDrop: () => {} }))

const haptique = vi.hoisted(() => ({ triggerHaptic: vi.fn() }))
vi.mock('@/composables/useHaptics', () => ({ triggerHaptic: (...args) => haptique.triggerHaptic(...args) }))

vi.mock('@inertiajs/vue3', async () => {
    const { reactive } = await vi.importActual('vue')

    return {
        Head: { template: '<div />' },
        Link: { template: '<a><slot /></a>' },
        usePage: () => pageStub,
        router: { visit: vi.fn(), reload: vi.fn(), delete: vi.fn(), post: vi.fn(), patch: vi.fn() },
        useForm: (data) =>
            reactive({
                ...data,
                processing: false,
                errors: {},
                transform() {
                    return this
                },
                patch: vi.fn(),
                post: vi.fn(),
                reset: vi.fn(),
                clearErrors: vi.fn(),
                setError: vi.fn(),
            }),
    }
})

import WorkoutShow from '@/Pages/Workouts/Show.vue'
import RestTimer from '@/Components/Workout/RestTimer.vue'
import RangeeDeSerie from '@/Components/Workout/RangeeDeSerie.vue'
import CarteDExercice from '@/Components/Workout/CarteDExercice.vue'
import ActionsDeLaSeance from '@/Components/Workout/ActionsDeLaSeance.vue'
import { noterQueLeServeurNeRepondPas, noterQueLeServeurRepond } from '@/composables/useEtatDuReseau'
import { suivreLesPagesMontees } from './pagesMontees'

/** Un exercice au repos de 90 s ; le compte, lui, se repose 120 s. */
const DEVELOPPE = { id: 5, name: 'Développé couché', type: 'strength', category: 'Pectoraux', default_rest_time: 90 }

const seance = () => ({
    id: 1,
    name: 'Séance',
    started_at: '2026-07-29T08:00:00.000000Z',
    ended_at: null,
    notes: '',
    workout_lines: [
        {
            id: 10,
            order: 0,
            exercise: DEVELOPPE,
            sets: [1, 2, 3, 4].map((n) => ({ id: 40 + n, weight: 80, reps: 5, is_completed: false })),
        },
    ],
})

const passeSonCreneau = { template: '<div><slot /></div>' }
const Bouton = { name: 'Bouton', template: '<button><slot /></button>' }

const pages = suivreLesPagesMontees()

const monterLaSeance = async () => {
    const page = mount(WorkoutShow, {
        props: { workout: seance(), exercises: [DEVELOPPE] },
        shallow: true,
        global: {
            directives: { press: {} },
            stubs: {
                AuthenticatedLayout: { template: '<div><slot name="header-actions" /><slot /></div>' },
                GlassCard: passeSonCreneau,
                SwipeableRow: { template: '<div><slot name="action-right" /><slot /></div>' },
                CarteDExercice,
                RangeeDeSerie,
                ActionsDeLaSeance,
                GlassButton: Bouton,
                RestTimer,
            },
        },
    })
    pages.retenir(page)
    await flushPromises()

    return page
}

/** Un toucher de l'élément marqué `dusk`, sous l'activation de la page. */
const toucher = async (page, dusk) => {
    await dansUnGeste(() => page.get(`[dusk="${dusk}"]`).trigger('click'))
    await flushPromises()
}

/** Le bouton du minuteur qui le met en pause ou le relance. */
const boutonDuMinuteur = (page, libelle) => page.get(`[dusk="rest-timer"] button[aria-label="${libelle}"]`)

/** Le temps passe ; la fin du repos tombe dans le rappel de la minuterie. */
const attendre = async (secondes) => {
    vi.advanceTimersByTime(secondes * 1000)
    await flushPromises()
}

const minuteurAffiche = (page) => page.findComponent(RestTimer).exists()

beforeAll(() => {
    globalThis.route = (nom, parametres) => (parametres ? `/${nom}/${Object.values(parametres).join('/')}` : `/${nom}`)
})

beforeEach(() => {
    localStorage.clear()
    // La minuterie du repos et l'heure seulement : `flushPromises` garde les siennes.
    vi.useFakeTimers({ toFake: ['setInterval', 'clearInterval', 'Date'] })
    installerLaSortieWebKit()
    patch.mockReset()
    patch.mockResolvedValue({ data: {} })
    haptique.triggerHaptic.mockReset()
    pageStub.props.flash = undefined
})

afterEach(async () => {
    await pages.demonterToutes()
    delete pageStub.props.horsLigne
    noterQueLeServeurRepond()
    retirerLaSortieWebKit()
    vi.useRealTimers()
    localStorage.clear()
})

describe('la fin du repos sur la page de séance (#2112)', () => {
    it.each([
        ['AudioContext', {}],
        ['webkitAudioContext seul', { prefixee: true }],
    ])(
        'une série validée lance un repos dont la fin naturelle sonne, sur la sortie ouverte par ce geste (%s)',
        async (_nom, installation) => {
            installerLaSortieWebKit(installation)
            const page = await monterLaSeance()

            await toucher(page, 'complete-set-0-0')

            expect(sorties, 'la sortie s’ouvre dans le geste qui lance le repos').toHaveLength(1)
            expect(sorties[0].creeeDansUnGeste).toBe(true)
            expect(sorties[0].state).toBe('running')
            expect(minuteurAffiche(page)).toBe(true)

            await attendre(90)

            expect(minuteurAffiche(page)).toBe(false)
            expect(sorties).toHaveLength(1)
            expect(sorties[0].sonsAudibles).toHaveLength(1)
            expect(sorties[0].sonsAudibles[0]).toMatchObject({ frequence: 880, fin: 0.5 })
            // Le retour haptique reste tel quel, et ne remplace pas le son : Safari iOS n'a pas `navigator.vibrate`.
            expect(haptique.triggerHaptic).toHaveBeenCalledWith('timer')
        },
    )

    it('quatre repos, une seule sortie, qui reste ouverte de l’un à l’autre', async () => {
        const page = await monterLaSeance()
        const entreDeuxRepos = []

        for (const index of [0, 1, 2, 3]) {
            await toucher(page, `complete-set-0-${index}`)
            await attendre(90)
            expect(minuteurAffiche(page)).toBe(false)
            entreDeuxRepos.push(sorties.map((sortie) => sortie.state))
        }

        // Le bilan d'un coup : sur l'ancien code, il dit ce que l'issue mesurait.
        expect({
            sorties: sorties.length,
            arretees: sorties.filter((sortie) => sortie.state !== 'running').length,
            reprises: sorties.reduce((total, sortie) => total + sortie.reprises.length, 0),
            fermetures: sorties.reduce((total, sortie) => total + sortie.fermetures, 0),
            sonsAudibles: sonsAudibles().length,
        }).toEqual({ sorties: 1, arretees: 0, reprises: 0, fermetures: 0, sonsAudibles: 4 })
        expect(entreDeuxRepos).toEqual([['running'], ['running'], ['running'], ['running']])
    })

    it('« Démarrer un repos » ouvre la sortie dans son geste, et la fin sonne', async () => {
        const page = await monterLaSeance()

        await toucher(page, 'open-rest-timer')
        expect(sorties).toHaveLength(1)
        expect(sorties[0].state).toBe('running')

        // Le repos du compte : le geste n'est attaché à aucune série.
        await attendre(119)
        expect(sonsAudibles()).toHaveLength(0)
        await attendre(1)

        expect(sonsAudibles()).toHaveLength(1)
    })

    it.each([
        [
            'sans réseau',
            () => {
                noterQueLeServeurNeRepondPas()
                patch.mockRejectedValue({ isOffline: true, message: 'Mise en file' })
            },
        ],
        [
            'sur une copie hors ligne',
            () => {
                pageStub.props.horsLigne = { copieDu: Date.now(), raison: 'reseau', datesDesProps: {} }
            },
        ],
    ])('la validation lance aussi %s un repos qui sonne', async (_cas, preparerLeCas) => {
        preparerLeCas()
        const page = await monterLaSeance()

        await toucher(page, 'complete-set-0-0')
        await attendre(90)

        expect(sorties).toHaveLength(1)
        expect(sonsAudibles()).toHaveLength(1)
    })

    it.each(['interrupted', 'suspended'])(
        'une sortie %s entre deux repos reprend dans le geste qui lance le suivant',
        async (etat) => {
            const page = await monterLaSeance()

            await toucher(page, 'complete-set-0-0')
            await attendre(90)
            // Un appel ou Siri a pris le son ; ou le navigateur a suspendu la sortie.
            sorties[0].state = etat

            await toucher(page, 'complete-set-0-1')

            expect(sorties).toHaveLength(1)
            expect(sorties[0].reprises.at(-1)).toEqual({ dansUnGeste: true, etat })
            expect(sorties[0].state).toBe('running')

            await attendre(90)

            expect(sonsAudibles()).toHaveLength(2)
        },
    )

    it('une sortie fermée entre deux repos se recrée dans le geste qui lance le suivant', async () => {
        const page = await monterLaSeance()

        await toucher(page, 'complete-set-0-0')
        await attendre(90)
        sorties[0].close()

        await toucher(page, 'complete-set-0-1')
        await attendre(90)

        expect(sorties).toHaveLength(2)
        expect(sorties[1].creeeDansUnGeste).toBe(true)
        expect(sorties[1].sonsAudibles).toHaveLength(1)
    })

    it('la reprise après une pause rend, dans son geste, la sortie qu’iOS avait interrompue', async () => {
        const page = await monterLaSeance()

        await toucher(page, 'complete-set-0-0')
        await attendre(30)
        await dansUnGeste(() => boutonDuMinuteur(page, 'Pause').trigger('click'))
        sorties[0].interrompre()

        await dansUnGeste(() => boutonDuMinuteur(page, 'Démarrer le minuteur').trigger('click'))

        expect(sorties[0].reprises.at(-1)).toEqual({ dansUnGeste: true, etat: 'interrupted' })
        expect(sorties[0].state).toBe('running')

        await attendre(60)

        expect(minuteurAffiche(page)).toBe(false)
        expect(sorties).toHaveLength(1)
        expect(sonsAudibles()).toHaveLength(1)
    })

    it('« Passer » sonne sur la sortie de la page, qu’il reprend dans son geste, sans en ouvrir une autre', async () => {
        const page = await monterLaSeance()

        await toucher(page, 'complete-set-0-0')
        await attendre(10)
        sorties[0].interrompre()

        await toucher(page, 'skip-rest-timer')

        expect(minuteurAffiche(page)).toBe(false)
        expect(sorties).toHaveLength(1)
        expect(sorties[0].reprises.at(-1)).toEqual({ dansUnGeste: true, etat: 'interrupted' })
        expect(sonsAudibles()).toHaveLength(1)
    })

    it('la page rend sa sortie en partant, même pendant un repos, et plus rien ne sonne', async () => {
        const page = await monterLaSeance()

        await toucher(page, 'complete-set-0-0')
        await attendre(30)
        page.unmount()

        expect(sorties).toHaveLength(1)
        expect(sorties[0].fermetures).toBe(1)
        expect(sorties[0].state).toBe('closed')

        await attendre(120)

        expect(sorties).toHaveLength(1)
        expect(sonsAudibles()).toHaveLength(0)
    })
})
