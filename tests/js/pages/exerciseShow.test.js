import { describe, it, expect, vi, beforeAll } from 'vitest'

vi.mock('@/Components/Stats/AverageWeightChart.vue', () => ({
    __esModule: true,
    default: { name: 'AverageWeightChart', props: ['data'], template: '<div />' },
}))
vi.mock('@/Components/Stats/Estimated1RMHistoryChart.vue', () => ({
    __esModule: true,
    default: { name: 'Estimated1RMHistoryChart', props: ['data'], template: '<div />' },
}))
vi.mock('@/Components/Stats/HistoryChart.vue', () => ({
    __esModule: true,
    default: { name: 'HistoryChart', props: ['data'], template: '<div />' },
}))
vi.mock('@/Components/Stats/MaxRepsChart.vue', () => ({
    __esModule: true,
    default: { name: 'MaxRepsChart', props: ['data'], template: '<div />' },
}))
vi.mock('@/Components/Stats/MaxWeightChart.vue', () => ({
    __esModule: true,
    default: { name: 'MaxWeightChart', props: ['data'], template: '<div />' },
}))
vi.mock('@/Components/Stats/OneRepMaxChart.vue', () => ({
    __esModule: true,
    default: { name: 'OneRepMaxChart', props: ['data'], template: '<div />' },
}))
vi.mock('@/Components/Stats/SessionPerformanceChart.vue', () => ({
    __esModule: true,
    default: { name: 'SessionPerformanceChart', props: ['data'], template: '<div />' },
}))
vi.mock('@/Components/Stats/SessionVolumeLineChart.vue', () => ({
    __esModule: true,
    default: { name: 'SessionVolumeLineChart', props: ['data'], template: '<div />' },
}))
vi.mock('@/Components/Stats/SetWeightProgressionChart.vue', () => ({
    __esModule: true,
    default: { name: 'SetWeightProgressionChart', props: ['data'], template: '<div />' },
}))
vi.mock('@/Components/Stats/SetsPerSessionChart.vue', () => ({
    __esModule: true,
    default: { name: 'SetsPerSessionChart', props: ['data'], template: '<div />' },
}))
vi.mock('@/Components/Stats/TotalRepsChart.vue', () => ({
    __esModule: true,
    default: { name: 'TotalRepsChart', props: ['data'], template: '<div />' },
}))
vi.mock('@/Components/Stats/VolumeTrendChart.vue', () => ({
    __esModule: true,
    default: { name: 'VolumeTrendChart', props: ['data'], template: '<div />' },
}))
vi.mock('@/Components/Stats/WeightDistributionChart.vue', () => ({
    __esModule: true,
    default: { name: 'WeightDistributionChart', props: ['data'], template: '<div />' },
}))
vi.mock('@/Components/Stats/WeightRepsScatterChart.vue', () => ({
    __esModule: true,
    default: { name: 'WeightRepsScatterChart', props: ['data'], template: '<div />' },
}))
import { mount, flushPromises } from '@vue/test-utils'

vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<div />' },
    Link: { template: '<a><slot /></a>' },
}))

/*
 * The fourteen charts are not loaded at all here.
 *
 * The page pulls them in through defineAsyncComponent, and those import chains
 * keep resolving after the test that started them has returned. Whatever they
 * log then lands once the worker is already closing, and Vitest ends the run on
 * `EnvironmentTeardownError: Closing rpc while "onUserConsoleLog" was pending` —
 * every test green, exit code 1, and the file it blames moves around as the
 * suite grows. Measured at 2 runs in 8 before this.
 *
 * Awaiting the imports inside the test was the previous answer, and it only
 * raced them: flushPromises drains the microtask queue, while a dynamic import
 * goes through Vite's transform and can take longer than that. Stubbing the
 * loader removes the race instead of trying to win it, and costs nothing —
 * every assertion below reads the series off the component, never a chart.
 */
vi.mock('vue', async (importOriginal) => ({
    ...(await importOriginal()),
    defineAsyncComponent: () => ({ name: 'UnloadedChart', render: () => null }),
}))

import ExerciseShow from '@/Pages/Exercises/Show.vue'
import { passesSlot } from './pageStubs'

beforeAll(() => {
    globalThis.route = (name, params) => `/${name}/${JSON.stringify(params ?? '')}`
})

/**
 * Une séance telle que le serveur l'envoie. Les séries sont validées sauf
 * mention contraire : c'est ce que « une série » veut dire quand on n'en
 * précise pas davantage, comme la fabrique côté serveur.
 */
const session = (date, sets, best1rm = 0) => ({
    formatted_date: date,
    sets: sets.map((set) => ({ is_completed: true, is_warmup: false, ...set })),
    best_1rm: best1rm,
})

/**
 * Every chart on this page reads the same `history` prop, which the server
 * sends newest first, and turns it into a series. Two things are load-bearing
 * across all of them: the reversal, because a progression drawn backwards tells
 * the opposite story; and the guards, because a session with no sets or a set
 * with no weight is ordinary data here, not a corner case.
 */
const mountPage = async (history = []) => {
    const wrapper = mount(ExerciseShow, {
        props: {
            exercise: { id: 1, name: 'Développé Couché', type: 'strength', category: 'Pectoraux' },
            progress: [],
            history,
        },
        global: {
            mocks: { route: globalThis.route },
            stubs: { AuthenticatedLayout: passesSlot, GlassCard: passesSlot },
        },
    })

    await flushPromises()

    return wrapper
}

describe('the series feeding the charts', () => {
    it('reads oldest first, whichever way the server sent it', async () => {
        const wrapper = await mountPage([
            session('03/02/2026', [{ weight: 100, reps: 5 }]),
            session('01/02/2026', [{ weight: 80, reps: 5 }]),
        ])

        // The server sends newest first. Drawn in that order a progression
        // reads as a decline — the one mistake nobody would notice from the
        // shape of the curve alone.
        expect(wrapper.vm.volumeData.map((point) => point.volume)).toEqual([400, 500])
    })

    it('drops the year from the axis labels', async () => {
        const wrapper = await mountPage([session('03/02/2026', [{ weight: 100, reps: 5 }])])

        expect(wrapper.vm.volumeData[0].date).toBe('03/02')
    })

    it('counts a set with no weight as nothing rather than as NaN', async () => {
        const wrapper = await mountPage([
            session('01/02/2026', [
                // No `weight` key at all, which is what the API sends for a
                // bodyweight set. `null` would coerce to 0 on its own; only a
                // missing key reaches the fallback, and without it the
                // multiplication yields NaN and takes the whole session's
                // volume — and its point on the chart — with it.
                { reps: 12 },
                { weight: 60, reps: 10 },
            ]),
        ])

        expect(wrapper.vm.volumeData[0].volume).toBe(600)
    })

    it('reports no point, neither minus infinity nor zero, for a session with no sets', async () => {
        const wrapper = await mountPage([session('01/02/2026', [], null)])

        // Math.max of nothing is -Infinity, which drags every axis on the page
        // down with it. Zero is no better: it reads as a session where the bar
        // came down to nothing. null is the point Chart.js does not draw.
        expect(wrapper.vm.maxRepsData[0].reps).toBeNull()
        expect(wrapper.vm.maxWeightData[0].weight).toBeNull()
        expect(wrapper.vm.averageWeightData[0].weight).toBeNull()
        expect(wrapper.vm.estimated1rmData[0].weight).toBeNull()
        expect(wrapper.vm.setsPerSessionData[0].sets).toBe(0)
    })

    it('leaves bodyweight sets out of the average load', async () => {
        const wrapper = await mountPage([
            session('01/02/2026', [
                { weight: 0, reps: 15 },
                { weight: 80, reps: 5 },
                { weight: 100, reps: 3 },
            ]),
        ])

        // Averaging the zeros in would report 60 kg for a session whose real
        // loaded average was 90.
        expect(parseFloat(wrapper.vm.averageWeightData[0].weight)).toBe(90)
    })

    it('adds up every rep of the session, not just the best set', async () => {
        const wrapper = await mountPage([
            session('01/02/2026', [
                { weight: 60, reps: 10 },
                { weight: 60, reps: 8 },
            ]),
        ])

        expect(wrapper.vm.totalRepsData[0].reps).toBe(18)
    })
})

describe('the weight distribution', () => {
    it('bins by five kilos and keeps the empty bins between', async () => {
        const wrapper = await mountPage([
            session('01/02/2026', [
                { weight: 60, reps: 5 },
                { weight: 62, reps: 5 },
                { weight: 75, reps: 5 },
            ]),
        ])

        // The gap matters: dropping the empty bins would draw 60 and 75 side by
        // side and hide the fact that nothing was ever lifted in between.
        expect(wrapper.vm.weightDistributionData).toEqual([
            { label: '60', count: 2 },
            { label: '65', count: 0 },
            { label: '70', count: 0 },
            { label: '75', count: 1 },
        ])
    })

    it('draws nothing when no set carries a weight', async () => {
        const wrapper = await mountPage([])

        expect(wrapper.vm.weightDistributionData).toEqual([])
    })
})

describe('the weight-against-reps scatter', () => {
    it('keeps only the sets that have both numbers', async () => {
        const wrapper = await mountPage([
            session('01/02/2026', [
                { weight: 100, reps: 5 },
                { weight: 0, reps: 15 },
                { weight: 80, reps: null },
            ]),
        ])

        // A point at x=0 or y=0 is not a lift; plotted it pulls the trend line
        // towards an origin no one trained at.
        expect(wrapper.vm.scatterData).toEqual([{ x: 100, y: 5 }])
    })
})

/*
 * Chaque série naît décochée, préremplie par le modèle ou par la valeur
 * proposée. Le volume de la séance et les records ne comptent que les séries
 * validées ; les graphiques de la fiche comptaient tout, et la page se
 * contredisait d'une carte à l'autre (#1956).
 */
describe('only the sets that were done', () => {
    /** 100 × 5 cochée, 140 × 5 jamais cochée : le volume de séance vaut 500, le record 100 kg. */
    const seanceAvecUneSeriePrevue = () =>
        session(
            '13/06/2026',
            [
                { weight: 100, reps: 5, one_rep_max: 116.67 },
                { weight: 140, reps: 5, one_rep_max: 163.33, is_completed: false },
            ],
            116.67,
        )

    it('gives the Volume card the session volume, not the planned one', async () => {
        const wrapper = await mountPage([seanceAvecUneSeriePrevue()])

        expect(wrapper.vm.volumeData[0].volume).toBe(500)
    })

    it('gives the Charge Max card the record, not the heavier set never ticked', async () => {
        const wrapper = await mountPage([seanceAvecUneSeriePrevue()])

        expect(wrapper.vm.maxWeightData[0].weight).toBe(100)
        expect(wrapper.vm.estimated1rmData[0].weight).toBe(116.67)
    })

    it('leaves the set never ticked out of every other card', async () => {
        const wrapper = await mountPage([
            session('13/06/2026', [
                { weight: 100, reps: 5 },
                { weight: 140, reps: 8, is_completed: false },
            ]),
        ])

        expect(wrapper.vm.maxRepsData[0].reps).toBe(5)
        expect(wrapper.vm.totalRepsData[0].reps).toBe(5)
        expect(wrapper.vm.setsPerSessionData[0].sets).toBe(1)
        expect(wrapper.vm.averageWeightData[0].weight).toBe(100)
        expect(wrapper.vm.weightDistributionData).toEqual([{ label: '100', count: 1 }])
        expect(wrapper.vm.scatterData).toEqual([{ x: 100, y: 5 }])
    })

    it('counts a ticked warm-up in the volume, as the session does, but not in the Charge Max', async () => {
        const wrapper = await mountPage([
            session('13/06/2026', [
                { weight: 100, reps: 5 },
                { weight: 120, reps: 2, is_warmup: true },
            ]),
        ])

        expect(wrapper.vm.volumeData[0].volume).toBe(740)
        expect(wrapper.vm.maxWeightData[0].weight).toBe(100)
    })

    it('draws no point at 0 kg for a session abandoned before its first set', async () => {
        const wrapper = await mountPage([
            session('15/06/2026', [{ weight: 100, reps: 5 }], 116.67),
            session('13/06/2026', [{ weight: 100, reps: 5, is_completed: false }], null),
            session('11/06/2026', [{ weight: 95, reps: 5 }], 110.83),
        ])

        expect(wrapper.vm.estimated1rmData.map((point) => point.weight)).toEqual([110.83, null, 116.67])
        expect(wrapper.vm.maxWeightData.map((point) => point.weight)).toEqual([95, null, 100])
        expect(wrapper.vm.maxRepsData.map((point) => point.reps)).toEqual([5, null, 5])
        expect(wrapper.vm.averageWeightData.map((point) => point.weight)).toEqual([95, null, 100])
        // Rien n'a été soulevé : un volume nul est la vérité, pas un trou.
        expect(wrapper.vm.volumeData.map((point) => point.volume)).toEqual([475, 0, 500])
    })
})
