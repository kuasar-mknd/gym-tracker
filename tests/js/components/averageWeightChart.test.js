import { describe, it, expect, vi } from 'vitest'
import { mount } from '@vue/test-utils'

vi.mock('vue-chartjs', () => ({
    Line: {
        name: 'Line',
        props: { data: { type: Object, default: null }, options: { type: Object, default: null } },
        template: '<div />',
    },
}))

const AverageWeightChart = (await import('@/Components/Stats/AverageWeightChart.vue')).default

const line = (wrapper) => wrapper.findComponent({ name: 'Line' })

const points = [
    { date: '01/03', weight: 62.5 },
    { date: '08/03', weight: 65 },
    { date: '15/03', weight: 67.5 },
]

describe('AverageWeightChart', () => {
    it('garde chaque charge en face de sa date, dans l’ordre reçu', () => {
        const wrapper = mount(AverageWeightChart, { props: { data: points } })

        expect(line(wrapper).props('data').labels).toEqual(['01/03', '08/03', '15/03'])
        expect(line(wrapper).props('data').datasets[0].data).toEqual([62.5, 65, 67.5])
    })

    it('annonce des kilos dans l’infobulle', () => {
        const wrapper = mount(AverageWeightChart, { props: { data: points } })

        expect(
            line(wrapper)
                .props('options')
                .plugins.tooltip.callbacks.label({ parsed: { y: 62.5 } }),
        ).toBe('62.5 kg')
    })

    it('n’écrase pas l’écart en partant de zéro', () => {
        const wrapper = mount(AverageWeightChart, { props: { data: points } })

        // Une charge moyenne évolue de quelques kilos : forcer l’axe à zéro
        // aplatit la courbe en ligne droite.
        expect(line(wrapper).props('options').scales.y.beginAtZero).toBeUndefined()
    })

    it('suit la série quand la période change', async () => {
        const wrapper = mount(AverageWeightChart, { props: { data: points } })

        // Une visite Inertia réutilise l’instance : une série figée au setup
        // afficherait encore la période précédente.
        await wrapper.setProps({ data: [{ date: '22/03', weight: 70 }] })

        expect(line(wrapper).props('data').labels).toEqual(['22/03'])
        expect(line(wrapper).props('data').datasets[0].data).toEqual([70])
    })

    it('ne trace aucun point sans donnée', () => {
        const wrapper = mount(AverageWeightChart, { props: { data: [] } })

        expect(line(wrapper).props('data').labels).toEqual([])
        expect(line(wrapper).props('data').datasets[0].data).toEqual([])
    })
})
