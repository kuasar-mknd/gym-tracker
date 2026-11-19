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

    describe('couleurs', () => {
        const dataset = () => chartOf([{ day_label: 'Lun', volume: 1200 }]).props('data').datasets[0]

        it('trace la ligne d’une seule couleur, l’orange, sans dégradé', () => {
            expect(dataset().borderColor).toBe(jeton('accent-primary'))
            expect(dataset().pointBorderColor).toBe(jeton('accent-primary'))
        })

        it('remplit d’un aplat léger de la même couleur', () => {
            expect(dataset().backgroundColor).toBe(jetonTransparent('accent-primary', 0.08))
        })
    })
})
