import { describe, it, expect, vi, beforeAll, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'

const get = vi.fn()

vi.mock('axios', () => ({ default: { get: (...args) => get(...args) } }))

import ExerciseProgressCard from '@/Components/Stats/ExerciseProgressCard.vue'

beforeAll(() => {
    globalThis.route = (name, params) => `/${name}/${params?.exercise ?? ''}`
})

const exercises = [
    { id: 1, name: 'Développé couché' },
    { id: 2, name: 'Squat' },
]

const mountCard = () =>
    mount(ExerciseProgressCard, {
        props: { exercises },
        global: { stubs: { OneRepMaxChart: true } },
    })

const select = async (wrapper, exerciseId) => {
    wrapper.vm.selectedExercise = exerciseId
    await flushPromises()
}

beforeEach(() => {
    get.mockReset()
})

describe('ExerciseProgressCard', () => {
    it('renders the curve when the request succeeds', async () => {
        get.mockResolvedValue({ data: { progress: [{ date: '2026-07-01', oneRepMax: 100 }] } })

        const wrapper = mountCard()
        await select(wrapper, 1)

        expect(wrapper.find('[dusk="exercise-progress-error"]').exists()).toBe(false)
        expect(wrapper.text()).not.toContain('Pas assez de données')

        wrapper.unmount()
    })

    /**
     * The catch used to log and return. With no points assigned, the template
     * fell through to "Pas assez de données pour cet exercice" — a claim about
     * the exercise, when the truth is that the request failed.
     */
    it('says the request failed rather than blaming the exercise', async () => {
        get.mockRejectedValue(new Error('network'))

        const wrapper = mountCard()
        await select(wrapper, 1)

        expect(wrapper.find('[dusk="exercise-progress-error"]').exists()).toBe(true)
        expect(wrapper.text()).not.toContain('Pas assez de données')

        wrapper.unmount()
    })

    /**
     * The worst of the two: points were only assigned on success, so a failed
     * request left the previous exercise's curve on screen under the newly
     * selected exercise's name.
     */
    it('never shows the previous exercise data under a new selection', async () => {
        get.mockResolvedValue({ data: { progress: [{ date: '2026-07-01', oneRepMax: 100 }] } })

        const wrapper = mountCard()
        await select(wrapper, 1)

        expect(wrapper.findComponent({ name: 'OneRepMaxChart' }).exists()).toBe(true)

        get.mockRejectedValue(new Error('network'))
        await select(wrapper, 2)

        expect(wrapper.vm.exerciseProgressData).toEqual([])
        expect(wrapper.findComponent({ name: 'OneRepMaxChart' }).exists()).toBe(false)
        expect(wrapper.find('[dusk="exercise-progress-error"]').exists()).toBe(true)

        wrapper.unmount()
    })

    it('clears the failure when a retry succeeds', async () => {
        get.mockRejectedValue(new Error('network'))

        const wrapper = mountCard()
        await select(wrapper, 1)

        expect(wrapper.find('[dusk="exercise-progress-error"]').exists()).toBe(true)

        get.mockResolvedValue({ data: { progress: [{ date: '2026-07-01', oneRepMax: 100 }] } })
        await wrapper.find('[dusk="exercise-progress-error"] button').trigger('click')
        await flushPromises()

        expect(wrapper.find('[dusk="exercise-progress-error"]').exists()).toBe(false)

        wrapper.unmount()
    })
})
