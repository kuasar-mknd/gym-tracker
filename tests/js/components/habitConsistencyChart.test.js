import { describe, it, expect, vi, beforeAll, afterAll } from 'vitest'
import { mount } from '@vue/test-utils'
import { chartDataOf, chartOptionsOf, tooltipLabelOf } from './chartRecorder.js'

vi.mock('vue-chartjs', async () => (await import('./chartRecorder.js')).chartRecorders())

const HabitConsistencyChart = (await import('@/Components/Stats/HabitConsistencyChart.vue')).default

const mountChart = (data) => mount(HabitConsistencyChart, { props: { data } })

const originalTimeZone = process.env.TZ

beforeAll(() => {
    /*
     * Behind UTC on purpose. `new Date('2026-07-31')` is specified to parse as
     * midnight *UTC*, so anywhere west of Greenwich it reads back as the 30th —
     * which is the whole reason parseCalendarDate exists. Run in UTC, this
     * suite could not tell the two parsings apart.
     */
    process.env.TZ = 'America/New_York'
})

afterAll(() => {
    if (originalTimeZone === undefined) {
        delete process.env.TZ
    } else {
        process.env.TZ = originalTimeZone
    }
})

describe('HabitConsistencyChart', () => {
    it('étiquette le jour saisi, pas la veille', () => {
        const chart = chartDataOf(mountChart([{ date: '2026-07-31', count: 3 }]), 'Line')

        // Locale-agnostic on purpose: what is at stake is the day number.
        expect(chart.labels[0]).toContain('31')
        expect(chart.labels[0]).not.toContain('30')
    })

    it('garde les jours dans l’ordre reçu, avec leur compte', () => {
        const chart = chartDataOf(
            mountChart([
                { date: '2026-07-29', count: 1 },
                { date: '2026-07-30', count: 4 },
                { date: '2026-07-31', count: 2 },
            ]),
            'Line',
        )

        expect(chart.datasets[0].data).toEqual([1, 4, 2])
        expect(chart.labels).toEqual(['29/07', '30/07', '31/07'])
    })

    it('trace quand même le compte d’un jour sans date exploitable', () => {
        // parseCalendarDate returns null there, and the optional chaining keeps
        // the series aligned with the days around it instead of throwing.
        const chart = chartDataOf(
            mountChart([
                { date: null, count: 2 },
                { date: '2026-07-31', count: 5 },
            ]),
            'Line',
        )

        expect(chart.labels[0]).toBeUndefined()
        expect(chart.datasets[0].data).toEqual([2, 5])
    })

    it('compte les habitudes en français dans l’infobulle', () => {
        const wrapper = mountChart([{ date: '2026-07-31', count: 3 }])

        expect(tooltipLabelOf(wrapper, 'Line', { parsed: { y: 3 } })).toBe('3 Complétés')
    })

    it('gradue l’axe en habitudes entières, depuis zéro', () => {
        // A habit is done or it is not; a 0,5 gridline would be meaningless.
        const yAxis = chartOptionsOf(mountChart([{ date: '2026-07-31', count: 3 }]), 'Line').scales.y

        expect(yAxis.ticks.stepSize).toBe(1)
        expect(yAxis.ticks.precision).toBe(0)
        expect(yAxis.beginAtZero).toBe(true)
    })
})
