import { describe, it, expect, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { chartDataOf, tooltipLabelOf } from './chartRecorder.js'

vi.mock('vue-chartjs', async () => (await import('./chartRecorder.js')).chartRecorders())

const MacroHistoryChart = (await import('@/Components/Stats/MacroHistoryChart.vue')).default

// The macro endpoint hands back the newest revision first.
const newestFirst = [
    { created_at: '2026-07-31T09:00:00', target_calories: 2200, tdee: 2600 },
    { created_at: '2026-07-17T09:00:00', target_calories: 2300, tdee: 2650 },
    { created_at: '2026-07-05T09:00:00', target_calories: 2400, tdee: 2700 },
]

const mountChart = (data = newestFirst) => mount(MacroHistoryChart, { props: { data } })

describe('MacroHistoryChart series', () => {
    it('reads chronologically, oldest revision on the left', () => {
        const series = chartDataOf(mountChart(), 'Line')

        // `jj/mm`, comme tout axe de dates : le jour de created_at, du plus ancien au plus récent.
        expect(series.labels.map((label) => label.match(/\d+/)[0])).toEqual(['05', '17', '31'])
    })

    it('keeps the target and the TDEE on separate lines, each following its own date', () => {
        const series = chartDataOf(mountChart(), 'Line')

        expect(series.datasets.map((dataset) => dataset.label)).toEqual(['Cible (kcal)', 'TDEE (kcal)'])
        expect(series.datasets[0].data).toEqual([2400, 2300, 2200])
        expect(series.datasets[1].data).toEqual([2700, 2650, 2600])
    })

    it('does not reverse the page prop it was handed', () => {
        // `[...props.data].reverse()`, not `props.data.reverse()`: the second
        // flips the array Inertia keeps, so the list rendered beside the chart
        // reverses every time the chart recomputes.
        const source = [...newestFirst]

        mountChart(source)

        expect(source.map((revision) => revision.created_at)).toEqual([
            '2026-07-31T09:00:00',
            '2026-07-17T09:00:00',
            '2026-07-05T09:00:00',
        ])
    })

    it('plots no points at all before the first macro target is set', () => {
        const series = chartDataOf(mountChart([]), 'Line')

        expect(series.labels).toEqual([])
        expect(series.datasets[0].data).toEqual([])
        expect(series.datasets[1].data).toEqual([])
    })

    it('follows the history when a new target is saved', () => {
        // chartData is a computed; an Inertia visit reuses the instance rather
        // than remounting it, so a series pinned to the first render would keep
        // showing the superseded target.
        const wrapper = mountChart([newestFirst[2]])

        return wrapper.setProps({ data: newestFirst }).then(() => {
            expect(chartDataOf(wrapper, 'Line').datasets[0].data).toEqual([2400, 2300, 2200])
        })
    })
})

describe('MacroHistoryChart tooltip', () => {
    it('tells the target apart from the TDEE', () => {
        // Two lines share one tooltip, and the axis carries no unit, so the
        // series name has to travel with the value.
        const wrapper = mountChart()

        expect(tooltipLabelOf(wrapper, 'Line', { dataset: { label: 'TDEE (kcal)' }, parsed: { y: 2650 } })).toBe(
            'TDEE (kcal): 2650',
        )
        expect(tooltipLabelOf(wrapper, 'Line', { dataset: { label: 'Cible (kcal)' }, parsed: { y: 2400 } })).toBe(
            'Cible (kcal): 2400',
        )
    })
})

describe('MacroHistoryChart gradients', () => {
    const AREA = { left: 10, right: 310, top: 20, bottom: 220 }

    const paintingChart = () => {
        const gradients = []

        return {
            gradients,
            chart: {
                chartArea: AREA,
                ctx: {
                    createLinearGradient: (...from) => {
                        const gradient = { from, stops: [] }
                        gradients.push(gradient)

                        return { addColorStop: (offset, color) => gradient.stops.push([offset, color]) }
                    },
                },
            },
        }
    }

    const paints = () => {
        const dataset = chartDataOf(mountChart(), 'Line').datasets[0]

        return [
            ['borderColor', dataset.borderColor],
            ['backgroundColor', dataset.backgroundColor],
        ]
    }

    it('asks for no colour at all before the plot area exists', () => {
        // Chart.js resolves the colour once on the very first frame, when
        // chartArea is still undefined. Without the guard that read takes the
        // whole stats page down rather than losing a gradient.
        for (const [name, paint] of paints()) {
            expect(paint({ chart: { ctx: null, chartArea: null } }), name).toBeNull()
        }
    })

    it('spans the plot area it was measured against, not a fixed pixel box', () => {
        for (const [name, paint] of paints()) {
            const { chart, gradients } = paintingChart()

            paint({ chart })

            expect(gradients, name).toHaveLength(1)
            expect(
                gradients[0].from.every((coordinate) => coordinate === 0 || Object.values(AREA).includes(coordinate)),
                `${name} from ${gradients[0].from}`,
            ).toBe(true)
            expect(gradients[0].stops.length, name).toBe(2)
        }
    })

    it('runs the line gradient across the chart and the fill down it', () => {
        const [[, border], [, background]] = paints()
        const borderChart = paintingChart()
        const backgroundChart = paintingChart()

        border({ chart: borderChart.chart })
        background({ chart: backgroundChart.chart })

        // The line is tinted left to right along the time axis; the fill fades
        // top to bottom under it. Swapping the two paints a stripe instead.
        expect(borderChart.gradients[0].from).toEqual([AREA.left, 0, AREA.right, 0])
        expect(backgroundChart.gradients[0].from).toEqual([0, AREA.top, 0, AREA.bottom])
    })
})
