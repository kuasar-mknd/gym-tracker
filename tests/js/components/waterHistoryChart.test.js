import { describe, it, expect, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { chartDataOf, chartOptionsOf, tooltipLabelOf } from './chartRecorder.js'

vi.mock('vue-chartjs', async () => (await import('./chartRecorder.js')).chartRecorders())

const WaterHistoryChart = (await import('@/Components/Stats/WaterHistoryChart.vue')).default

const mountChart = (props) => mount(WaterHistoryChart, { props })

/**
 * The Y axis maximum — its padding above the goal and its reactivity to a day
 * that passes it — is covered in chartAxes.test.js. What is left here is the
 * series itself and the labels.
 */
const week = [
    { day_name: 'Lundi', total: 1800 },
    { day_name: 'Mardi', total: 2600 },
    { day_name: 'Dimanche', total: 900 },
]

describe('WaterHistoryChart', () => {
    it('abrège les jours à trois lettres, dans l’ordre reçu', () => {
        const chart = chartDataOf(mountChart({ data: week }), 'Bar')

        expect(chart.labels).toEqual(['Lun', 'Mar', 'Dim'])
    })

    it('trace le volume bu chaque jour', () => {
        const chart = chartDataOf(mountChart({ data: week }), 'Bar')

        expect(chart.datasets[0].data).toEqual([1800, 2600, 900])
        expect(chart.datasets[0].label).toBe('Volume (ml)')
    })

    it('donne l’unité dans l’infobulle', () => {
        const wrapper = mountChart({ data: week })

        expect(tooltipLabelOf(wrapper, 'Bar', { raw: 1800 })).toBe('1800 ml')
    })

    it('garde un axe utilisable sur une semaine vide', () => {
        // Math.max() over nothing is -Infinity; the goal has to hold the axis up
        // on a week with no entry at all.
        const yAxis = chartOptionsOf(mountChart({ data: [], goal: 2500 }), 'Bar').scales.y

        expect(yAxis.max).toBe(2700)
        expect(chartDataOf(mountChart({ data: [] }), 'Bar').labels).toEqual([])
    })

    it('retient l’objectif par défaut de 2500 ml quand la page n’en envoie pas', () => {
        // Every day below the goal, so the axis can only come from the default.
        const modestWeek = [
            { day_name: 'Lundi', total: 1800 },
            { day_name: 'Mardi', total: 1200 },
        ]

        expect(chartOptionsOf(mountChart({ data: modestWeek }), 'Bar').scales.y.max).toBe(2700)
    })

    it('monte l’axe au-dessus du jour qui dépasse l’objectif', () => {
        expect(chartOptionsOf(mountChart({ data: week }), 'Bar').scales.y.max).toBe(2800)
    })
})
