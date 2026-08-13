import { describe, it, expect, vi, beforeAll, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'

const post = vi.fn()
let form

vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<div />' },
    router: { delete: vi.fn() },
    useForm: (data) => {
        form = { ...data, processing: false, errors: {}, post, reset: vi.fn() }

        return form
    },
}))

import WarmupCalculator from '@/Pages/Tools/WarmupCalculator.vue'
import { passesSlot } from './pageStubs'

beforeAll(() => {
    globalThis.route = (name) => `/${name}`
})

beforeEach(() => {
    vi.clearAllMocks()
})

const preference = {
    bar_weight: 20,
    rounding_increment: 2.5,
    steps: [
        { percent: 40, reps: 8, label: 'Barre' },
        { percent: 60, reps: 5, label: '' },
    ],
}

// Deep-copied per mount: a shallow spread hands every test the same `steps`
// array, so addStep in one leaks into the next and the failure looks like the
// component's rather than the fixture's.
const mountPage = (overrides = {}) =>
    mount(WarmupCalculator, {
        props: { preference: structuredClone({ ...preference, ...overrides }) },
        global: {
            mocks: { route: globalThis.route },
            stubs: {
                AuthenticatedLayout: passesSlot,
                GlassCard: passesSlot,
            },
        },
    })

const buttonSaying = (wrapper, text) => wrapper.findAll('button').find((button) => button.text().includes(text))

describe('editing the warm-up ladder', () => {
    it('adds a step ready to be edited rather than an empty one', async () => {
        const wrapper = mountPage()

        await buttonSaying(wrapper, 'Ajouter un palier').trigger('click')

        expect(form.steps).toHaveLength(3)
        expect(form.steps.at(-1)).toEqual({ percent: 50, reps: 5, label: '' })
    })

    it('refuses to remove the last step', async () => {
        const wrapper = mountPage({ steps: [{ percent: 50, reps: 5, label: '' }] })

        // A ladder with no rungs is not a warm-up, and the page offers no way
        // back from it — the guard is the only thing standing there.
        wrapper.vm.removeStep(0)

        expect(form.steps).toHaveLength(1)
    })

    it('removes the step that was asked for, not the last one', () => {
        const wrapper = mountPage()

        wrapper.vm.removeStep(0)

        expect(form.steps).toEqual([{ percent: 60, reps: 5, label: '' }])
    })
})

describe('saving the preferences', () => {
    it('shows what the server objected to', async () => {
        const wrapper = mountPage()

        await buttonSaying(wrapper, 'Sauvegarder').trigger('click')
        post.mock.calls[0][1].onError({ 'steps.0.percent': 'Le pourcentage ne peut dépasser 100.' })
        await wrapper.vm.$nextTick()

        // The server validates every step. This handler used to be an onSuccess
        // holding a comment and nothing else, so a rejected ladder looked
        // exactly like a saved one.
        expect(wrapper.find('[dusk="warmup-save-error"]').text()).toBe('Le pourcentage ne peut dépasser 100.')
    })

    it('still says something when the rejection carries no message', async () => {
        const wrapper = mountPage()

        await buttonSaying(wrapper, 'Sauvegarder').trigger('click')
        post.mock.calls[0][1].onError({})
        await wrapper.vm.$nextTick()

        expect(wrapper.find('[dusk="warmup-save-error"]').text()).toContain('a échoué')
    })

    it('clears the previous complaint before trying again', async () => {
        const wrapper = mountPage()

        await buttonSaying(wrapper, 'Sauvegarder').trigger('click')
        post.mock.calls[0][1].onError({ bar_weight: 'Trop lourd.' })
        await wrapper.vm.$nextTick()

        await buttonSaying(wrapper, 'Sauvegarder').trigger('click')
        await wrapper.vm.$nextTick()

        // Leaving the old error under a fresh attempt reads as a new refusal.
        expect(wrapper.find('[dusk="warmup-save-error"]').exists()).toBe(false)
    })
})
