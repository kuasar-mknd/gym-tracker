import { describe, it, expect, vi, beforeAll, beforeEach, afterEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'

const patch = vi.fn()

const failedRequests = vi.fn(() => [])

vi.mock('@/Utils/SyncService', () => ({
    default: {
        patch: (...args) => patch(...args),
        post: vi.fn(),
        delete: vi.fn(),
        get: vi.fn(),
        failedRequests: () => failedRequests(),
    },
}))
vi.mock('@/composables/useHaptics', () => ({ triggerHaptic: vi.fn() }))
vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<div />' },
    Link: { template: '<a><slot /></a>' },
    usePage: () => ({ props: { auth: { user: { id: 1 } }, is_testing: true } }),
    router: { visit: vi.fn(), post: vi.fn(), reload: vi.fn(), delete: vi.fn() },
    useForm: (data) => ({ ...data, processing: false, errors: {}, post: vi.fn(), put: vi.fn(), reset: vi.fn() }),
}))

import WorkoutShow from '@/Pages/Workouts/Show.vue'

const DRAFT_KEY = 'draft_set_42'

const workout = {
    id: 1,
    name: 'Séance',
    started_at: '2026-07-29T08:00:00.000000Z',
    ended_at: null,
    workout_lines: [
        {
            id: 10,
            exercise: { id: 5, name: 'Développé couché', type: 'strength', default_rest_time: 90 },
            sets: [{ id: 42, weight: 80, reps: 5, is_completed: false }],
        },
    ],
}

beforeAll(() => {
    // Mirrors Ziggy's output for api.v1.sets.update — the shape the failure
    // matcher parses a set id out of.
    globalThis.route = (name, params) => (name === 'api.v1.sets.update' ? `/api/v1/sets/${params.set}` : `/${name}`)
})

const mountPage = async () => {
    const wrapper = mount(WorkoutShow, {
        props: { workout: JSON.parse(JSON.stringify(workout)), exercises: [] },
        shallow: true,
        global: { directives: { press: {} } },
    })

    await flushPromises()

    return wrapper
}

const draft = () => JSON.parse(localStorage.getItem(DRAFT_KEY))

beforeEach(() => {
    localStorage.clear()
    patch.mockReset()
    failedRequests.mockReturnValue([])
})

afterEach(() => localStorage.clear())

/**
 * Edits made offline are written to localStorage and replayed on the next mount.
 * The catch used to be `if (err.isOffline) localStorage.removeItem(key)` and
 * nothing else, so a rejected edit stayed on screen looking saved and was
 * re-sent on every single mount for as long as the draft survived.
 */
describe('Workouts/Show — offline draft replay', () => {
    it('drops the draft once the server accepts it', async () => {
        localStorage.setItem(DRAFT_KEY, JSON.stringify({ weight: 95 }))
        patch.mockResolvedValue({ data: {} })

        const wrapper = await mountPage()

        expect(patch).toHaveBeenCalledTimes(1)
        expect(localStorage.getItem(DRAFT_KEY)).toBeNull()
        expect(wrapper.vm.unsyncedSetIds.has('42')).toBe(false)

        wrapper.unmount()
    })

    it('keeps the value and marks the set when the server refuses it', async () => {
        localStorage.setItem(DRAFT_KEY, JSON.stringify({ weight: 95 }))
        patch.mockRejectedValue({ response: { status: 422 } })

        const wrapper = await mountPage()

        expect(wrapper.vm.unsyncedSetIds.has('42')).toBe(true)
        expect(wrapper.vm.localWorkout.workout_lines[0].sets[0].weight).toBe(95)
        expect(draft().syncRejected).toBe(true)
        expect(draft().weight).toBe(95)

        wrapper.unmount()
    })

    it('stops re-sending a draft the server has already refused', async () => {
        localStorage.setItem(DRAFT_KEY, JSON.stringify({ weight: 95, syncRejected: true }))

        const wrapper = await mountPage()

        expect(patch).not.toHaveBeenCalled()
        expect(wrapper.vm.unsyncedSetIds.has('42')).toBe(true)
        expect(wrapper.vm.localWorkout.workout_lines[0].sets[0].weight).toBe(95)

        wrapper.unmount()
    })

    /**
     * A 500 says nothing about the edit's validity, so the draft stays exactly
     * as it was and the next mount tries again.
     */
    it('keeps retrying after a transient failure', async () => {
        localStorage.setItem(DRAFT_KEY, JSON.stringify({ weight: 95 }))
        patch.mockRejectedValue({ response: { status: 500 } })

        const wrapper = await mountPage()

        expect(draft().syncRejected).toBeUndefined()
        expect(wrapper.vm.unsyncedSetIds.has('42')).toBe(true)

        wrapper.unmount()
    })

    /**
     * A queued mutation the server refused is kept by SyncService rather than
     * dropped. It has to become visible somewhere, or preserving it only moves
     * the silence from the console to localStorage.
     */
    it('marks a set whose queued update the server refused', async () => {
        failedRequests.mockReturnValue([{ url: '/api/v1/sets/42', status: 422 }])

        const wrapper = await mountPage()

        expect(wrapper.vm.unsyncedSetIds.has('42')).toBe(true)

        wrapper.unmount()
    })

    it('marks a set when a refusal arrives while the page is open', async () => {
        const wrapper = await mountPage()

        expect(wrapper.vm.unsyncedSetIds.has('42')).toBe(false)

        window.dispatchEvent(new CustomEvent('sync:failed', { detail: { url: '/api/v1/sets/42', status: 422 } }))
        await flushPromises()

        expect(wrapper.vm.unsyncedSetIds.has('42')).toBe(true)

        wrapper.unmount()
    })

    it('stops listening once the page is gone', async () => {
        const wrapper = await mountPage()
        wrapper.unmount()

        // Would throw against a detached component if the listener survived.
        window.dispatchEvent(new CustomEvent('sync:failed', { detail: { url: '/api/v1/sets/42' } }))

        expect(true).toBe(true)
    })

    it('hands an offline edit over to the queue instead of keeping two copies', async () => {
        localStorage.setItem(DRAFT_KEY, JSON.stringify({ weight: 95 }))
        patch.mockRejectedValue({ isOffline: true })

        const wrapper = await mountPage()

        expect(localStorage.getItem(DRAFT_KEY)).toBeNull()
        expect(wrapper.vm.unsyncedSetIds.has('42')).toBe(false)

        wrapper.unmount()
    })
})
