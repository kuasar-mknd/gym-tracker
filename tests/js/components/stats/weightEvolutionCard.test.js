import { describe, it, expect, vi, beforeAll } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'

/**
 * Deferred is replaced by a component that always renders its default slot and
 * exposes the prop key it was told to wait for. Without a page context the real
 * one would hold the card on its skeleton for ever, and the key it is given is
 * itself a piece of logic: the wrong one never resolves.
 */
vi.mock('@inertiajs/vue3', () => ({
    Deferred: {
        name: 'Deferred',
        props: { data: { type: [String, Array], required: true } },
        template: '<div :data-deferred-key="data"><slot /></div>',
    },
    Link: { name: 'Link', props: ['href'], template: '<a :href="href"><slot /></a>' },
}))

vi.mock('@/Components/Stats/WeightHistoryChart.vue', () => ({
    // defineAsyncComponent only unwraps `default` from something that says it
    // is a module, so the mock has to say so too.
    __esModule: true,
    default: { name: 'WeightHistoryChart', props: ['data'], template: '<div class="weight-chart" />' },
}))

const WeightEvolutionCard = (await import('@/Components/Stats/WeightEvolutionCard.vue')).default

beforeAll(() => {
    globalThis.route = (name) => `/${name}`
})

/** The chart is an async component, so the slot content lands a tick later. */
const mountCard = async (props) => {
    const wrapper = mount(WeightEvolutionCard, { props, global: { mocks: { route: globalThis.route } } })

    await flushPromises()

    return wrapper
}

const badge = (card) => card.find('div.rounded-full')

describe('WeightEvolutionCard headline', () => {
    it('shows the latest weight it was given', async () => {
        const card = await mountCard({ latestWeight: '82.5', weightHistory: [] })

        expect(card.text()).toContain('82.5')
        expect(card.text()).toContain('kg')
    })

    it('shows a dash rather than a bare unit when nothing has been weighed', async () => {
        const card = await mountCard({ latestWeight: null, weightHistory: [] })

        expect(card.text()).toContain('—')
    })
})

describe('WeightEvolutionCard trend badge', () => {
    it('points down, in green, when the weight dropped', async () => {
        const card = await mountCard({ latestWeight: '82.5', weightChange: -1.2 })

        expect(badge(card).text()).toContain('trending_down')
        expect(badge(card).classes()).toContain('text-emerald-600')
        expect(badge(card).classes()).not.toContain('text-red-600')
    })

    it('points up, in red, when the weight climbed', async () => {
        const card = await mountCard({ latestWeight: '82.5', weightChange: 1.2 })

        expect(badge(card).text()).toContain('trending_up')
        expect(badge(card).classes()).toContain('text-red-600')
        expect(badge(card).classes()).not.toContain('text-emerald-600')
    })

    it('signs the gain, and leaves the loss its own minus', async () => {
        const gained = await mountCard({ latestWeight: '82.5', weightChange: 1.2 })
        const lost = await mountCard({ latestWeight: '82.5', weightChange: -1.2 })

        expect(gained.text()).toContain('+1.2 kg')
        expect(lost.text()).toContain('-1.2 kg')
        expect(lost.text()).not.toContain('+')
    })

    it('claims no trend at all when the weight has not moved, or is not known to have', async () => {
        const flat = await mountCard({ latestWeight: '82.5', weightChange: 0 })
        const unknown = await mountCard({ latestWeight: '82.5' })

        expect(badge(flat).exists()).toBe(false)
        expect(flat.text()).not.toContain('trending')
        expect(badge(unknown).exists()).toBe(false)
    })
})

describe('WeightEvolutionCard chart', () => {
    it('hands the history it was given to the chart', async () => {
        const history = [
            { date: '01/07', weight: 83.1 },
            { date: '08/07', weight: 82.5 },
        ]
        const card = await mountCard({ latestWeight: '82.5', weightHistory: history })

        expect(card.findComponent({ name: 'WeightHistoryChart' }).props('data')).toEqual(history)
        expect(card.text()).not.toContain('Pas encore de données de poids')
    })

    it('says there is no weight data rather than drawing an empty chart', async () => {
        const empty = await mountCard({ latestWeight: null, weightHistory: [] })
        const missing = await mountCard({ latestWeight: null })

        expect(empty.findComponent({ name: 'WeightHistoryChart' }).exists()).toBe(false)
        expect(empty.text()).toContain('Pas encore de données de poids')
        // `weightHistory?.length > 0`: the prop is absent on a first paint, and
        // reading .length off it unguarded would blank the whole card.
        expect(missing.findComponent({ name: 'WeightHistoryChart' }).exists()).toBe(false)
        expect(missing.text()).toContain('Pas encore de données de poids')
    })

    /**
     * The card is mounted both on the dashboard, where the body stats arrive as
     * their own deferred prop, and on a page that passes them under deferredData.
     * Waiting on a key the page never sends leaves the skeleton up for good.
     */
    it('waits on the deferred prop the page actually sends', async () => {
        const dashboard = await mountCard({ latestWeight: '82.5', weightHistory: [] })
        const scoped = await mountCard({ latestWeight: '82.5', weightHistory: [], deferredData: { weightHistory: [] } })

        expect(dashboard.find('[data-deferred-key]').attributes('data-deferred-key')).toBe('bodyStats')
        expect(scoped.find('[data-deferred-key]').attributes('data-deferred-key')).toBe('deferredData')
    })

    it('leads to the page where the measurement is taken', async () => {
        const card = await mountCard({ latestWeight: '82.5', weightHistory: [] })

        expect(card.find('a').attributes('href')).toBe('/body-measurements.index')
    })
})
