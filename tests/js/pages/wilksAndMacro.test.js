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
        // Reactive on purpose: both pages gate their save behind a computed
        // reading these fields, and a plain object never invalidates it — the
        // button would stay disabled no matter what the test typed.
        useForm: (data) => {
            form = reactive({ ...data, processing: false, errors: {}, post, reset: vi.fn() })

            return form
        },
    }
})

const haptic = vi.fn()
vi.mock('@/composables/useHaptics', () => ({ triggerHaptic: (...a) => haptic(...a) }))

import WilksCalculator from '@/Pages/Tools/WilksCalculator.vue'
import MacroCalculator from '@/Pages/Tools/MacroCalculator.vue'
import { passesSlot } from './pageStubs'

beforeAll(() => {
    globalThis.route = (name, params) => `/${name}/${JSON.stringify(params ?? '')}`
})

beforeEach(() => {
    vi.clearAllMocks()
})

const mountPage = (page, props) =>
    mount(page, {
        props,
        global: {
            mocks: { route: globalThis.route },
            stubs: { AuthenticatedLayout: passesSlot, GlassCard: passesSlot },
        },
    })

const saveButton = (wrapper) => wrapper.findAll('button').find((button) => button.text().includes('Enregistrer'))

/**
 * Both formulas live in Utils/formulas and are tested there. What these two
 * pages add is the gate in front of the save and the way the number is dressed
 * for the screen — and a save that goes out on half a form comes back 422,
 * which on both pages used to be silent.
 */
describe('the Wilks page', () => {
    const props = { history: [] }

    it('will not send a score with only one of the two weights', async () => {
        const wrapper = mountPage(WilksCalculator, props)

        form.body_weight = 80
        await wrapper.vm.$nextTick()

        expect(saveButton(wrapper).attributes('disabled')).toBeDefined()

        wrapper.vm.saveScore()

        expect(post).not.toHaveBeenCalled()
    })

    it('sends once both weights are there', async () => {
        const wrapper = mountPage(WilksCalculator, props)

        form.body_weight = 80
        form.lifted_weight = 500
        await wrapper.vm.$nextTick()

        expect(saveButton(wrapper).attributes('disabled')).toBeUndefined()

        wrapper.vm.saveScore()

        expect(post).toHaveBeenCalledTimes(1)
    })

    it('shows the score to two decimals, no more', async () => {
        const wrapper = mountPage(WilksCalculator, props)

        form.body_weight = 80
        form.lifted_weight = 500
        await wrapper.vm.$nextTick()

        // The formula returns a long float; two decimals is this page's
        // presentation of it, and a raw 320.4517293... would read as noise.
        expect(wrapper.text()).toMatch(/\d+\.\d{2}(?!\d)/)
    })

    it('says so with a buzz when the server refuses', async () => {
        const wrapper = mountPage(WilksCalculator, props)

        form.body_weight = 80
        form.lifted_weight = 500
        wrapper.vm.saveScore()
        post.mock.calls[0][1].onError()

        // The isValid gate is looser than the server's rules, so a rejection is
        // reachable — and used to do nothing at all.
        expect(haptic).toHaveBeenCalledWith('error')
    })
})

describe('the macro page', () => {
    const props = { history: [] }

    it('will not send a calculation missing a measurement', async () => {
        const wrapper = mountPage(MacroCalculator, props)

        form.age = 30
        form.height = 180
        await wrapper.vm.$nextTick()

        // Weight is still empty: without it the basal rate is meaningless, and
        // the server rejects it anyway. This page hides the whole results block
        // rather than disabling the button, which is the stronger of the two —
        // there is no half-filled set of targets to misread.
        expect(saveButton(wrapper)).toBeUndefined()

        wrapper.vm.saveCalculation()

        expect(post).not.toHaveBeenCalled()
    })

    it('sends once age, height and weight are all there', async () => {
        const wrapper = mountPage(MacroCalculator, props)

        form.age = 30
        form.height = 180
        form.weight = 75
        await wrapper.vm.$nextTick()

        expect(saveButton(wrapper).attributes('disabled')).toBeUndefined()

        wrapper.vm.saveCalculation()

        expect(post).toHaveBeenCalledTimes(1)
    })

    it('demande avant de supprimer un calcul enregistré', async () => {
        const wrapper = mountPage(MacroCalculator, props)

        wrapper.vm.demanderSuppression({ id: 7, created_at: '2026-08-01T10:00:00Z' })
        await wrapper.vm.$nextTick()

        expect(routerDelete).not.toHaveBeenCalled()

        wrapper.vm.confirmerSuppression()

        expect(routerDelete).toHaveBeenCalledTimes(1)
    })

    it('ne supprime rien quand on annule', async () => {
        const wrapper = mountPage(MacroCalculator, props)

        wrapper.vm.demanderSuppression({ id: 7, created_at: '2026-08-01T10:00:00Z' })
        await wrapper.vm.$nextTick()

        wrapper.vm.annulerSuppression()
        wrapper.vm.confirmerSuppression()

        // `confirmerSuppression` sort tôt quand plus rien n'est en attente : un
        // appui sur « Supprimer » après une annulation ne doit rien relancer.
        expect(routerDelete).not.toHaveBeenCalled()
    })
})
