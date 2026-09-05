import { describe, it, expect, vi, beforeAll, afterAll } from 'vitest'
import { jeton, jetonTransparent } from '@/Utils/couleurs'
import { mount } from '@vue/test-utils'

vi.mock('vue-chartjs', () => {
    const recorder = (name) => ({
        name,
        props: { data: { type: Object, default: null }, options: { type: Object, default: null } },
        template: '<div />',
    })

    return { Line: recorder('Line'), Bar: recorder('Bar'), Doughnut: recorder('Doughnut') }
})

const BodyPartHistoryChart = (await import('@/Components/Stats/BodyPartHistoryChart.vue')).default

const chartOf = (props) => mount(BodyPartHistoryChart, { props }).findComponent({ name: 'Line' })

/** Le même jour, tel que l'axe l'écrit : `jj/mm`, sans passer par la locale du navigateur. */
const dayLabel = (year, monthIndex, day) => `${String(day).padStart(2, '0')}/${String(monthIndex + 1).padStart(2, '0')}`

/**
 * A measurement is a calendar day, not an instant. Read in a timezone behind
 * UTC — the whole American continent — `new Date('2026-07-31')` is 20:00 on the
 * 30th, so the axis labels every measurement one day early. The tests below are
 * blind to that bug in a timezone at or ahead of UTC, which is where this suite
 * otherwise runs, so the zone is forced for this file.
 */
const originalTimezone = process.env.TZ

beforeAll(() => {
    process.env.TZ = 'America/New_York'
})

afterAll(() => {
    if (originalTimezone === undefined) {
        delete process.env.TZ
    } else {
        process.env.TZ = originalTimezone
    }
})

describe('BodyPartHistoryChart', () => {
    it('étiquette le jour saisi, même à l’ouest de UTC', () => {
        const chart = chartOf({ data: [{ measured_at: '2026-07-31', value: 42 }] })

        expect(chart.props('data').labels).toEqual([dayLabel(2026, 6, 31)])
    })

    it('ne retient que le jour d’un horodatage complet', () => {
        // 23:30 UTC le 31 est déjà le 1er août à Paris : la mesure doit rester
        // affichée au jour où elle a été prise.
        const chart = chartOf({ data: [{ measured_at: '2026-07-31T23:30:00Z', value: 42 }] })

        expect(chart.props('data').labels).toEqual([dayLabel(2026, 6, 31)])
    })

    it('n’étiquette rien plutôt que « Invalid Date » sur une mesure sans jour', () => {
        const chart = chartOf({
            data: [
                { measured_at: null, value: 42 },
                { measured_at: 'pas une date', value: 43 },
            ],
        })

        expect(chart.props('data').labels).toEqual([undefined, undefined])
    })

    it('aligne les valeurs sur les jours, dans l’ordre reçu', () => {
        const chart = chartOf({
            data: [
                { measured_at: '2026-07-01', value: 40 },
                { measured_at: '2026-07-15', value: 41.5 },
                { measured_at: '2026-07-31', value: 42 },
            ],
        })

        expect(chart.props('data').labels).toEqual([dayLabel(2026, 6, 1), dayLabel(2026, 6, 15), dayLabel(2026, 6, 31)])
        expect(chart.props('data').datasets[0].data).toEqual([40, 41.5, 42])
    })

    it('trace un historique vide sans point', () => {
        const data = chartOf({ data: [] }).props('data')

        expect(data.labels).toEqual([])
        expect(data.datasets[0].data).toEqual([])
    })

    it('nomme la série d’après la mesure suivie', () => {
        expect(chartOf({ data: [], label: 'Tour de bras' }).props('data').datasets[0].label).toBe('Tour de bras')
        expect(chartOf({ data: [] }).props('data').datasets[0].label).toBe('Measurement')
    })

    it('libelle le survol avec l’unité de la mesure', () => {
        const tooltipOf = (props) => chartOf(props).props('options').plugins.tooltip.callbacks.label

        expect(tooltipOf({ data: [] })({ parsed: { y: 42 } })).toBe('42 cm')
        expect(tooltipOf({ data: [], unit: '%' })({ parsed: { y: 18.4 } })).toBe('18.4 %')
    })

    /**
     * chartOptions is built once at setup, so the tooltip has to read the unit
     * off props at call time. Capturing it into a local would leave a chart
     * reused by an Inertia visit announcing the previous body part's unit.
     */
    it('suit l’unité quand on change de mesure sans remonter le composant', async () => {
        const wrapper = mount(BodyPartHistoryChart, { props: { data: [], unit: 'cm' } })

        await wrapper.setProps({ unit: 'kg' })

        const label = wrapper.findComponent({ name: 'Line' }).props('options').plugins.tooltip.callbacks.label

        expect(label({ parsed: { y: 42 } })).toBe('42 kg')
    })

    describe('dégradés', () => {
        const dataset = () => chartOf({ data: [{ measured_at: '2026-07-31', value: 42 }] }).props('data').datasets[0]

        /** A canvas context that records the gradient it is asked for. */
        const recordingContext = () => {
            const recorded = []

            return {
                recorded,
                ctx: {
                    createLinearGradient: (...coordinates) => {
                        recorded.push(coordinates)

                        return { addColorStop: (offset, color) => recorded.push([offset, color]) }
                    },
                },
            }
        }

        it('trace la courbe de gauche à droite, violet vers rose', () => {
            const { ctx, recorded } = recordingContext()

            dataset().borderColor({ chart: { ctx, chartArea: { left: 10, right: 310, top: 0, bottom: 256 } } })

            expect(recorded).toEqual([
                [10, 0, 310, 0],
                [0, jeton('accent-tertiary')],
                [1, jeton('accent-secondary')],
            ])
        })

        it('remplit de haut en bas, en s’effaçant vers l’axe', () => {
            const { ctx, recorded } = recordingContext()

            dataset().backgroundColor({ chart: { ctx, chartArea: { left: 10, right: 310, top: 0, bottom: 256 } } })

            expect(recorded).toEqual([
                [0, 0, 0, 256],
                [0, jetonTransparent('accent-tertiary', 0.2)],
                [1, jetonTransparent('accent-tertiary', 0)],
            ])
        })

        /**
         * Chart.js evaluates scriptable options once before it has measured the
         * canvas. Reading `chartArea.left` off that undefined area throws, and
         * the page renders blank instead of the chart.
         */
        it('ne peint rien tant que la zone de tracé n’est pas mesurée', () => {
            const beforeLayout = { chart: { ctx: {}, chartArea: undefined } }

            expect(dataset().borderColor(beforeLayout)).toBeNull()
            expect(dataset().backgroundColor(beforeLayout)).toBeNull()
        })
    })
})
