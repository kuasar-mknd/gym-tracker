import { describe, it, expect, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { chartDataOf, tooltipLabelOf } from './chartRecorder.js'

vi.mock('vue-chartjs', async () => (await import('./chartRecorder.js')).chartRecorders())

const SetWeightProgressionChart = (await import('@/Components/Stats/SetWeightProgressionChart.vue')).default

const mountChart = (data) => mount(SetWeightProgressionChart, { props: { data } })

/**
 * @param {string} formattedDate
 * @param {Array<number|string>} weights
 * @returns {Object} A session in the shape the exercise page sends: newest first.
 */
const session = (formattedDate, weights) => ({
    formatted_date: formattedDate,
    sets: weights.map((weight) => ({ weight })),
})

describe('SetWeightProgressionChart', () => {
    it('renverse un historique descendant pour se lire de gauche à droite', () => {
        // The page sends newest first; a chart read that way runs backwards.
        const chart = chartDataOf(
            mountChart([session('12/05/2026', [80]), session('05/05/2026', [70]), session('28/04/2026', [60])]),
            'Line',
        )

        expect(chart.labels).toEqual(['28/04', '05/05', '12/05'])
        expect(chart.datasets[0].data).toEqual([60, 70, 80])
    })

    it('laisse tomber l’année de l’étiquette', () => {
        const chart = chartDataOf(mountChart([session('12/05/2026', [80])]), 'Line')

        expect(chart.labels).toEqual(['12/05'])
    })

    it('trace une courbe par série', () => {
        const chart = chartDataOf(mountChart([session('12/05/2026', [80, 75, 70])]), 'Line')

        expect(chart.datasets.map((dataset) => dataset.label)).toEqual(['Série 1', 'Série 2', 'Série 3'])
        expect(chart.datasets.map((dataset) => dataset.data[0])).toEqual([80, 75, 70])
    })

    it('s’arrête à trois courbes, même pour une séance de cinq séries', () => {
        const chart = chartDataOf(mountChart([session('12/05/2026', [80, 75, 70, 65, 60])]), 'Line')

        expect(chart.datasets).toHaveLength(3)
        expect(chart.datasets.map((dataset) => dataset.label)).toEqual(['Série 1', 'Série 2', 'Série 3'])
    })

    it('dimensionne les courbes sur la séance la plus fournie', () => {
        const chart = chartDataOf(mountChart([session('12/05/2026', [80]), session('05/05/2026', [70, 65])]), 'Line')

        expect(chart.datasets).toHaveLength(2)
    })

    it('laisse un trou plutôt qu’un zéro pour une série que la séance n’a pas eue', () => {
        // 0 kg would draw the second set collapsing to the floor that day.
        // null is the gap Chart.js bridges with spanGaps.
        const chart = chartDataOf(mountChart([session('12/05/2026', [80, 75]), session('05/05/2026', [70])]), 'Line')

        expect(chart.datasets[1].data).toEqual([null, 75])
        expect(chart.datasets[1].spanGaps).toBe(true)
    })

    it('laisse un trou pour une série au poids du corps', () => {
        const chart = chartDataOf(mountChart([session('12/05/2026', [80]), session('05/05/2026', [0])]), 'Line')

        expect(chart.datasets[0].data).toEqual([null, 80])
    })

    it('trace des nombres à partir des poids rendus en chaîne par la base', () => {
        const chart = chartDataOf(mountChart([session('12/05/2026', ['82.5'])]), 'Line')

        expect(chart.datasets[0].data).toEqual([82.5])
        expect(typeof chart.datasets[0].data[0]).toBe('number')
    })

    it('distingue les trois courbes par la couleur', () => {
        const chart = chartDataOf(mountChart([session('12/05/2026', [80, 75, 70])]), 'Line')

        expect(new Set(chart.datasets.map((dataset) => dataset.borderColor)).size).toBe(3)
    })

    it('nomme la série et l’unité dans l’infobulle', () => {
        const wrapper = mountChart([session('12/05/2026', [80, 75])])

        expect(tooltipLabelOf(wrapper, 'Line', { dataset: { label: 'Série 2' }, parsed: { y: 75 } })).toBe(
            'Série 2: 75 kg',
        )
    })
})
