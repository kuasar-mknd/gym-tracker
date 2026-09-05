import { describe, it, expect, vi } from 'vitest'
import { jeton, jetonTransparent } from '@/Utils/couleurs'
import { mount } from '@vue/test-utils'

/**
 * Same reasoning as chartAxes.test.js: vue-chartjs is replaced rather than
 * stubbed by name, because the real components reach for a canvas jsdom does
 * not draw. What these tests need is the `data` object handed to the chart —
 * the labels, the values, their pairing and their order. That is the series the
 * user actually sees, and it is where a regression hides.
 */
vi.mock('vue-chartjs', () => {
    const recorder = (name) => ({
        name,
        props: { data: { type: Object, default: null }, options: { type: Object, default: null } },
        template: '<div />',
    })

    return { Bar: recorder('Bar'), Line: recorder('Line'), Doughnut: recorder('Doughnut') }
})

const BodyPartDiffChart = (await import('@/Components/Stats/BodyPartDiffChart.vue')).default
const BodyFatLineChart = (await import('@/Components/Stats/BodyFatLineChart.vue')).default
const RecentWorkoutsExercisesChart = (await import('@/Components/Stats/RecentWorkoutsExercisesChart.vue')).default
const ExerciseCategoryChart = (await import('@/Components/Stats/ExerciseCategoryChart.vue')).default
const DurationHistoryChart = (await import('@/Components/Stats/DurationHistoryChart.vue')).default
const SetsPerSessionChart = (await import('@/Components/Stats/SetsPerSessionChart.vue')).default
const VolumePerWorkoutChart = (await import('@/Components/Stats/VolumePerWorkoutChart.vue')).default

const chartIn = (wrapper, name) => wrapper.findComponent({ name })
const seriesOf = (wrapper, name) => chartIn(wrapper, name).props('data')

/**
 * A stand-in for the Chart.js scriptable context. The colour callbacks build a
 * canvas gradient, so the fake records the stops that were added to it — that
 * is what decides whether a bar reads as a gain or a loss.
 *
 * @returns {{ctx: object, chartArea: object, stops: string[]}}
 */
const paintingContext = () => {
    const stops = []

    return {
        stops,
        ctx: {
            createLinearGradient: () => ({ addColorStop: (_offset, color) => stops.push(color) }),
        },
        chartArea: { top: 0, bottom: 100, left: 0, right: 100 },
    }
}

/**
 * Runs a scriptable colour callback and returns the gradient stops it laid down.
 *
 * @param {Function} callback
 * @param {number} raw
 * @returns {string[]}
 */
const stopsFor = (callback, raw) => {
    const chart = paintingContext()
    callback({ raw, chart })

    return chart.stops
}

describe('BodyPartDiffChart series', () => {
    const measurements = [
        { part: 'Poids', diff: 0, unit: 'kg' },
        { part: 'Poitrine', diff: 2, unit: 'cm' },
        { part: 'Tour de bras', diff: -1.5, unit: 'mm' },
    ]

    const mountChart = (data = measurements) => mount(BodyPartDiffChart, { props: { data } })

    it('leaves out the parts that did not move, and keeps label and value paired', () => {
        const series = seriesOf(mountChart(), 'Bar')

        expect(series.labels).toEqual(['Poitrine', 'Tour de bras'])
        expect(series.datasets[0].data).toEqual([2, -1.5])
    })

    it('shows an empty state instead of an axis with no bars', () => {
        const wrapper = mountChart([{ part: 'Poids', diff: 0, unit: 'kg' }])

        expect(chartIn(wrapper, 'Bar').exists()).toBe(false)
        expect(wrapper.text()).toContain('Aucun changement enregistré')
    })

    it('draws the chart, and drops the empty state, as soon as one part moved', () => {
        const wrapper = mountChart([{ part: 'Poitrine', diff: 0.5, unit: 'cm' }])

        expect(chartIn(wrapper, 'Bar').exists()).toBe(true)
        expect(wrapper.text()).not.toContain('Aucun changement enregistré')
    })

    it('paints a gain green and a loss red', () => {
        const paint = seriesOf(mountChart(), 'Bar').datasets[0].backgroundColor

        expect(stopsFor(paint, 2)).toEqual([jeton('trend-up'), jetonTransparent('trend-up', 0.55)])
        expect(stopsFor(paint, -1.5)).toEqual([jeton('trend-down'), jetonTransparent('trend-down', 0.55)])
    })
})

describe('BodyFatLineChart series', () => {
    const mountChart = (data) => mount(BodyFatLineChart, { props: { data } })

    it('plots each reading against its own date, in the order given', () => {
        const series = seriesOf(
            mountChart([
                { date: '01/07', body_fat: 18.4 },
                { date: '15/07', body_fat: 17.9 },
                { date: '29/07', body_fat: 17.2 },
            ]),
            'Line',
        )

        expect(series.labels).toEqual(['01/07', '15/07', '29/07'])
        expect(series.datasets[0].data).toEqual([18.4, 17.9, 17.2])
    })

    it('plots the single reading it was given rather than nothing', () => {
        const series = seriesOf(mountChart([{ date: '01/07', body_fat: 18.4 }]), 'Line')

        expect(series.labels).toEqual(['01/07'])
        expect(series.datasets[0].data).toEqual([18.4])
    })

    /**
     * Unlike BodyPartDiffChart this component has no empty state — it hands an
     * empty series to Chart.js. The point of the assertion is that it stays
     * empty: a phantom point at zero would read as 0 % body fat.
     */
    it('plots no points at all when there is no reading', () => {
        const series = seriesOf(mountChart([]), 'Line')

        expect(series.labels).toEqual([])
        expect(series.datasets[0].data).toEqual([])
    })

    it('asks for neither line nor fill colour before the plot area exists', () => {
        const dataset = seriesOf(mountChart([{ date: '01/07', body_fat: 18.4 }]), 'Line').datasets[0]
        const blankChart = { chart: { ctx: null, chartArea: null } }

        expect(dataset.borderColor(blankChart)).toBeNull()
        expect(dataset.backgroundColor(blankChart)).toBeNull()
    })
})

describe('RecentWorkoutsExercisesChart series', () => {
    /**
     * A factory, not a shared const: the defect the last test in this block
     * looks for is an in-place reverse of the prop, and a shared array would let
     * an earlier mount reverse it back and hide exactly that.
     *
     * The API hands the most recent session back first.
     */
    const newestFirst = () => [
        { started_at: '2026-07-09T18:00:00Z', workout_lines_count: 5 },
        { started_at: '2026-07-05T10:00:00Z', workout_lines_count: 3 },
        { started_at: '2026-07-01T09:00:00Z', workout_lines_count: 1 },
    ]

    const mountChart = (data = newestFirst()) => mount(RecentWorkoutsExercisesChart, { props: { data } })

    it('reads chronologically, oldest bar on the left, counts following their date', () => {
        const series = seriesOf(mountChart(), 'Bar')

        expect(series.labels).toEqual(['01/07', '05/07', '09/07'])
        expect(series.datasets[0].data).toEqual([1, 3, 5])
    })

    it('does not reverse the page prop it was handed', () => {
        const source = newestFirst()

        mountChart(source)

        // props.data is the very array Inertia keeps on the page, so a bare
        // props.data.reverse() reorders it for every other component reading it.
        expect(source.map((workout) => workout.workout_lines_count)).toEqual([5, 3, 1])
    })

    it('draws a session with no exercises as a zero, not as a gap', () => {
        const series = seriesOf(mountChart([{ started_at: '2026-07-05T10:00:00Z' }]), 'Bar')

        expect(series.datasets[0].data).toEqual([0])
    })
})

describe('ExerciseCategoryChart series', () => {
    const mountChart = (exercises) => mount(ExerciseCategoryChart, { props: { exercises } })

    const exercises = [
        { category: 'Pectoraux' },
        { category: 'Pectoraux' },
        { category: 'Dos' },
        { category: null },
        { category: 'Mobilité' },
    ]

    it('counts one slice per category, in the order the categories first appear', () => {
        const series = seriesOf(mountChart(exercises), 'Doughnut')

        expect(series.labels).toEqual(['Pectoraux', 'Dos', 'Autres', 'Mobilité'])
        expect(series.datasets[0].data).toEqual([2, 1, 1, 1])
    })

    it('files an exercise with no category under Autres rather than under an empty name', () => {
        const series = seriesOf(mountChart([{ category: null }, { category: undefined }]), 'Doughnut')

        expect(series.labels).toEqual(['Autres'])
        expect(series.datasets[0].data).toEqual([2])
    })

    it('gives every slice the colour of its own category', () => {
        const series = seriesOf(mountChart(exercises), 'Doughnut')

        // Fourth entry is 'Mobilité', a category with no colour of its own: it
        // keeps its name and borrows the neutral Autres grey.
        //
        // Le test nomme les jetons de CATÉGORIE, pas les rôles voisins qui
        // portaient la même valeur. `category-other` et `text-muted` étaient
        // tous deux `#64748b` — ils ont divergé le jour où le gris de texte a
        // été assombri pour tenir 4,5:1, et c'est bien : un gris de catégorie
        // ne porte aucun texte, il n'a aucune raison de suivre.
        //
        // Ils restent écrits ici plutôt que lus par `couleurDeCategorie()` : un
        // test qui importe l'utilitaire que la source utilise se compare à
        // lui-même. Nommer le jeton, en revanche, laisse la charte décider de
        // sa valeur tout en fixant lequel doit être employé.
        expect(series.datasets[0].backgroundColor).toEqual([
            jeton('category-chest'),
            jeton('category-back'),
            jeton('category-other'),
            jeton('category-other'),
        ])
    })

    it('draws no slice when there is no exercise', () => {
        const series = seriesOf(mountChart([]), 'Doughnut')

        expect(series.labels).toEqual([])
        expect(series.datasets[0].data).toEqual([])
    })
})

describe('DurationHistoryChart series', () => {
    it('plots each session duration against its date', () => {
        const series = seriesOf(
            mount(DurationHistoryChart, {
                props: {
                    data: [
                        { date: '01/07', duration: 45, name: 'Push' },
                        { date: '03/07', duration: 72, name: 'Pull' },
                    ],
                },
            }),
            'Bar',
        )

        expect(series.labels).toEqual(['01/07', '03/07'])
        expect(series.datasets[0].data).toEqual([45, 72])
    })
})

describe('SetsPerSessionChart series', () => {
    const mountChart = (data) => mount(SetsPerSessionChart, { props: { data } })

    it('plots the set count of each session against its date', () => {
        const series = seriesOf(
            mountChart([
                { date: '01/07', sets: 12 },
                { date: '03/07', sets: 18 },
            ]),
            'Bar',
        )

        expect(series.labels).toEqual(['01/07', '03/07'])
        expect(series.datasets[0].data).toEqual([12, 18])
    })

    it('plots no bar at all when there is no session', () => {
        const series = seriesOf(mountChart([]), 'Bar')

        expect(series.labels).toEqual([])
        expect(series.datasets[0].data).toEqual([])
    })
})

describe('VolumePerWorkoutChart series', () => {
    it('plots the volume of each session against its date', () => {
        const series = seriesOf(
            mount(VolumePerWorkoutChart, {
                props: {
                    data: [
                        { date: '01/07', volume: 8250 },
                        { date: '03/07', volume: 12500 },
                    ],
                },
            }),
            'Bar',
        )

        expect(series.labels).toEqual(['01/07', '03/07'])
        expect(series.datasets[0].label).toBe('Volume (kg)')
        expect(series.datasets[0].data).toEqual([8250, 12500])
    })
})

/**
 * Every one of these builds its bar fill as a canvas gradient over the plot
 * area — and Chart.js runs that callback once on the very first frame, before
 * any plot area exists. The guard is the difference between the chart rendering
 * and the page throwing on mount, and it is identical enough in all five that
 * testing only one would let the other four drift.
 */
describe.each([
    ['BodyPartDiffChart', BodyPartDiffChart, { data: [{ part: 'Poitrine', diff: 2, unit: 'cm' }] }],
    [
        'RecentWorkoutsExercisesChart',
        RecentWorkoutsExercisesChart,
        { data: [{ started_at: '2026-07-05T10:00:00Z', workout_lines_count: 5 }] },
    ],
    ['DurationHistoryChart', DurationHistoryChart, { data: [{ date: '01/07', duration: 45 }] }],
    ['SetsPerSessionChart', SetsPerSessionChart, { data: [{ date: '01/07', sets: 12 }] }],
    ['VolumePerWorkoutChart', VolumePerWorkoutChart, { data: [{ date: '01/07', volume: 8250 }] }],
])('%s first frame', (_name, component, props) => {
    it('asks for no fill colour at all before the plot area exists', () => {
        const paint = seriesOf(mount(component, { props }), 'Bar').datasets[0].backgroundColor

        expect(paint({ raw: 1, chart: { ctx: null, chartArea: null } })).toBeNull()
    })
})
