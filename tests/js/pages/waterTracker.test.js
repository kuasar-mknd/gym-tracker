import { describe, it, expect, vi, beforeAll, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'

const post = vi.fn()
const routerDelete = vi.fn()
let form

vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<div />' },
    router: { delete: (...args) => routerDelete(...args) },
    useForm: (data) => {
        form = { ...data, processing: false, errors: {}, post, reset: vi.fn() }

        return form
    },
}))

const haptic = vi.fn()
vi.mock('@/composables/useHaptics', () => ({ triggerHaptic: (...a) => haptic(...a) }))

import WaterTracker from '@/Pages/Tools/WaterTracker.vue'
import { passesSlot } from './pageStubs'

beforeAll(() => {
    globalThis.route = (name, params) => `/${name}/${JSON.stringify(params ?? '')}`
})

beforeEach(() => {
    vi.clearAllMocks()
})

const mountPage = (props = {}) =>
    mount(WaterTracker, {
        props: { logs: [], todayTotal: 0, history: [], ...props },
        global: {
            mocks: { route: globalThis.route },
            stubs: {
                AuthenticatedLayout: passesSlot,
                GlassCard: passesSlot,
                // `disabled` is forwarded on purpose: it is half of what stops
                // an empty custom amount from being logged, and a stub that
                // drops it would let a test pass on the other half alone.
                GlassButton: {
                    props: ['disabled'],
                    template: '<button :disabled="disabled"><slot /></button>',
                },
                GlassInput: { template: '<input />' },
            },
        },
    })

/** The progress ring's stroke-dashoffset, which is how the fill is drawn. */
const ringOffset = (wrapper) => {
    const circle = wrapper.findAll('circle').at(-1)

    return parseFloat(circle.attributes('stroke-dashoffset'))
}

describe('the daily progress ring', () => {
    it('stops at full rather than overshooting on a big day', () => {
        const wrapper = mountPage({ todayTotal: 5000, goal: 2500 })

        // 200 % of the goal would wind the ring past its own start and draw a
        // negative offset, which renders as an empty circle — the opposite of
        // what the day deserves.
        expect(wrapper.text()).toContain('100%')
        expect(ringOffset(wrapper)).toBe(0)
    })

    it('leaves the ring empty before the first glass', () => {
        const wrapper = mountPage({ todayTotal: 0, goal: 2500 })

        expect(ringOffset(wrapper)).toBeCloseTo(2 * Math.PI * 45, 5)
    })

    it('fills exactly half the ring at half the goal', () => {
        const wrapper = mountPage({ todayTotal: 1250, goal: 2500 })

        expect(wrapper.text()).toContain('50%')
        expect(ringOffset(wrapper)).toBeCloseTo(Math.PI * 45, 5)
    })
})

describe('logging a glass', () => {
    it('sends the moment it was drunk, not only the amount', async () => {
        const wrapper = mountPage()

        await wrapper.findAll('button')[0].trigger('click')

        // consumed_at is required server-side. Leaving it out made every log
        // 422 with nothing on screen, so the tracker silently saved nothing.
        expect(form.amount).toBe(250)
        expect(form.consumed_at).not.toBe('')
        expect(Number.isNaN(Date.parse(form.consumed_at))).toBe(false)
        expect(post).toHaveBeenCalledTimes(1)
    })

    it('says so with a buzz when the server refuses', async () => {
        const wrapper = mountPage()

        await wrapper.findAll('button')[0].trigger('click')
        post.mock.calls[0][1].onError()

        expect(haptic).toHaveBeenCalledWith('error')
    })

    it('sends nothing at all for an empty custom amount', async () => {
        const wrapper = mountPage()

        // The custom-amount button is the one carrying the `add` glyph; the
        // three before it are the fixed 250/500/1000 shortcuts.
        const customButton = wrapper.findAll('button').find((button) => button.text() === 'add')

        expect(customButton.attributes('disabled')).toBeDefined()

        await customButton.trigger('click')

        // Both halves matter. The attribute is what a user runs into, and the
        // guard inside addWater is what catches a click that reaches it anyway
        // — a stub that dropped `disabled` used to let this pass on neither.
        expect(post).not.toHaveBeenCalled()

        wrapper.vm.addWater('')

        expect(post).not.toHaveBeenCalled()
    })
})
