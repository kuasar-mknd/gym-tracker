import { describe, it, expect, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'

vi.mock('@inertiajs/vue3', () => ({
    Deferred: {
        name: 'Deferred',
        props: { data: { type: [String, Array], required: true } },
        template: '<div :data-deferred-key="data"><slot /></div>',
    },
}))

vi.mock('@/Components/Stats/WorkoutsPerMonthChart.vue', () => ({
    __esModule: true,
    default: { name: 'WorkoutsPerMonthChart', props: ['data'], template: '<div class="par-mois" />' },
}))

vi.mock('@/Components/Stats/WorkoutFrequencyChart.vue', () => ({
    __esModule: true,
    default: { name: 'WorkoutFrequencyChart', props: ['data'], template: '<div class="par-jour" />' },
}))

const RegulariteCard = (await import('@/Components/Stats/RegulariteCard.vue')).default

const monter = async (props) => {
    const wrapper = mount(RegulariteCard, { props })

    await flushPromises()

    return wrapper
}

describe('RegulariteCard', () => {
    it('donne à chaque graphique sa série : séances par mois, séances par jour', async () => {
        const parMois = [{ month: 'Juin', count: 3 }]
        const parJour = [{ day: 'Lun', count: 2 }]
        const carte = await monter({ regularite: { monthly_frequency: parMois, day_of_week_frequency: parJour } })

        expect(carte.findComponent({ name: 'WorkoutsPerMonthChart' }).props('data')).toEqual(parMois)
        expect(carte.findComponent({ name: 'WorkoutFrequencyChart' }).props('data')).toEqual(parJour)
        expect(carte.text()).toContain('Régularité')
        expect(carte.text()).not.toContain('Séances par mois et par jour')
    })

    it('attend la clé groupée de la page et ne dessine rien tant que la série manque', async () => {
        const carte = await monter({})

        expect(carte.findComponent({ name: 'Deferred' }).props('data')).toBe('deferredData')
        expect(carte.findComponent({ name: 'WorkoutsPerMonthChart' }).exists()).toBe(false)
        expect(carte.findComponent({ name: 'WorkoutFrequencyChart' }).exists()).toBe(false)
    })
})
