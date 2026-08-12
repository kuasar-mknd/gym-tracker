import { describe, it, expect, vi } from 'vitest'
import { mount } from '@vue/test-utils'

vi.mock('vue-chartjs', () => {
    const recorder = (name) => ({
        name,
        props: { data: { type: Object, default: null }, options: { type: Object, default: null } },
        template: '<div />',
    })

    return { Line: recorder('Line'), Bar: recorder('Bar'), Doughnut: recorder('Doughnut') }
})

const GoalTypeChart = (await import('@/Components/Stats/GoalTypeChart.vue')).default

const chartOf = (data) => mount(GoalTypeChart, { props: { data } }).findComponent({ name: 'Doughnut' })

const goals = [
    { label: 'Force', count: 4 },
    { label: 'Fréquence', count: 2 },
    { label: 'Volume', count: 1 },
]

describe('GoalTypeChart', () => {
    it('compte les objectifs par type, dans l’ordre reçu', () => {
        const chart = chartOf(goals)

        expect(chart.props('data').labels).toEqual(['Force', 'Fréquence', 'Volume'])
        expect(chart.props('data').datasets[0].data).toEqual([4, 2, 1])
    })

    it('ne découpe rien sans objectif', () => {
        const data = chartOf([]).props('data')

        expect(data.labels).toEqual([])
        expect(data.datasets[0].data).toEqual([])
    })

    /**
     * The palette is positional, so it has to cover the five goal types the API
     * can return. A shorter palette makes Chart.js reuse colour zero, and two
     * different goal types then share a slice colour in the legend.
     */
    it('garde une couleur distincte pour chacun des cinq types', () => {
        const colors = chartOf(goals).props('data').datasets[0].backgroundColor

        expect(colors).toHaveLength(5)
        expect(new Set(colors).size).toBe(5)
    })
})
