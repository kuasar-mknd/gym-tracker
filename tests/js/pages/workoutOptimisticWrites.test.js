import { describe, it, expect, vi, beforeAll, beforeEach, afterEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'

const post = vi.fn()
const patch = vi.fn()
const routerPatch = vi.fn()

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
    router: {
        visit: vi.fn(),
        post: vi.fn(),
        reload: vi.fn(),
        delete: vi.fn(),
        patch: (...args) => routerPatch(...args),
    },
    useForm: (data) => ({ ...data, processing: false, errors: {}, post: vi.fn(), put: vi.fn(), reset: vi.fn() }),
}))

import WorkoutShow from '@/Pages/Workouts/Show.vue'

const EXERCISE = { id: 5, name: 'Développé couché', type: 'strength', category: 'Pectoraux', default_rest_time: 90 }

const workoutWithSet = {
    id: 1,
    name: 'Séance',
    started_at: '2026-07-29T08:00:00.000000Z',
    ended_at: null,
    workout_lines: [
        { id: 10, order: 0, exercise: EXERCISE, sets: [{ id: 42, weight: 80, reps: 5, is_completed: false }] },
    ],
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
            stubs: {
                AuthenticatedLayout: passesSlot,
                GlassCard: passesSlot,
                SwipeableRow: passesSlot,
                Modal: passesSlot,
                GlassInput: passesSlot,
                GlassSelect: passesSlot,
            },
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
    routerPatch.mockReset()
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
        post.mockImplementation((url) => (url.includes('workout-lines') ? lineCreated.promise : setCreated.promise))

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
        window.dispatchEvent(new CustomEvent('sync:replayed', { detail: { queueId: 'q-77', data: { id: 31 } } }))
        await flushPromises()

        expect(post).toHaveBeenCalledWith('/api/v1/sets', expect.objectContaining({ workout_line_id: 31 }))
        expect(lines(wrapper)[0].sets[0].id).toBe(88)
        expect(wrapper.vm.unsyncedSetIds.size).toBe(0)
    })
})

/**
 * Typing a weight and immediately going back is ordinary. The edit was left in
 * a one-second debounce that fired into a component that no longer existed: a
 * refusal reverted a ref nobody was rendering and told nobody, so the edit was
 * gone without a word.
 */
describe('Workouts/Show — leaving the page mid-edit', () => {
    it('sends a debounced edit instead of leaving it to fire into nothing', async () => {
        const wrapper = await mountPage(workoutWithSet)
        const input = wrapper.find('[dusk="weight-input-0-0"]')

        input.element.value = '105'
        await input.trigger('input')
        await input.trigger('change')

        // Straight out of the page, well inside the debounce.
        expect(patch).not.toHaveBeenCalled()

        wrapper.unmount()
        await flushPromises()

        expect(patch).toHaveBeenCalledWith('/api/v1/sets/42', { weight: '105' })
    })
})

/**
 * Closing the session revokes the right to write to its sets. A value typed a
 * second before tapping Terminer used to go out AFTER the workout was finished,
 * come back 403, and be reverted on a page that had already navigated away.
 */
describe('Workouts/Show — finishing the session', () => {
    it('sends the pending edit before it closes the workout', async () => {
        const order = []
        patch.mockImplementation((...args) => {
            order.push('patch:' + args[0])

            return Promise.resolve({ data: {} })
        })
        routerPatch.mockImplementation((url) => {
            order.push('finish:' + url)
        })

        const wrapper = await mountPage(workoutWithSet)
        const input = wrapper.find('[dusk="weight-input-0-0"]')

        input.element.value = '110'
        await input.trigger('input')
        await input.trigger('change')

        await wrapper.vm.confirmFinishWorkout()
        await flushPromises()

        expect(order).toEqual(['patch:/api/v1/sets/42', 'finish:/workouts.update'])
    })
})

/**
 * An ordinary Inertia round trip on this page — renaming the session, fixing its
 * start time — used to assign the incoming props over local state wholesale.
 * Every row still being created and every value the server had refused was
 * thrown away without a word: the user renamed their workout and the sets they
 * had just added disappeared.
 */
describe('Workouts/Show — the server re-sends the workout', () => {
    it('keeps a set the server has never heard of', async () => {
        const setCreated = deferred()
        post.mockImplementation((url) =>
            url.includes('workout-lines')
                ? Promise.resolve({ data: { data: { id: 10, order: 0, exercise: EXERCISE, sets: [] } } })
                : setCreated.promise,
        )

        const wrapper = await mountPage(workoutWithSet)

        await click(wrapper, 'add-set-0')
        await flushPromises()

        expect(lines(wrapper)[0].sets).toHaveLength(2)
        expect(String(lines(wrapper)[0].sets[1].id)).toMatch(/^temp-/)

        // The rename comes back from the server, knowing only the saved set.
        await wrapper.setProps({ workout: { ...JSON.parse(JSON.stringify(workoutWithSet)), name: 'Renommée' } })
        await flushPromises()

        expect(wrapper.vm.localWorkout.name).toBe('Renommée')
        expect(lines(wrapper)[0].sets).toHaveLength(2)
        expect(String(lines(wrapper)[0].sets[1].id)).toMatch(/^temp-/)
    })

    it('keeps a value the server refused over the stale one it sends back', async () => {
        const wrapper = await mountPage(workoutWithSet)

        // The set is on screen with a value the server would not accept.
        lines(wrapper)[0].sets[0].weight = 137
        wrapper.vm.unsyncedSetIds.add('42')

        await wrapper.setProps({ workout: { ...JSON.parse(JSON.stringify(workoutWithSet)), name: 'Renommée' } })
        await flushPromises()

        expect(lines(wrapper)[0].sets[0].weight).toBe(137)
    })
})

/**
 * A closed session is a record, not a workspace. The page had no idea a workout
 * could be finished and rendered the full live editor regardless: SetPolicy
 * refuses every write to a closed session, so each control answered 403, and
 * only the two that revert visibly said anything at all. Adding an exercise,
 * adding a set and deleting one simply did nothing.
 */
describe('Workouts/Show — a finished session', () => {
    const finished = { ...workoutWithSet, ended_at: '2026-07-29T09:30:00.000000Z' }

    it('offers no control that the server would refuse', async () => {
        const wrapper = await mountPage(finished)

        expect(wrapper.find('[dusk="add-set-0"]').exists()).toBe(false)
        expect(wrapper.find('[dusk="add-exercise-existing"]').exists()).toBe(false)
        expect(wrapper.find('[dusk="remove-set-0-0"]').exists()).toBe(false)
    })

    it('shows the values without letting them be edited', async () => {
        const wrapper = await mountPage(finished)
        const weight = wrapper.find('[dusk="weight-input-0-0"]')

        expect(weight.exists()).toBe(true)
        expect(weight.attributes('disabled')).toBeDefined()
        expect(wrapper.find('[dusk="complete-set-0-0"]').attributes('disabled')).toBeDefined()
    })

    it('still offers everything while the session is open', async () => {
        const wrapper = await mountPage(workoutWithSet)

        expect(wrapper.find('[dusk="add-set-0"]').exists()).toBe(true)
        expect(wrapper.find('[dusk="remove-set-0-0"]').exists()).toBe(true)
        expect(wrapper.find('[dusk="weight-input-0-0"]').attributes('disabled')).toBeUndefined()
    })
})

/**
 * The timer only reset itself while it was NOT running, and completing a set
 * while it was already counting down neither remounted it nor restarted it. The
 * second set of a superset got whatever was left of the first one's rest — the
 * shorter the gap between sets, the shorter the rest, which is backwards.
 */
describe('Workouts/Show — the rest timer', () => {
    it('starts a fresh countdown for every completed set', async () => {
        const wrapper = await mountPage(workoutWithSet)
        const timer = () => wrapper.find('[dusk="rest-timer"]')

        await click(wrapper, 'complete-set-0-0')
        await flushPromises()

        expect(timer().exists()).toBe(true)

        // The element itself, so this asserts the timer was rebuilt rather than
        // left counting down — a fresh rest period, not the tail of the last one.
        const firstTimer = timer().element

        // Uncheck, then complete again while the first rest is still running.
        await click(wrapper, 'complete-set-0-0')
        await click(wrapper, 'complete-set-0-0')
        await flushPromises()

        expect(timer().exists()).toBe(true)
        expect(timer().element).not.toBe(firstTimer)
    })
})

/**
 * Set rows were keyed `${set.id}-${index}`. Deleting a row shifts every index
 * below it, so every one of those keys changed and Vue destroyed and rebuilt
 * rows that had not moved: swipe state reset, inputs re-created, and a field
 * being edited losing focus mid-keystroke.
 */
describe('Workouts/Show — list identity', () => {
    it('leaves the rows below a deletion alone', async () => {
        const threeSets = {
            ...workoutWithSet,
            workout_lines: [
                {
                    id: 10,
                    order: 0,
                    exercise: EXERCISE,
                    sets: [
                        { id: 41, weight: 60, reps: 5, is_completed: false },
                        { id: 42, weight: 70, reps: 5, is_completed: false },
                        { id: 43, weight: 80, reps: 5, is_completed: false },
                    ],
                },
            ],
        }

        const wrapper = await mountPage(threeSets)
        const inputFor = (index) => wrapper.find(`[dusk="weight-input-0-${index}"]`).element

        const survivor = inputFor(2)

        // Remove the first row; the two below it shift up but do not change.
        lines(wrapper)[0].sets.splice(0, 1)
        await wrapper.vm.$nextTick()

        expect(lines(wrapper)[0].sets).toHaveLength(2)
        expect(inputFor(1)).toBe(survivor)
    })
})

/**
 * The badge matcher only recognised an id-bearing URL, so a refused CREATE —
 * POST /api/v1/sets, POST /api/v1/workout-lines — matched nothing and the user
 * was told nothing at all. There is no row to mark, because the thing that
 * failed is the row itself.
 */
describe('Workouts/Show — a refused create', () => {
    const announce = (url) => window.dispatchEvent(new CustomEvent('sync:failed', { detail: { url, status: 422 } }))

    it('says something when a set could not be created', async () => {
        const wrapper = await mountPage(workoutWithSet)

        announce('/api/v1/sets')
        await wrapper.vm.$nextTick()

        expect(wrapper.find('[dusk="set-edit-error"]').exists()).toBe(true)
    })

    it('says something when an exercise could not be created', async () => {
        const wrapper = await mountPage(workoutWithSet)

        announce('/api/v1/workout-lines')
        await wrapper.vm.$nextTick()

        expect(wrapper.find('[dusk="set-edit-error"]').exists()).toBe(true)
    })

    it('still marks the row when the failure names one', async () => {
        const wrapper = await mountPage(workoutWithSet)

        announce('/api/v1/sets/42')
        await wrapper.vm.$nextTick()

        expect(wrapper.vm.unsyncedSetIds.has('42')).toBe(true)
        expect(wrapper.find('[dusk="set-edit-error"]').exists()).toBe(false)
    })
})
