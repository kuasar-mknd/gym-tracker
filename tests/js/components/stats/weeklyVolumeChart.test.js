import { describe, it, expect, vi } from 'vitest'
import { jeton, jetonTransparent } from '@/Utils/couleurs'
import { mount } from '@vue/test-utils'

/**
 * vue-chartjs is replaced rather than stubbed by name: the real component
 * reaches for a canvas jsdom does not draw, and what matters here is the series
 * and the callbacks handed to Chart.js — that is where a regression hides.
 */
vi.mock('vue-chartjs', () => {
    const recorder = (name) => ({
        name,
        props: { data: { type: Object, default: null }, options: { type: Object, default: null } },
        template: '<div />',
    })

    return { Line: recorder('Line'), Bar: recorder('Bar'), Doughnut: recorder('Doughnut') }
})

const WeeklyVolumeChart = (await import('@/Components/Stats/WeeklyVolumeChart.vue')).default

const chartOf = (data) => mount(WeeklyVolumeChart, { props: { data } }).findComponent({ name: 'Line' })

/** A canvas context that records the gradients asked of it. */
const recordingContext = () => {
    const created = []

    return {
        created,
        ctx: {
            createLinearGradient: (...coordinates) => {
                const stops = []
                created.push({ coordinates, stops })

                return { addColorStop: (offset, color) => stops.push([offset, color]) }
            },
        },
    }
}

/** The argument Chart.js passes to a scriptable option. */
const scriptableContext = (ctx, chartArea) => ({ chart: { ctx, chartArea } })

/** The plot area once Chart.js has measured the canvas. */
const laidOutArea = { top: 0, bottom: 200, left: 10, right: 310 }

describe('WeeklyVolumeChart', () => {
    it('aligne chaque volume sur son jour, dans l’ordre reçu', () => {
        const chart = chartOf([
            { day_label: 'Lun', volume: 1200 },
            { day_label: 'Mar', volume: 0 },
            { day_label: 'Mer', volume: 3400 },
        ])

        expect(chart.props('data').labels).toEqual(['Lun', 'Mar', 'Mer'])
        expect(chart.props('data').datasets[0].data).toEqual([1200, 0, 3400])
    })

    it('trace une semaine vide sans inventer de point', () => {
        const data = chartOf([]).props('data')

        expect(data.labels).toEqual([])
        expect(data.datasets).toHaveLength(1)
        expect(data.datasets[0].data).toEqual([])
    })

    it('suit les données quand la semaine change', async () => {
        const wrapper = mount(WeeklyVolumeChart, { props: { data: [{ day_label: 'Lun', volume: 1200 }] } })

        await wrapper.setProps({ data: [{ day_label: 'Dim', volume: 800 }] })

        expect(wrapper.findComponent({ name: 'Line' }).props('data').labels).toEqual(['Dim'])
    })

    it('libelle le survol en kilos', () => {
        const label = chartOf([{ day_label: 'Lun', volume: 1250 }])
            .props('options')
            .plugins.tooltip.callbacks.label({ parsed: { y: 1250 } })

        expect(label).toBe('1250 kg')
    })

    it('part de zéro, pour ne pas exagérer l’écart entre deux jours', () => {
        expect(chartOf([{ day_label: 'Lun', volume: 1200 }]).props('options').scales.y.beginAtZero).toBe(true)
    })

    describe('dégradés', () => {
        const dataset = () => chartOf([{ day_label: 'Lun', volume: 1200 }]).props('data').datasets[0]

        it('remplit de haut en bas, en s’effaçant vers l’axe', () => {
            const { ctx, created } = recordingContext()

            dataset().backgroundColor(scriptableContext(ctx, laidOutArea))

            // Vertical: x stays at 0, y runs from the top of the area to its
            // bottom. Handing it the horizontal coordinates instead would fade
            // the fill left-to-right, across days rather than towards the axis.
            expect(created[0].coordinates).toEqual([0, 0, 0, 200])
            expect(created[0].stops.map(([offset]) => offset)).toEqual([0, 1])
            expect(created[0].stops[0][1]).toBe(jetonTransparent('accent-primary', 0.2))
            expect(created[0].stops[1][1]).toBe(jetonTransparent('accent-primary', 0))
        })

        it('trace la ligne de gauche à droite, orange vers violet', () => {
            const { ctx, created } = recordingContext()

            dataset().borderColor(scriptableContext(ctx, laidOutArea))

            expect(created[0].coordinates).toEqual([10, 0, 310, 0])
            expect(created[0].stops).toEqual([
                [0, jeton('accent-primary')],
                [0.5, jeton('accent-secondary')],
                [1, jeton('accent-tertiary')],
            ])
        })

        /**
         * Chart.js evaluates scriptable options once before it has measured the
         * canvas. Reading `chartArea.top` off that undefined area throws, and
         * the whole page renders blank instead of the chart.
         */
        it('ne peint rien tant que la zone de tracé n’est pas mesurée', () => {
            const { ctx } = recordingContext()
            const beforeLayout = scriptableContext(ctx)

            expect(dataset().backgroundColor(beforeLayout)).toBeNull()
            expect(dataset().borderColor(beforeLayout)).toBeNull()
        })
    })
})
