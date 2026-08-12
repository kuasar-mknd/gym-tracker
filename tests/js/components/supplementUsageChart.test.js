import { describe, it, expect, vi } from 'vitest'
import { mount } from '@vue/test-utils'

vi.mock('vue-chartjs', () => ({
    Bar: {
        name: 'Bar',
        props: { data: { type: Object, default: null }, options: { type: Object, default: null } },
        template: '<div />',
    },
}))

const SupplementUsageChart = (await import('@/Components/Stats/SupplementUsageChart.vue')).default

const bar = (wrapper) => wrapper.findComponent({ name: 'Bar' })

const days = [
    { date: '01/03', count: 2 },
    { date: '02/03', count: 0 },
    { date: '03/03', count: 3 },
]

describe('SupplementUsageChart', () => {
    it('trace une barre par jour, dans l’ordre reçu', () => {
        const wrapper = mount(SupplementUsageChart, { props: { data: days } })

        expect(bar(wrapper).props('data').labels).toEqual(['01/03', '02/03', '03/03'])
    })

    it('garde le jour sans dose à zéro plutôt que de le sauter', () => {
        const wrapper = mount(SupplementUsageChart, { props: { data: days } })

        // Filtrer les zéros décalerait toutes les barres suivantes d’un jour.
        expect(bar(wrapper).props('data').datasets[0].data).toEqual([2, 0, 3])
    })

    it('compte les doses en entiers sur l’axe', () => {
        const wrapper = mount(SupplementUsageChart, { props: { data: days } })
        const ticks = bar(wrapper).props('options').scales.y.ticks

        // Une demi-dose n’existe pas : sans ce pas, Chart.js gradue « 0,5 ; 1 ;
        // 1,5 » dès que le maximum de la semaine est bas.
        expect(ticks.stepSize).toBe(1)
        expect(ticks.precision).toBe(0)
        expect(bar(wrapper).props('options').scales.y.beginAtZero).toBe(true)
    })

    it('libelle l’infobulle en doses', () => {
        const wrapper = mount(SupplementUsageChart, { props: { data: days } })

        expect(bar(wrapper).props('options').plugins.tooltip.callbacks.label({ raw: 3 })).toBe('3 doses')
    })

    it('suit la série quand la période change', async () => {
        const wrapper = mount(SupplementUsageChart, { props: { data: days } })

        await wrapper.setProps({ data: [{ date: '10/03', count: 1 }] })

        expect(bar(wrapper).props('data').labels).toEqual(['10/03'])
        expect(bar(wrapper).props('data').datasets[0].data).toEqual([1])
    })
})
