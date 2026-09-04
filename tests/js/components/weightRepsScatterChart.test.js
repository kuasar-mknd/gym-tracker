import { describe, it, expect, vi } from 'vitest'
import { mount } from '@vue/test-utils'

vi.mock('vue-chartjs', () => ({
    Scatter: {
        name: 'Scatter',
        props: { data: { type: Object, default: null }, options: { type: Object, default: null } },
        template: '<div />',
    },
}))

const WeightRepsScatterChart = (await import('@/Components/Stats/WeightRepsScatterChart.vue')).default

const scatter = (wrapper) => wrapper.findComponent({ name: 'Scatter' })

const sets = [
    { x: 100, y: 5 },
    { x: 80, y: 10 },
]

describe('WeightRepsScatterChart', () => {
    it('place une série unique portant toutes les séries d’entraînement', () => {
        const wrapper = mount(WeightRepsScatterChart, { props: { data: sets } })
        const datasets = scatter(wrapper).props('data').datasets

        expect(datasets).toHaveLength(1)
        expect(datasets[0].data).toEqual(sets)
    })

    it('porte le poids en abscisse et les répétitions en ordonnée', () => {
        const wrapper = mount(WeightRepsScatterChart, { props: { data: sets } })
        const scales = scatter(wrapper).props('options').scales

        // Intervertir les deux axes lit « 5 kg × 100 reps » sur une série de
        // 100 kg à 5 répétitions.
        expect(scales.x.title.text).toBe('Poids (kg)')
        expect(scales.y.title.text).toBe('Répétitions')
    })

    it('lit le point dans le même sens dans l’infobulle', () => {
        const wrapper = mount(WeightRepsScatterChart, { props: { data: sets } })
        const label = scatter(wrapper).props('options').plugins.tooltip.callbacks.label

        expect(label({ parsed: { x: 100, y: 5 } })).toBe('100 kg × 5 reps')
    })

    it('ancre les répétitions à zéro', () => {
        const wrapper = mount(WeightRepsScatterChart, { props: { data: sets } })

        // Le nuage se lit par rapport à « aucune répétition » ; un axe flottant
        // exagère l’écart entre une série de 8 et une de 10.
        expect(scatter(wrapper).props('options').scales.y.beginAtZero).toBe(true)
    })

    it('quadrille le nuage dans les deux sens', () => {
        const wrapper = mount(WeightRepsScatterChart, { props: { data: sets } })
        const scales = scatter(wrapper).props('options').scales

        // Un point isolé ne se lit que par rapport à la grille : sans les
        // verticales, plus rien ne rattache un point à son poids en abscisse.
        expect(scales.x.grid.display).not.toBe(false)
        expect(scales.x.grid.color).toBe(scales.y.grid.color)
    })

    it('suit les points quand l’exercice change', async () => {
        const wrapper = mount(WeightRepsScatterChart, { props: { data: sets } })

        await wrapper.setProps({ data: [{ x: 60, y: 12 }] })

        expect(scatter(wrapper).props('data').datasets[0].data).toEqual([{ x: 60, y: 12 }])
    })
})
