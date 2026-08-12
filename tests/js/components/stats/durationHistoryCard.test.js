import { describe, it, expect, vi } from 'vitest'
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
}))

vi.mock('@/Components/Stats/DurationHistoryChart.vue', () => ({
    // defineAsyncComponent only unwraps `default` from something that says it
    // is a module, so the mock has to say so too.
    __esModule: true,
    default: { name: 'DurationHistoryChart', props: ['data'], template: '<div class="duration-chart" />' },
}))

const DurationHistoryCard = (await import('@/Components/Stats/DurationHistoryCard.vue')).default

/** The chart is an async component, so the slot content lands a tick later. */
const mountCard = async (props) => {
    const wrapper = mount(DurationHistoryCard, { props })

    await flushPromises()

    return wrapper
}

const chartIn = (card) => card.findComponent({ name: 'DurationHistoryChart' })

describe('DurationHistoryCard', () => {
    it('hands the durations it was given to the chart', async () => {
        const history = [
            { date: '01/07', duration: 62 },
            { date: '03/07', duration: 48 },
        ]
        const card = await mountCard({ durationHistory: history })

        expect(chartIn(card).props('data')).toEqual(history)
        expect(card.text()).not.toContain('Pas encore de données de durée')
    })

    it('says there is no duration data rather than drawing an empty chart', async () => {
        const empty = await mountCard({ durationHistory: [] })
        const missing = await mountCard({})

        expect(chartIn(empty).exists()).toBe(false)
        expect(empty.text()).toContain('Pas encore de données de durée')
        expect(empty.text()).toContain('timer_off')
        // The prop is absent on a first paint: reading .length off it unguarded
        // would blank the whole card.
        expect(chartIn(missing).exists()).toBe(false)
        expect(missing.text()).toContain('Pas encore de données de durée')
    })

    it('still names what the card holds while it is empty', async () => {
        const empty = await mountCard({ durationHistory: [] })

        expect(empty.text()).toContain('Durée des Séances')
        expect(empty.text()).toContain('30 dernières séances')
    })

    /**
     * The card is mounted both on the dashboard, where the session stats arrive
     * as their own deferred prop, and on a page that passes them under
     * deferredData. Waiting on a key the page never sends leaves the skeleton up
     * for good.
     */
    it('waits on the deferred prop the page actually sends', async () => {
        const dashboard = await mountCard({ durationHistory: [] })
        const scoped = await mountCard({ durationHistory: [], deferredData: { durationHistory: [] } })

        expect(dashboard.find('[data-deferred-key]').attributes('data-deferred-key')).toBe('performanceStats')
        expect(scoped.find('[data-deferred-key]').attributes('data-deferred-key')).toBe('deferredData')
    })
})
