import { describe, it, expect, vi, beforeAll, afterEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'

import { chartRecorders } from '../components/chartRecorder'

/*
 * Ici ni les graphiques ni leurs chargeurs ne sont simulés : la page monte les
 * vrais composants, seul vue-chartjs est remplacé par un enregistreur qui garde
 * ce qu'il aurait dessiné. Si la branche sur le type de l'exercice casse, la
 * carte attendue disparaît ou celle qu'on cache revient.
 */
vi.mock('vue-chartjs', () => chartRecorders())

/*
 * Chaque graphique est « moqué » pour la garde `graphiquesMoques`, mais par le
 * vrai module : `importOriginal` rend le composant tel quel.
 */
vi.mock('@/Components/Stats/OneRepMaxChart.vue', async (importOriginal) => await importOriginal())
vi.mock('@/Components/Stats/VolumeTrendChart.vue', async (importOriginal) => await importOriginal())
vi.mock('@/Components/Stats/MaxRepsChart.vue', async (importOriginal) => await importOriginal())
vi.mock('@/Components/Stats/MaxWeightChart.vue', async (importOriginal) => await importOriginal())
vi.mock('@/Components/Stats/WeightRepsScatterChart.vue', async (importOriginal) => await importOriginal())
vi.mock('@/Components/Stats/SetsPerSessionChart.vue', async (importOriginal) => await importOriginal())

const historique = vi.hoisted(() => ({ arrive: true }))

vi.mock('@inertiajs/vue3', async () => {
    const { h } = await import('vue')

    return {
        Head: { template: '<div />' },
        Link: { template: '<a><slot /></a>' },
        Deferred: {
            props: ['data'],
            setup:
                (props, { slots }) =>
                () =>
                    h('div', historique.arrive ? slots.default?.() : slots.fallback?.()),
        },
    }
})

import ExerciseShow from '@/Pages/Exercises/Show.vue'
import { passesSlot } from './pageStubs'

beforeAll(() => {
    globalThis.route = (name, params) => `/${name}/${JSON.stringify(params ?? '')}`
})

afterEach(() => {
    historique.arrive = true
})

/**
 * Une série telle que `FetchExerciseHistoryAction` l'envoie : poids et
 * répétitions toujours des nombres (0 quand la série n'en a pas, comme le
 * cardio et le temps), jamais de durée ni de distance.
 */
const serie = (weight, reps) => ({
    weight,
    reps,
    one_rep_max: 0,
    is_completed: true,
    is_warmup: false,
})

const seance = (date, series) => ({
    id: 1,
    workout_id: 1,
    workout_name: 'Séance',
    formatted_date: date,
    best_1rm: null,
    sets: series,
})

const SEANCES = {
    force: [serie(100, 5), serie(100, 6)],
    'poids du corps': [serie(0, 12), serie(0, 10)],
    cardio: [serie(0, 0), serie(0, 0), serie(0, 0)],
    temps: [serie(0, 0), serie(0, 0)],
}

const monter = async (type, series, progress = []) => {
    const wrapper = mount(ExerciseShow, {
        props: {
            exercise: { id: 1, name: 'Exercice', type, category: 'Pectoraux' },
            progress,
            history: [seance('03/02/2026', series)],
        },
        global: {
            mocks: { route: globalThis.route },
            stubs: { AuthenticatedLayout: passesSlot },
        },
    })

    await vi.dynamicImportSettled()
    await flushPromises()

    return wrapper
}

const titres = (wrapper) => wrapper.findAll('h3').map((titre) => titre.text())

const CINQ_CARTES = ['Progression 1RM', 'Volume', 'Endurance', 'Charge Max', 'Poids vs Reps']

describe('une fiche de force', () => {
    it('montre les cinq cartes, sans « Séries », dès que des séries portent une charge', async () => {
        const wrapper = await monter('strength', SEANCES.force, [
            { date: '03/02', full_date: '2026-02-03', one_rep_max: 120 },
        ])

        expect(titres(wrapper)).toEqual(CINQ_CARTES)
    })

    it('ne montre que « Endurance » pour le poids du corps, dont aucune série ne porte de charge', async () => {
        const wrapper = await monter('strength', SEANCES['poids du corps'])

        expect(titres(wrapper)).toEqual(['Endurance'])
        expect(wrapper.findComponent({ name: 'Line' }).props('data').datasets[0].data).toEqual([12])
        expect(wrapper.text()).not.toContain('Pas assez de données pour afficher le graphique')
    })

    it('rend les cinq cartes dès qu’une séance sur deux porte une charge', async () => {
        const wrapper = mount(ExerciseShow, {
            props: {
                exercise: { id: 1, name: 'Exercice', type: 'strength', category: 'Pectoraux' },
                progress: [],
                history: [seance('10/02/2026', [serie(20, 8)]), seance('03/02/2026', [serie(0, 12)])],
            },
            global: { mocks: { route: globalThis.route }, stubs: { AuthenticatedLayout: passesSlot } },
        })
        await vi.dynamicImportSettled()
        await flushPromises()

        expect(titres(wrapper)).toEqual(CINQ_CARTES)
    })

    it('ne compte ni un échauffement ni une série jamais cochée comme une charge', async () => {
        const wrapper = await monter('strength', [
            { ...serie(40, 10), is_warmup: true },
            { ...serie(60, 8), is_completed: false },
            serie(0, 12),
        ])

        expect(titres(wrapper)).toEqual(['Endurance'])
    })

    it('garde l’état vide de la progression pour un exercice sans séance', async () => {
        const wrapper = mount(ExerciseShow, {
            props: {
                exercise: { id: 1, name: 'Exercice', type: 'strength', category: 'Pectoraux' },
                progress: [],
                history: [],
            },
            global: { mocks: { route: globalThis.route }, stubs: { AuthenticatedLayout: passesSlot } },
        })
        await flushPromises()

        expect(titres(wrapper)).toEqual(['Progression 1RM'])
    })
})

describe.each([
    ['cardio', 'cardio', 3],
    ['temps', 'timed', 2],
])('une fiche de %s', (nom, type, series) => {
    it('ne montre que « Séries », avec le nombre de séries de la séance', async () => {
        const wrapper = await monter(type, SEANCES[nom])

        expect(titres(wrapper)).toEqual(['Séries'])
        expect(wrapper.findComponent({ name: 'Bar' }).props('data').datasets[0].data).toEqual([series])
        expect(wrapper.text()).not.toContain('Pas assez de données pour afficher le graphique')
    })

    it('réserve une seule carte au squelette de l’historique', async () => {
        historique.arrive = false
        const wrapper = await monter(type, SEANCES[nom])

        expect(wrapper.findAll('[data-historique-en-attente] .animate-pulse')).toHaveLength(1)
    })
})

it('réserve quatre cartes au squelette d’une force', async () => {
    historique.arrive = false
    const wrapper = await monter('strength', SEANCES.force)

    expect(wrapper.findAll('[data-historique-en-attente] .animate-pulse')).toHaveLength(4)
})
