import { describe, it, expect, vi } from 'vitest'
import { mount } from '@vue/test-utils'

vi.mock('vue-chartjs', () => ({
    Line: {
        name: 'Line',
        props: { data: { type: Object, default: null }, options: { type: Object, default: null } },
        template: '<div />',
    },
}))

const RecentWorkoutsTimelineChart = (await import('@/Components/Stats/RecentWorkoutsTimelineChart.vue')).default

const line = (wrapper) => wrapper.findComponent({ name: 'Line' })

/**
 * Tel que l’API le renvoie : la séance la plus récente en premier. Le composant
 * inverse pour se lire dans le sens du temps, puis inverse une seconde fois
 * dans l’infobulle — c’est là que se cache la confusion de séance.
 *
 * La durée nulle de la séance en cours est déjà couverte par chartAxes.test.js.
 */
const workouts = [
    { name: 'Jambes', started_at: '2026-03-05T12:00:00Z', ended_at: '2026-03-05T13:30:00Z' },
    { name: 'Dos', started_at: '2026-03-03T12:00:00Z', ended_at: '2026-03-03T13:00:00Z' },
    { name: 'Pecs', started_at: '2026-02-28T12:00:00Z', ended_at: '2026-02-28T12:45:00Z' },
]

describe('RecentWorkoutsTimelineChart', () => {
    it('se lit de gauche à droite dans le sens du temps', () => {
        const wrapper = mount(RecentWorkoutsTimelineChart, { props: { data: workouts } })

        expect(line(wrapper).props('data').labels).toEqual(['28/02', '03/03', '05/03'])
    })

    it('garde chaque durée en face de sa date', () => {
        const wrapper = mount(RecentWorkoutsTimelineChart, { props: { data: workouts } })

        expect(line(wrapper).props('data').datasets[0].data).toEqual([45, 60, 90])
    })

    it('nomme la séance survolée, pas son symétrique', () => {
        const wrapper = mount(RecentWorkoutsTimelineChart, { props: { data: workouts } })
        const title = line(wrapper).props('options').plugins.tooltip.callbacks.title

        // Le point 0 est le plus ancien après inversion : oublier de réinverser
        // ici annonce « Jambes » sur la séance de pecs.
        expect(title([{ dataIndex: 0 }])).toBe('Pecs')
        expect(title([{ dataIndex: 2 }])).toBe('Jambes')
    })

    it('appelle « Séance » celle qui n’a pas de nom', () => {
        const wrapper = mount(RecentWorkoutsTimelineChart, {
            props: { data: [{ name: null, started_at: '2026-03-05T12:00:00Z', ended_at: '2026-03-05T13:00:00Z' }] },
        })

        expect(
            line(wrapper)
                .props('options')
                .plugins.tooltip.callbacks.title([{ dataIndex: 0 }]),
        ).toBe('Séance')
    })

    it('affiche la durée en minutes dans l’infobulle', () => {
        const wrapper = mount(RecentWorkoutsTimelineChart, { props: { data: workouts } })

        expect(
            line(wrapper)
                .props('options')
                .plugins.tooltip.callbacks.label({ parsed: { y: 90 } }),
        ).toBe('90 minutes')
    })

    it('ne renverse pas le tableau reçu du parent', () => {
        const data = [...workouts]

        mount(RecentWorkoutsTimelineChart, { props: { data } })

        expect(data.map((w) => w.name)).toEqual(['Jambes', 'Dos', 'Pecs'])
    })
})
