import { describe, it, expect, vi, beforeAll, beforeEach, afterEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'

const post = vi.fn()
const patch = vi.fn()

vi.mock('@/Utils/SyncService', () => ({
    default: {
        post: (...args) => post(...args),
        patch: (...args) => patch(...args),
        delete: vi.fn(),
        get: vi.fn(),
        failedRequests: () => [],
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

const EXERCISE = { id: 5, name: 'Développé couché', type: 'strength', category: 'Pectoraux', default_rest_time: 90 }

const workoutWithSet = {
    id: 1,
    name: 'Séance',
    started_at: '2026-07-29T08:00:00.000000Z',
    ended_at: null,
    workout_lines: [{ id: 10, order: 0, exercise: EXERCISE, sets: [{ id: 42, weight: 80, reps: 5, is_completed: false }] }],
}

const emptyWorkout = {
    id: 1,
    name: 'Séance',
    started_at: '2026-07-29T08:00:00.000000Z',
    ended_at: null,
    workout_lines: [],
}

beforeAll(() => {
    globalThis.route = (name, params) => {
        if (name === 'api.v1.sets.update') return `/api/v1/sets/${params.set}`
        if (name === 'api.v1.sets.store') return '/api/v1/sets'
        if (name === 'api.v1.workout-lines.store') return '/api/v1/workout-lines'

        return `/${name}`
    }
})

const passesSlot = { template: '<div><slot /></div>' }

const mountPage = async (workout = emptyWorkout) => {
    const wrapper = mount(WorkoutShow, {
        props: { workout: JSON.parse(JSON.stringify(workout)), exercises: [EXERCISE] },
        shallow: true,
        global: {
            directives: { press: {} },
            stubs: { AuthenticatedLayout: passesSlot, GlassCard: passesSlot, SwipeableRow: passesSlot, Modal: passesSlot, GlassInput: passesSlot, GlassSelect: passesSlot },
        },
    })

    await flushPromises()

    return wrapper
}

/** A promise whose settling this test controls, so the in-flight window is real. */
const deferred = () => {
    let resolve
    const promise = new Promise((res) => {
        resolve = res
    })

    return { promise, resolve }
}

const click = async (wrapper, dusk) => {
    const button = wrapper.find(`[dusk="${dusk}"]`)

    expect(button.exists(), `no element marked ${dusk}`).toBe(true)
    await button.trigger('click')
}

/**
 * Opens the picker directly. Which button reveals it differs between the empty
 * and populated states and is not what these tests are about; choosing the
 * exercise is.
 */
const addExercise = async (wrapper) => {
    wrapper.vm.showAddExercise = true
    await wrapper.vm.$nextTick()
    await click(wrapper, `select-exercise-${EXERCISE.id}`)
}

/** The live state of the page, read back through the component instance. */
const lines = (wrapper) => wrapper.vm.localWorkout.workout_lines

beforeEach(() => {
    localStorage.clear()
    post.mockReset()
    patch.mockReset()
    patch.mockResolvedValue({ data: {} })
})

afterEach(() => localStorage.clear())

/**
 * Adding an exercise and typing into it immediately is the normal way to use
 * this screen, and it was the way to lose the entry. Both defects below only
 * exist inside the window where a create is in flight, which is exactly the
 * window the user spends typing.
 */
describe('Workouts/Show — writes made while a create is in flight', () => {
    /**
     * The server's answer necessarily carries the values the payload left with.
     * Assigning that answer over the row reverted whatever the user had typed
     * since, and left the database holding numbers the user had already
     * corrected — with the screen agreeing, so nothing looked wrong.
     */
    it('keeps a value typed while the set was being created, and sends it', async () => {
        const lineCreated = deferred()
        const setCreated = deferred()
        post.mockImplementation((url) =>
            url.includes('workout-lines') ? lineCreated.promise : setCreated.promise,
        )

        const wrapper = await mountPage()

        await addExercise(wrapper)
        lineCreated.resolve({ data: { data: { id: 10, order: 0, exercise: EXERCISE, sets: [] } } })
        await flushPromises()

        await click(wrapper, 'add-set-0')
        await flushPromises()

        // The user corrects the weight while the create is still in flight.
        const set = lines(wrapper)[0].sets[0]
        set.weight = 92.5

        setCreated.resolve({ data: { data: { id: 77, weight: 0, reps: 10, is_completed: false } } })
        await flushPromises()

        expect(lines(wrapper)[0].sets[0].weight).toBe(92.5)
        expect(lines(wrapper)[0].sets[0].id).toBe(77)
        expect(patch).toHaveBeenCalledWith('/api/v1/sets/77', expect.objectContaining({ weight: 92.5 }))
    })

    /**
     * Replacing the array slot with a fresh object fixed one bug and made a
     * subtler one: addSet captured the line when the user tapped and pushed its
     * optimistic set into THAT object, so the swap left it holding a detached
     * copy and the row never got its real id.
     */
    it('gives a set its real id even when the exercise was still being created', async () => {
        const lineCreated = deferred()
        post.mockImplementation((url) =>
            url.includes('workout-lines')
                ? lineCreated.promise
                : Promise.resolve({ data: { data: { id: 77, weight: 0, reps: 10 } } }),
        )

        const wrapper = await mountPage()

        await addExercise(wrapper)
        await flushPromises()

        // The exercise is on screen under a placeholder id; the user adds a set.
        expect(String(lines(wrapper)[0].id)).toMatch(/^temp-/)
        await click(wrapper, 'add-set-0')
        await flushPromises()

        lineCreated.resolve({ data: { data: { id: 10, order: 0, exercise: EXERCISE, sets: [] } } })
        await flushPromises()

        expect(lines(wrapper)[0].id).toBe(10)
        expect(lines(wrapper)[0].sets).toHaveLength(1)
        expect(lines(wrapper)[0].sets[0].id).toBe(77)
        expect(post).toHaveBeenCalledWith('/api/v1/sets', expect.objectContaining({ workout_line_id: 10 }))
    })
})

/**
 * A refused value has to leave the screen, or the user walks away believing a
 * number that the database does not hold.
 */
describe('Workouts/Show — a refused edit reverts', () => {
    it('restores the value the server accepted, not the one it just refused', async () => {
        patch.mockRejectedValue({ response: { status: 422 }, request: {} })

        const wrapper = await mountPage(workoutWithSet)
        const input = wrapper.find('[dusk="weight-input-0-0"]')

        expect(input.exists()).toBe(true)
        expect(lines(wrapper)[0].sets[0].weight).toBe(80)

        /**
         * Typing, then leaving the field — in that order, and both events.
         * `input` is the one that matters: it is what v-model listened to, so
         * firing only `change` lets the old binding look innocent and makes
         * this guard incapable of failing.
         */
        input.element.value = '999'
        await input.trigger('input')
        await input.trigger('change')

        // Real timers: the edit is debounced by a second, and the rollback sits
        // several promise hops past the refusal.
        await new Promise((resolve) => setTimeout(resolve, 1100))
        await flushPromises()

        expect(patch).toHaveBeenCalledWith('/api/v1/sets/42', { weight: '999' })
        expect(lines(wrapper)[0].sets[0].weight).toBe(80)
    })
})

/**
 * The successor of the placeholder-id bug, and it outlived the fix for it.
 *
 * When the exercise's create goes into the offline queue, its placeholder used
 * to resolve to "no such row", so a set added onto it was refused on the spot
 * and never revisited. The queue drained, the exercise appeared on the server,
 * and the set belonged to nobody — gone, with the row still on screen.
 */
describe('Workouts/Show — a set added onto a queued exercise', () => {
    it('is sent once the queue reports what the exercise became', async () => {
        post.mockImplementation((url) =>
            url.includes('workout-lines')
                ? Promise.reject({ isOffline: true, queueId: 'q-77' })
                : Promise.resolve({ data: { data: { id: 88, weight: 0, reps: 10 } } }),
        )

        const wrapper = await mountPage()

        await addExercise(wrapper)
        await flushPromises()

        await click(wrapper, 'add-set-0')
        await flushPromises()

        // Nothing has been sent for the set: the exercise does not exist yet.
        expect(post).toHaveBeenCalledTimes(1)
        expect(wrapper.vm.unsyncedSetIds.size).toBe(1)

        // The connection comes back and the queue drains the exercise.
        window.dispatchEvent(
            new CustomEvent('sync:replayed', { detail: { queueId: 'q-77', data: { id: 31 } } }),
        )
        await flushPromises()

        expect(post).toHaveBeenCalledWith('/api/v1/sets', expect.objectContaining({ workout_line_id: 31 }))
        expect(lines(wrapper)[0].sets[0].id).toBe(88)
        expect(wrapper.vm.unsyncedSetIds.size).toBe(0)
    })
})
