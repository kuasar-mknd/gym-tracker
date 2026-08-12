import { describe, it, expect, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'

vi.mock('vue-chartjs', async () => (await import('./chartRecorder.js')).chartRecorders())

/**
 * Inertia's <Deferred> waits on the page prop it is given before rendering its
 * default slot. Replaced here by a recorder that renders the slot immediately
 * and keeps the prop, because the prop is the thing worth asserting: pick the
 * wrong key and the card waits forever on data the page never sends.
 */
vi.mock('@inertiajs/vue3', () => ({
    Deferred: {
        name: 'Deferred',
        props: { data: { type: [String, Array], default: null } },
        template: '<div><slot /></div>',
    },
}))

/*
 * The chart is a defineAsyncComponent behind a dynamic import. Loaded here
 * first, the card's own import resolves from the module cache within the tick
 * flushPromises awaits — otherwise the slot is still a comment node and the
 * assertions below would depend on a timer.
 */
await import('@/Components/Stats/MuscleDistributionChart.vue')

const MuscleDistributionCard = (await import('@/Components/Stats/MuscleDistributionCard.vue')).default

const distribution = [
    { category: 'Pectoraux', volume: 12000 },
    { category: 'Dos', volume: 9500 },
]

const mountCard = async (props) => {
    const wrapper = mount(MuscleDistributionCard, { props })

    // The chart is a defineAsyncComponent; without this it is still a comment node.
    await flushPromises()

    return wrapper
}

describe('MuscleDistributionCard', () => {
    it('passe la répartition au graphique quand il y en a une', async () => {
        const wrapper = await mountCard({ muscleDistribution: distribution })

        const chart = wrapper.findComponent({ name: 'MuscleDistributionChart' })

        expect(chart.exists()).toBe(true)
        expect(chart.props('data')).toEqual(distribution)
        expect(wrapper.text()).not.toContain('Données de répartition indisponibles')
    })

    it('annonce l’absence de données plutôt qu’un camembert vide', async () => {
        const wrapper = await mountCard({ muscleDistribution: [] })

        expect(wrapper.findComponent({ name: 'MuscleDistributionChart' }).exists()).toBe(false)
        expect(wrapper.text()).toContain('Données de répartition indisponibles')
    })

    it('tient l’état vide quand la page n’envoie rien du tout', async () => {
        // muscleDistribution has no default: an unresolved deferred prop arrives
        // as undefined, and `undefined.length` would take the page down.
        const wrapper = await mountCard({})

        expect(wrapper.findComponent({ name: 'MuscleDistributionChart' }).exists()).toBe(false)
        expect(wrapper.text()).toContain('Données de répartition indisponibles')
    })

    it('attend la clé différée que la page lui annonce', async () => {
        const wrapper = await mountCard({ muscleDistribution: distribution, deferredData: { performance: {} } })

        expect(wrapper.findComponent({ name: 'Deferred' }).props('data')).toBe('deferredData')
    })

    it('retombe sur performanceStats quand la page ne groupe pas ses props différées', async () => {
        const wrapper = await mountCard({ muscleDistribution: distribution })

        expect(wrapper.findComponent({ name: 'Deferred' }).props('data')).toBe('performanceStats')
    })
})
