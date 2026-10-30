import { describe, it, expect, vi, afterEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { reactive } from 'vue'

import { titresExposes } from '../conventions/exposition'
import { suivreLesPagesMontees } from './pagesMontees'

/**
 * Une séance dont on a vidé le nom garde un titre de niveau 1, sur téléphone
 * comme sur bureau (#2120).
 *
 * Le champ « Nom » des réglages n'est pas requis : `UpdateWorkoutRequest`
 * accepte `'name' => 'nullable'`, `ConvertEmptyStringsToNull` change le
 * champ vidé en `null`, et la colonne l'admet. La page passait ce nom tel quel
 * au layout (`:page-title="localWorkout.name"`), dont les deux `<h1>`, celui
 * de l'en-tête mobile et celui que le layout pose pour les lecteurs d'écran
 * sur bureau, tiennent à `pageTitle` : la séance n'exposait alors aucun titre,
 * à aucune largeur. Le `<Head>` de la page avait déjà son repli, « Séance ».
 *
 * La vraie page dans le vrai layout, montés superficiellement : les `<h1>` du
 * layout sont des éléments, rendus pour de vrai ; ses composants et ceux de la
 * page sont remplacés par des bouchons.
 */
const page = vi.hoisted(() => ({
    props: {
        auth: { user: { id: 1, name: 'Samuel', unread_notifications_count: 0, default_rest_time: 120 } },
        flash: {},
    },
    url: '/workouts/1',
    component: 'Workouts/Show',
}))

vi.mock('@/Utils/SyncService', () => ({
    default: {
        post: vi.fn(),
        patch: vi.fn(),
        delete: vi.fn(),
        get: vi.fn(),
        identifiantsEnAttente: () => [],
        entreesDuCompte: () => [],
        rejeuxDepuisLaDerniereVisite: 0,
        failedRequests: () => [],
        clearFailedRequests: vi.fn(),
        mettreEnFile: vi.fn(),
        modifierEnFile: vi.fn(),
        retirerDeLaFile: vi.fn(),
        processQueue: () => Promise.resolve(),
        attendreLesEcritures: () => Promise.resolve(),
        enAttente: () => 0,
        ecrituresEnCours: () => 0,
    },
}))
vi.mock('@formkit/drag-and-drop/vue', () => ({ dragAndDrop: () => {} }))
vi.mock('@/composables/useAbonnementPush', () => ({ rapprocherLAbonnementPush: () => {} }))
vi.mock('@/composables/useDeconnexion', () => ({ seDeconnecter: () => {} }))
vi.mock('@/Utils/http', () => ({ http: () => Promise.reject({ code: 'ERR_NETWORK', request: {} }) }))
vi.mock('@inertiajs/vue3', async () => {
    const { reactive: reactif } = await vi.importActual('vue')

    return {
        Head: { template: '<div />' },
        Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
        usePage: () => page,
        router: { visit: vi.fn(), reload: vi.fn(), delete: vi.fn(), post: vi.fn(), patch: vi.fn(), on: () => () => {} },
        useForm: (donnees) =>
            reactif({
                ...donnees,
                errors: {},
                processing: false,
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

/** Ziggy, réduit à ce que la page et le layout lui demandent : une adresse, ou `route().current(…)`. */
const route = (nom) => (nom === undefined ? { current: () => false } : `/${nom}`)
globalThis.route = route

const AuthenticatedLayout = (await import('@/Layouts/AuthenticatedLayout.vue')).default
const WorkoutShow = (await import('@/Pages/Workouts/Show.vue')).default

const pages = suivreLesPagesMontees()

afterEach(async () => {
    await pages.demonterToutes()
    localStorage.clear()
})

const monter = async (nom) => {
    const wrapper = mount(WorkoutShow, {
        props: {
            workout: {
                id: 1,
                name: nom,
                started_at: '2026-07-29T08:00:00.000000Z',
                ended_at: null,
                notes: '',
                workout_lines: [],
            },
            exercises: [],
        },
        shallow: true,
        global: {
            directives: { press: {} },
            mocks: { route, $page: reactive(page) },
            stubs: { AuthenticatedLayout },
        },
    })
    pages.retenir(wrapper)
    await flushPromises()

    return wrapper
}

describe('Workouts/Show — le titre d’une séance sans nom (#2120)', () => {
    it('expose « Séance » comme seul titre, sur téléphone comme sur bureau', async () => {
        const wrapper = await monter(null)

        expect(titresExposes(wrapper.element)).toEqual({ telephone: ['Séance'], bureau: ['Séance'] })
    })

    it('garde le nom de la séance quand elle en a un', async () => {
        const wrapper = await monter('Jambes')

        expect(titresExposes(wrapper.element)).toEqual({ telephone: ['Jambes'], bureau: ['Jambes'] })
    })
})
