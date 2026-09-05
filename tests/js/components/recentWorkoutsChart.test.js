import { describe, it, expect, vi } from 'vitest'
import { mount } from '@vue/test-utils'

vi.mock('vue-chartjs', () => ({
    Bar: {
        name: 'Bar',
        props: { data: { type: Object, default: null }, options: { type: Object, default: null } },
        template: '<div />',
    },
}))

const RecentWorkoutsChart = (await import('@/Components/Stats/RecentWorkoutsChart.vue')).default

const bar = (wrapper) => wrapper.findComponent({ name: 'Bar' })

/** Tel que l’API le renvoie : la séance la plus récente en premier. */
const workouts = [
    { started_at: '2026-03-05T12:00:00Z', workout_volume: 8200 },
    { started_at: '2026-03-03T12:00:00Z', workout_volume: 7100 },
    { started_at: '2026-02-28T12:00:00Z', workout_volume: 6400 },
]

describe('RecentWorkoutsChart', () => {
    it('se lit de gauche à droite dans le sens du temps', () => {
        const wrapper = mount(RecentWorkoutsChart, { props: { data: workouts } })

        expect(bar(wrapper).props('data').labels).toEqual(['28/02', '03/03', '05/03'])
    })

    it('garde chaque volume en face de sa séance', () => {
        const wrapper = mount(RecentWorkoutsChart, { props: { data: workouts } })

        // Inverser les labels sans inverser les volumes attribue les 8 200 kg
        // du 5 mars à la séance du 28 février.
        expect(bar(wrapper).props('data').datasets[0].data).toEqual([6400, 7100, 8200])
    })

    it('ne renverse pas le tableau reçu du parent', () => {
        const data = [...workouts]

        mount(RecentWorkoutsChart, { props: { data } })

        // `props.data.reverse()` sans copie réordonne la liste que la page
        // affiche juste au-dessous.
        expect(data.map((w) => w.started_at)).toEqual([
            '2026-03-05T12:00:00Z',
            '2026-03-03T12:00:00Z',
            '2026-02-28T12:00:00Z',
        ])
    })

    it('compte une séance sans volume enregistré comme zéro kilo', () => {
        const wrapper = mount(RecentWorkoutsChart, {
            props: { data: [{ started_at: '2026-03-05T12:00:00Z', workout_volume: null }] },
        })

        expect(bar(wrapper).props('data').datasets[0].data).toEqual([0])
    })

    it('affiche le volume en kilos dans l’infobulle', () => {
        const wrapper = mount(RecentWorkoutsChart, { props: { data: workouts } })

        expect(
            bar(wrapper)
                .props('options')
                .plugins.tooltip.callbacks.label({ parsed: { y: 7100 } }),
        ).toBe('7100 kg')
    })
})
