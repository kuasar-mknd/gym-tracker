import { describe, it, expect, vi } from 'vitest'
import { jeton, jetonTransparent } from '@/Utils/couleurs'
import { mount } from '@vue/test-utils'
import { chartDataOf } from './chartRecorder.js'

vi.mock('vue-chartjs', async () => (await import('./chartRecorder.js')).chartRecorders())

const BodyFatLineChart = (await import('@/Components/Stats/BodyFatLineChart.vue')).default
const DurationHistoryChart = (await import('@/Components/Stats/DurationHistoryChart.vue')).default
const FastingHistoryChart = (await import('@/Components/Stats/FastingHistoryChart.vue')).default
const HabitConsistencyChart = (await import('@/Components/Stats/HabitConsistencyChart.vue')).default
const HabitHistoryChart = (await import('@/Components/Stats/HabitHistoryChart.vue')).default
const SetsPerSessionChart = (await import('@/Components/Stats/SetsPerSessionChart.vue')).default
const JournalChart = (await import('@/Components/Stats/JournalChart.vue')).default
const MaxWeightChart = (await import('@/Components/Stats/MaxWeightChart.vue')).default
const MonthlyVolumeChart = (await import('@/Components/Stats/MonthlyVolumeChart.vue')).default
const OneRepMaxPercentagesChart = (await import('@/Components/Stats/OneRepMaxPercentagesChart.vue')).default
const RecentPRsChart = (await import('@/Components/Stats/RecentPRsChart.vue')).default
const RecentWorkoutsChart = (await import('@/Components/Stats/RecentWorkoutsChart.vue')).default
const RecentWorkoutsExercisesChart = (await import('@/Components/Stats/RecentWorkoutsExercisesChart.vue')).default
const RecentWorkoutsTimelineChart = (await import('@/Components/Stats/RecentWorkoutsTimelineChart.vue')).default
const SupplementUsageChart = (await import('@/Components/Stats/SupplementUsageChart.vue')).default
const VolumePerWorkoutChart = (await import('@/Components/Stats/VolumePerWorkoutChart.vue')).default
const VolumeTrendChart = (await import('@/Components/Stats/VolumeTrendChart.vue')).default
const WaterHistoryChart = (await import('@/Components/Stats/WaterHistoryChart.vue')).default
const WeightHistoryChart = (await import('@/Components/Stats/WeightHistoryChart.vue')).default
const WilksHistoryChart = (await import('@/Components/Stats/WilksHistoryChart.vue')).default
const WilksScoreChart = (await import('@/Components/Stats/WilksScoreChart.vue')).default
const WorkoutDurationChart = (await import('@/Components/Stats/WorkoutDurationChart.vue')).default
const WorkoutFrequencyChart = (await import('@/Components/Stats/WorkoutFrequencyChart.vue')).default
const WorkoutHistoryTimelineChart = (await import('@/Components/Stats/WorkoutHistoryTimelineChart.vue')).default

/**
 * Chart.js resolves a *scriptable* colour by calling it on every frame with the
 * live chart. The area fills under the Stats lines use that hook to build a canvas
 * gradient, and none of the existing suites ever calls the functions they hand
 * over — the charts are asserted through their series and their tooltips, which
 * both stop short of the paint.
 *
 * Three things can go wrong in there, and all three are silent:
 *
 *   - the guard on `chartArea` disappears, and the first frame — the one Chart.js
 *     runs before it has measured anything — reads `undefined.top` and takes the
 *     whole stats page down with it;
 *   - the two coordinate pairs get swapped, and a fill meant to fade downwards
 *     is painted as a left-to-right stripe (or the reverse);
 *   - a colour stop is moved, so a bar fades out of its baseline instead of into
 *     it, or a line is tinted with the wrong end of its own gradient.
 *
 * The plot area below deliberately has four distinct edges: with a square area
 * the swapped-axis mistake produces the same four numbers as the correct one and
 * nothing catches it.
 */
const AREA = { top: 11, right: 444, bottom: 222, left: 33 }

/** A fill: opaque at the top of the plot, fading down towards the axis. */
const DOWNWARD = [0, AREA.top, 0, AREA.bottom]

/**
 * Calls a scriptable colour the way Chart.js would and records what it asked the
 * canvas for.
 *
 * @param {Function} paint - The scriptable option taken off the dataset.
 * @param {Object} [options]
 * @param {Object} [options.chartArea] - Pass `undefined` for the first frame.
 * @returns {{returned: *, requested: Array<Array<number>>, stops: Array}}
 */
const paintWith = (paint, options = {}) => {
    // Read through the key rather than defaulting the parameter: the first frame
    // hands the callback an `undefined` chartArea, which a default parameter
    // would quietly replace with the measured one — and the guard would then
    // never be exercised at all.
    const chartArea = 'chartArea' in options ? options.chartArea : AREA
    const requested = []
    const stops = []
    const gradient = { addColorStop: (offset, color) => stops.push([offset, color]) }
    const ctx = {
        createLinearGradient: (...coordinates) => {
            requested.push(coordinates)

            return gradient
        },
    }

    return { returned: paint({ chart: { ctx, chartArea } }), requested, stops, gradient }
}

const paintOf = (component, key, { type = 'Bar', datasetIndex = 0 } = {}) =>
    chartDataOf(mount(component, { props: { data: [] } }), type).datasets[datasetIndex][key]

/**
 * One row per scriptable colour still unpainted by the rest of the suite. The
 * expectations are written out here rather than read back off the component —
 * comparing a component to itself would pass whatever it did.
 */
const cases = [
    {
        chart: 'BodyFatLineChart',
        component: BodyFatLineChart,
        type: 'Line',
        key: 'backgroundColor',
        requested: DOWNWARD,
        stops: [
            [0, jetonTransparent('accent-secondary', 0.25)],
            [1, jetonTransparent('accent-secondary', 0)],
        ],
    },
    {
        chart: 'HabitConsistencyChart',
        component: HabitConsistencyChart,
        type: 'Line',
        key: 'backgroundColor',
        requested: DOWNWARD,
        stops: [
            [0, jetonTransparent('accent-state', 0.2)],
            [1, jetonTransparent('accent-state', 0)],
        ],
    },
    {
        chart: 'MaxWeightChart',
        component: MaxWeightChart,
        type: 'Line',
        key: 'backgroundColor',
        requested: DOWNWARD,
        stops: [
            [0, jetonTransparent('accent-state', 0.2)],
            [1, jetonTransparent('accent-state', 0)],
        ],
    },
    {
        chart: 'RecentWorkoutsTimelineChart',
        component: RecentWorkoutsTimelineChart,
        type: 'Line',
        key: 'backgroundColor',
        requested: DOWNWARD,
        stops: [
            [0, jetonTransparent('accent-primary', 0.2)],
            [1, jetonTransparent('accent-primary', 0)],
        ],
    },
    {
        chart: 'WeightHistoryChart',
        component: WeightHistoryChart,
        type: 'Line',
        key: 'backgroundColor',
        requested: DOWNWARD,
        stops: [
            [0, jetonTransparent('accent-primary', 0.2)],
            [1, jetonTransparent('accent-primary', 0)],
        ],
    },
    {
        chart: 'WilksHistoryChart',
        component: WilksHistoryChart,
        type: 'Line',
        key: 'backgroundColor',
        requested: DOWNWARD,
        stops: [
            [0, jetonTransparent('accent-primary', 0.2)],
            [1, jetonTransparent('accent-primary', 0)],
        ],
    },
    {
        chart: 'WilksScoreChart',
        component: WilksScoreChart,
        type: 'Line',
        key: 'backgroundColor',
        requested: DOWNWARD,
        stops: [
            [0, jetonTransparent('accent-primary', 0.2)],
            [1, jetonTransparent('accent-primary', 0)],
        ],
    },
    {
        chart: 'WorkoutDurationChart',
        component: WorkoutDurationChart,
        type: 'Line',
        key: 'backgroundColor',
        requested: DOWNWARD,
        stops: [
            [0, jetonTransparent('accent-tertiary', 0.2)],
            [1, jetonTransparent('accent-tertiary', 0)],
        ],
    },
]

/**
 * Les séries peintes d'une seule couleur : le dégradé d'une teinte à l'autre a
 * quitté la charte, il ne reste que le trait ou l'aplat de l'accent du graphique.
 * Les attentes sont écrites ici, pas relues sur le composant.
 */
const flats = [
    {
        chart: 'BodyFatLineChart',
        component: BodyFatLineChart,
        type: 'Line',
        key: 'borderColor',
        expected: () => jeton('accent-secondary'),
    },
    {
        chart: 'DurationHistoryChart',
        component: DurationHistoryChart,
        key: 'backgroundColor',
        expected: () => jetonTransparent('accent-primary', 0.7),
    },
    {
        chart: 'FastingHistoryChart',
        component: FastingHistoryChart,
        key: 'backgroundColor',
        expected: () => jeton('accent-primary'),
    },
    {
        chart: 'HabitConsistencyChart',
        component: HabitConsistencyChart,
        type: 'Line',
        key: 'borderColor',
        expected: () => jeton('accent-state'),
    },
    {
        chart: 'HabitHistoryChart',
        component: HabitHistoryChart,
        key: 'backgroundColor',
        expected: () => jeton('accent-tertiary'),
    },
    {
        chart: 'MonthlyVolumeChart',
        component: MonthlyVolumeChart,
        key: 'backgroundColor',
        expected: () => jeton('accent-primary'),
    },
    {
        chart: 'OneRepMaxPercentagesChart',
        component: OneRepMaxPercentagesChart,
        key: 'backgroundColor',
        expected: () => jeton('accent-primary'),
    },
    {
        chart: 'RecentPRsChart',
        component: RecentPRsChart,
        key: 'backgroundColor',
        expected: () => jeton('accent-primary'),
    },
    {
        chart: 'RecentWorkoutsChart',
        component: RecentWorkoutsChart,
        key: 'backgroundColor',
        expected: () => jeton('accent-primary'),
    },
    {
        chart: 'RecentWorkoutsExercisesChart',
        component: RecentWorkoutsExercisesChart,
        key: 'backgroundColor',
        expected: () => jeton('accent-state'),
    },
    {
        chart: 'RecentWorkoutsTimelineChart',
        component: RecentWorkoutsTimelineChart,
        type: 'Line',
        key: 'borderColor',
        expected: () => jeton('accent-primary'),
    },
    {
        chart: 'SetsPerSessionChart',
        component: SetsPerSessionChart,
        key: 'backgroundColor',
        expected: () => jeton('accent-secondary'),
    },
    {
        chart: 'SupplementUsageChart',
        component: SupplementUsageChart,
        key: 'backgroundColor',
        expected: () => jetonTransparent('accent-primary', 0.7),
    },
    {
        chart: 'VolumePerWorkoutChart',
        component: VolumePerWorkoutChart,
        key: 'backgroundColor',
        expected: () => jeton('accent-primary'),
    },
    {
        chart: 'VolumeTrendChart',
        component: VolumeTrendChart,
        key: 'backgroundColor',
        expected: () => jeton('accent-primary'),
    },
    {
        chart: 'WaterHistoryChart',
        component: WaterHistoryChart,
        key: 'backgroundColor',
        expected: () => jetonTransparent('accent-primary', 0.7),
    },
    {
        chart: 'WeightHistoryChart',
        component: WeightHistoryChart,
        type: 'Line',
        key: 'borderColor',
        expected: () => jeton('accent-primary'),
    },
    {
        chart: 'WilksHistoryChart',
        component: WilksHistoryChart,
        type: 'Line',
        key: 'borderColor',
        expected: () => jeton('accent-primary'),
    },
    {
        chart: 'WilksScoreChart',
        component: WilksScoreChart,
        type: 'Line',
        key: 'borderColor',
        expected: () => jeton('accent-primary'),
    },
    {
        chart: 'WorkoutFrequencyChart',
        component: WorkoutFrequencyChart,
        key: 'backgroundColor',
        expected: () => jetonTransparent('accent-primary', 0.6),
    },
    {
        chart: 'WorkoutHistoryTimelineChart',
        component: WorkoutHistoryTimelineChart,
        datasetIndex: 1,
        key: 'backgroundColor',
        expected: () => jetonTransparent('accent-primary', 0.6),
    },
]

const paintFor = ({ component, key, type, datasetIndex }) => paintOf(component, key, { type, datasetIndex })

describe('les dégradés que les graphes demandent au canvas', () => {
    it.each(cases)('$chart · $key couvre la zone de tracé dans le bon sens', (subject) => {
        const { requested } = paintWith(paintFor(subject))

        // The four edges are distinct on purpose: an equality on the whole
        // quadruple is the only assertion a swapped or reversed pair fails.
        expect(requested).toEqual([subject.requested])
    })

    it.each(cases)('$chart · $key pose ses couleurs dans l’ordre', (subject) => {
        const { stops } = paintWith(paintFor(subject))

        // Ordered pairs rather than a membership check: two stops swapped are
        // still both present, and the gradient runs backwards on screen.
        expect(stops).toEqual(subject.stops)
    })

    it.each(cases)('$chart · $key ne touche pas au canvas avant la première mesure', (subject) => {
        const { returned, requested } = paintWith(paintFor(subject), { chartArea: undefined })

        expect(returned).toBeNull()
        expect(requested).toEqual([])
    })

    it.each(cases)('$chart · $key rend le dégradé, pas une couleur figée', (subject) => {
        const { returned, gradient } = paintWith(paintFor(subject))

        // Returning the stops instead of the object, or forgetting the return
        // altogether, leaves Chart.js with `undefined` and the series vanishes.
        expect(returned).toBe(gradient)
    })
})

describe('les séries peintes d’une seule couleur', () => {
    it.each(flats)('$chart · $key est une couleur unie, sans dégradé', (subject) => {
        const painted = paintFor(subject)

        expect(painted).toBe(subject.expected())
        expect(typeof painted).toBe('string')
    })
})

describe('JournalChart teinte son remplissage selon la métrique choisie', () => {
    const entry = (date, values) => ({ date, mood_score: 3, energy_level: 7, ...values })

    const mountJournal = () => mount(JournalChart, { props: { data: [entry('2026-02-01')] } })

    const buttonLabelled = (wrapper, label) => wrapper.findAll('button').find((button) => button.text() === label)

    it('convertit l’hexadécimal de la métrique en rgba translucide', () => {
        // #F472B6 is 244, 114, 182 — the fill is that colour at 0.2 fading to the
        // same colour at 0. A gradient built from the raw hex would draw an
        // opaque block over the whole plot.
        const paint = chartDataOf(mountJournal(), 'Line').datasets[0].backgroundColor

        expect(paintWith(paint).stops).toEqual([
            [0, jetonTransparent('palette-rose', 0.2)],
            [1, jetonTransparent('palette-rose', 0)],
        ])
    })

    it('suit la métrique quand elle change', async () => {
        // #FACC15 is 250, 204, 21. A fill pinned to the first metric leaves the
        // curve for Énergie drawn under Humeur's pink.
        const wrapper = mountJournal()

        await buttonLabelled(wrapper, 'Énergie').trigger('click')

        const paint = chartDataOf(wrapper, 'Line').datasets[0].backgroundColor

        expect(paintWith(paint).stops).toEqual([
            [0, jetonTransparent('palette-ambre', 0.2)],
            [1, jetonTransparent('palette-ambre', 0)],
        ])
    })

    it('descend le remplissage et ne peint rien avant la première mesure', () => {
        const paint = chartDataOf(mountJournal(), 'Line').datasets[0].backgroundColor

        expect(paintWith(paint).requested).toEqual([DOWNWARD])
        expect(paintWith(paint, { chartArea: undefined }).returned).toBeNull()
    })
})
