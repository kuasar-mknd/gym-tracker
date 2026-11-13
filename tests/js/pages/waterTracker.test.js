import { describe, it, expect, vi, beforeAll, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'

const post = vi.fn()
const routerDelete = vi.fn()
let form

vi.mock('@inertiajs/vue3', async () => {
    const { reactive } = await vi.importActual('vue')

    return {
        Head: { template: '<div />' },
        router: { delete: (...args) => routerDelete(...args) },
        useForm: (data) => {
            form = reactive({ ...data, processing: false, errors: {}, post, reset: vi.fn() })

            return form
        },
    }
})

const haptic = vi.fn()
vi.mock('@/composables/useHaptics', () => ({ triggerHaptic: (...a) => haptic(...a) }))
vi.mock('@/Components/Stats/WaterHistoryChart.vue', () => ({
    __esModule: true,
    default: { name: 'WaterHistoryChart', props: ['data'], template: '<div />' },
}))

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
                    props: ['disabled', 'loading'],
                    template: '<button :disabled="disabled" :data-loading="loading"><slot /></button>',
                },
                GlassInput: { template: '<input />' },
                ConfirmDialog: { props: ['description'], template: '<p class="dialogue">{{ description }}</p>' },
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
        expect(wrapper.text()).toContain('100 %')
        expect(ringOffset(wrapper)).toBe(0)
    })

    it('leaves the ring empty before the first glass', () => {
        const wrapper = mountPage({ todayTotal: 0, goal: 2500 })

        expect(ringOffset(wrapper)).toBeCloseTo(2 * Math.PI * 45, 5)
    })

    it('fills exactly half the ring at half the goal', () => {
        const wrapper = mountPage({ todayTotal: 1250, goal: 2500 })

        expect(wrapper.text()).toContain('50 %')
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

describe('les retouches de lecture', () => {
    const boutonDeLaQuantiteSaisie = (wrapper) => wrapper.findAll('button').find((button) => button.text() === 'add')

    it('ne fait tourner que le bouton de la quantité saisie', async () => {
        const wrapper = mountPage()

        await wrapper.findAll('button')[0].trigger('click')
        form.processing = true
        await wrapper.vm.$nextTick()

        expect(boutonDeLaQuantiteSaisie(wrapper).attributes('data-loading')).toBe('false')

        wrapper.vm.addWater(330, true)
        post.mock.calls.at(-1)[1].onStart()
        await wrapper.vm.$nextTick()

        expect(boutonDeLaQuantiteSaisie(wrapper).attributes('data-loading')).toBe('true')
    })

    it('désactive le « + » pendant l’envoi d’une tuile, même avec une quantité saisie', async () => {
        const wrapper = mountPage()

        wrapper.vm.customAmount = '330'
        await wrapper.vm.$nextTick()
        expect(boutonDeLaQuantiteSaisie(wrapper).attributes('disabled')).toBeUndefined()

        form.processing = true
        await wrapper.vm.$nextTick()

        expect(boutonDeLaQuantiteSaisie(wrapper).attributes('disabled')).toBeDefined()
    })

    it('propose une invite que le suffixe « ML » ne coupe pas', () => {
        const wrapper = mountPage()

        expect(wrapper.get('input').attributes('placeholder')).toBe('Ex. 330')
    })

    it('accorde « retirés » dans la confirmation de suppression', async () => {
        const wrapper = mountPage()

        wrapper.vm.demanderSuppression({ id: 1, amount: 250 })
        await wrapper.vm.$nextTick()

        expect(wrapper.get('.dialogue').text()).toContain('seront retirés de ton total du jour')
    })
})
