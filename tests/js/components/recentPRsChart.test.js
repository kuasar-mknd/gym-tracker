import { describe, it, expect, vi } from 'vitest'
import { mount } from '@vue/test-utils'

vi.mock('vue-chartjs', () => ({
    Bar: {
        name: 'Bar',
        props: { data: { type: Object, default: null }, options: { type: Object, default: null } },
        template: '<div />',
    },
}))

const RecentPRsChart = (await import('@/Components/Stats/RecentPRsChart.vue')).default

const bar = (wrapper) => wrapper.findComponent({ name: 'Bar' })

const mountWith = (data) => mount(RecentPRsChart, { props: { data } })

describe('RecentPRsChart', () => {
    it('porte le nom de l’exercice et sa valeur, dans l’ordre reçu', () => {
        const wrapper = mountWith([
            { exercise: { name: 'Squat' }, value: 140, type: 'max_weight' },
            { exercise: { name: 'Dips' }, value: 95, type: 'max_weight' },
        ])

        expect(bar(wrapper).props('data').labels).toEqual(['Squat', 'Dips'])
        expect(bar(wrapper).props('data').datasets[0].data).toEqual([140, 95])
    })

    it('laisse intact un nom de quinze caractères', () => {
        // Quinze exactement : la troncature ne doit pas mordre sur la limite.
        const wrapper = mountWith([{ exercise: { name: 'Tirage Vertical' }, value: 200 }])

        expect(bar(wrapper).props('data').labels).toEqual(['Tirage Vertical'])
    })

    it('tronque au seizième caractère en gardant les douze premiers', () => {
        const wrapper = mountWith([
            { exercise: { name: 'Tirage Verticale' }, value: 80 },
            { exercise: { name: 'Développé couché incliné' }, value: 100 },
        ])

        expect(bar(wrapper).props('data').labels).toEqual(['Tirage Verti...', 'Développé co...'])
    })

    it('retombe sur « PR » quand l’exercice manque', () => {
        const wrapper = mountWith([{ exercise: null, value: 60 }, { value: 50 }])

        expect(bar(wrapper).props('data').labels).toEqual(['PR', 'PR'])
    })

    it('donne son unité à chaque barre, pas celle de la première', () => {
        const wrapper = mountWith([
            { exercise: { name: 'Squat' }, value: 140, type: 'max_weight' },
            { exercise: { name: 'Tractions' }, value: 12, type: 'max_volume_set' },
        ])

        const label = bar(wrapper).props('options').plugins.tooltip.callbacks.label

        // Le record de volume se compte en répétitions ; l’afficher en kg
        // annonce douze kilos de tractions.
        expect(label({ dataIndex: 0, parsed: { y: 140 } })).toBe('140 kg')
        expect(label({ dataIndex: 1, parsed: { y: 12 } })).toBe('12 reps')
    })

    it('ne trace aucune barre sans record', () => {
        const wrapper = mountWith([])

        expect(bar(wrapper).props('data').labels).toEqual([])
        expect(bar(wrapper).props('data').datasets[0].data).toEqual([])
    })
})
