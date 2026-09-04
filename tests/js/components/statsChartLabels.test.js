import { describe, it, expect, vi } from 'vitest'
import { mount } from '@vue/test-utils'

/**
 * The companion to statsChartSeries.test.js: that file checks what each chart
 * plots, this one checks what the user reads on it — the tooltip strings. They
 * are plain callbacks Chart.js invokes with a context object, so they can be
 * called directly with a stand-in context and the string compared.
 */
vi.mock('vue-chartjs', () => {
    const recorder = (name) => ({
        name,
        props: { data: { type: Object, default: null }, options: { type: Object, default: null } },
        template: '<div />',
    })

    return { Bar: recorder('Bar'), Line: recorder('Line'), Doughnut: recorder('Doughnut') }
})

const { volumeTooltipCallback } = await import('@/Components/Stats/chartConfig')
const BodyPartDiffChart = (await import('@/Components/Stats/BodyPartDiffChart.vue')).default
const BodyFatLineChart = (await import('@/Components/Stats/BodyFatLineChart.vue')).default
const RecentWorkoutsExercisesChart = (await import('@/Components/Stats/RecentWorkoutsExercisesChart.vue')).default
const ExerciseCategoryChart = (await import('@/Components/Stats/ExerciseCategoryChart.vue')).default
const DurationHistoryChart = (await import('@/Components/Stats/DurationHistoryChart.vue')).default
const SetsPerSessionChart = (await import('@/Components/Stats/SetsPerSessionChart.vue')).default
const VolumePerWorkoutChart = (await import('@/Components/Stats/VolumePerWorkoutChart.vue')).default

const tooltipOf = (wrapper, name) => wrapper.findComponent({ name }).props('options').plugins.tooltip

describe('volumeTooltipCallback', () => {
    const callback = (label, y) => volumeTooltipCallback({ dataset: { label }, parsed: { y } })

    it('groups the thousands and suffixes the unit', () => {
        // Compared against toLocaleString rather than a hard-coded separator so
        // the expectation holds whatever locale the runtime resolves to — but
        // it still fails the moment the grouping is dropped.
        expect(callback('Volume (kg)', 12500)).toBe(`Volume (kg): ${(12500).toLocaleString()} kg`)
    })

    it('never prints the raw digits of a five-figure volume', () => {
        expect(callback('Volume (kg)', 12500)).not.toContain('12500')
    })

    it('omits the separator when the dataset carries no label', () => {
        expect(callback(undefined, 500)).toBe('500 kg')
    })

    it('leaves a gap in the series unlabelled instead of reading "null kg"', () => {
        expect(callback('Volume (kg)', null)).toBe('Volume (kg): ')
    })
})

describe('VolumePerWorkoutChart tooltip', () => {
    const wrapper = mount(VolumePerWorkoutChart, { props: { data: [{ date: '01/07', volume: 8250 }] } })

    it('formats a hovered bar through the shared volume formatter', () => {
        // Spreading commonTooltipOptions must not shadow the callbacks, and the
        // dataset label must be the one the callback prefixes with.
        const label = tooltipOf(wrapper, 'Bar').callbacks.label({
            dataset: { label: 'Volume (kg)' },
            parsed: { y: 8250 },
        })

        expect(label).toBe(`Volume (kg): ${(8250).toLocaleString()} kg`)
    })

    it('keeps the shared tooltip chrome alongside its own callbacks', () => {
        expect(tooltipOf(wrapper, 'Bar').cornerRadius).toBe(12)
    })
})

describe('BodyPartDiffChart tooltip', () => {
    const measurements = [
        { part: 'Poids', diff: 0, unit: 'kg' },
        { part: 'Poitrine', diff: 2, unit: 'cm' },
        { part: 'Tour de bras', diff: -1.5, unit: 'mm' },
    ]

    const wrapper = mount(BodyPartDiffChart, { props: { data: measurements } })
    const labelFor = (part, x) => tooltipOf(wrapper, 'Bar').callbacks.label({ label: part, parsed: { x } })

    it('signs a gain explicitly', () => {
        expect(labelFor('Poitrine', 2)).toBe('+2 cm')
    })

    /**
     * Two things at once. The minus sign is already in the number, so the
     * explicit '+' must not be added on top of it. And 'Tour de bras' is row 2
     * of the props but bar 1 of the series, because the unchanged weight row is
     * filtered out before plotting — looking the unit up by position instead of
     * by name would label these millimetres as centimetres.
     */
    it('does not double the minus sign, and takes the unit of the part hovered', () => {
        expect(labelFor('Tour de bras', -1.5)).toBe('-1.5 mm')
    })

    it('prints no unit rather than "undefined" for a part it cannot find', () => {
        expect(labelFor('Inconnu', 3)).toBe('+3 ')
    })
})

describe('BodyFatLineChart tooltip', () => {
    it('spaces the percent sign the French way', () => {
        const wrapper = mount(BodyFatLineChart, { props: { data: [{ date: '01/07', body_fat: 18.4 }] } })

        expect(tooltipOf(wrapper, 'Line').callbacks.label({ parsed: { y: 18.4 } })).toBe('18.4 %')
    })
})

describe('RecentWorkoutsExercisesChart tooltip', () => {
    it('names the unit of what the bar counts', () => {
        const wrapper = mount(RecentWorkoutsExercisesChart, {
            props: { data: [{ started_at: '2026-07-05T10:00:00Z', workout_lines_count: 5 }] },
        })

        expect(tooltipOf(wrapper, 'Bar').callbacks.label({ parsed: { y: 5 } })).toBe('5 exercices')
    })
})

describe('SetsPerSessionChart tooltip', () => {
    it('names the unit of what the bar counts', () => {
        const wrapper = mount(SetsPerSessionChart, { props: { data: [{ date: '01/07', sets: 12 }] } })

        expect(tooltipOf(wrapper, 'Bar').callbacks.label({ parsed: { y: 12 } })).toBe('12 séries')
    })
})

describe('DurationHistoryChart tooltip', () => {
    const sessions = [
        { date: '01/07', duration: 45, name: 'Push' },
        { date: '03/07', duration: 72, name: 'Pull' },
    ]

    const wrapper = mount(DurationHistoryChart, { props: { data: sessions } })
    const tooltip = () => tooltipOf(wrapper, 'Bar').callbacks

    it('reads the duration in minutes', () => {
        expect(tooltip().label({ parsed: { y: 45 } })).toBe('45 min')
    })

    it('names the session under the bar that was hovered', () => {
        expect(tooltip().afterLabel({ dataIndex: 1 })).toBe('Pull')
    })

    it('says nothing rather than "undefined" for a session with no name', () => {
        const unnamed = mount(DurationHistoryChart, { props: { data: [{ date: '01/07', duration: 45 }] } })

        expect(tooltipOf(unnamed, 'Bar').callbacks.afterLabel({ dataIndex: 0 })).toBe('')
    })

    it('survives an index that no longer has a session behind it', () => {
        expect(tooltip().afterLabel({ dataIndex: 9 })).toBe('')
    })
})

describe('ExerciseCategoryChart tooltip', () => {
    const wrapper = mount(ExerciseCategoryChart, {
        props: { exercises: [{ category: 'Pectoraux' }, { category: 'Pectoraux' }, { category: 'Dos' }] },
    })

    const labelFor = (label, raw, total) =>
        tooltipOf(wrapper, 'Doughnut').callbacks.label({
            label,
            raw,
            datasetIndex: 0,
            chart: { _metasets: [{ total }] },
        })

    it('gives the slice its count and its share, rounded rather than truncated', () => {
        // 2/3 is 66.66…: truncating would show 66 %, and the slices would then
        // add up to 99 %.
        expect(labelFor('Pectoraux', 2, 3)).toBe('Pectoraux: 2 (67%)')
    })

    it('reports an exact share exactly', () => {
        expect(labelFor('Dos', 1, 4)).toBe('Dos: 1 (25%)')
    })
})
