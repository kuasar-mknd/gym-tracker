import { describe, it, expect, vi, beforeAll, beforeEach, afterEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'

const patch = vi.fn()

const failedRequests = vi.fn(() => [])
const clearFailedRequests = vi.fn()

vi.mock('@/Utils/SyncService', () => ({
    default: {
        patch: (...args) => patch(...args),
        post: vi.fn(),
        delete: vi.fn(),
        get: vi.fn(),
        failedRequests: () => failedRequests(),
        clearFailedRequests: () => clearFailedRequests(),
    },
}))
vi.mock('@/composables/useHaptics', () => ({ triggerHaptic: vi.fn() }))
vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<div />' },
    Link: { template: '<a><slot /></a>' },
    usePage: () => ({ props: { auth: { user: { id: 1, auto_rest_timer: true } }, is_testing: true } }),
    router: { visit: vi.fn(), post: vi.fn(), reload: vi.fn(), delete: vi.fn() },
    useForm: (data) => ({ ...data, processing: false, errors: {}, post: vi.fn(), put: vi.fn(), reset: vi.fn() }),
}))

import WorkoutShow from '@/Pages/Workouts/Show.vue'
import RangeeDeSerie from '@/Components/Workout/RangeeDeSerie.vue'

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

/**
 * Shallow, so the page's own markup is the only thing rendered — but the three
 * wrappers between the page root and a set row have to pass their slots through
 * or the row never appears, and the marker with it.
 */
const passesSlot = { template: '<div><slot /></div>' }

const mountPage = async () => {
    const wrapper = mount(WorkoutShow, {
        props: { workout: JSON.parse(JSON.stringify(workout)), exercises: [] },
        shallow: true,
        global: {
            directives: { press: {} },
            stubs: { AuthenticatedLayout: passesSlot, GlassCard: passesSlot, SwipeableRow: passesSlot, RangeeDeSerie },
        },
    })

    await flushPromises()

    return wrapper
}

const marker = (wrapper) => wrapper.find('[dusk="set-unsynced-0-0"]')

const draft = () => JSON.parse(localStorage.getItem(DRAFT_KEY))

beforeEach(() => {
    localStorage.clear()
    patch.mockReset()
    clearFailedRequests.mockReset()
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
        expect(marker(wrapper).exists()).toBe(false)

        wrapper.unmount()
    })

    it('keeps the value and marks the set when the server refuses it', async () => {
        localStorage.setItem(DRAFT_KEY, JSON.stringify({ weight: 95 }))
        patch.mockRejectedValue({ response: { status: 422 } })

        const wrapper = await mountPage()

        expect(marker(wrapper).exists()).toBe(true)
        expect(marker(wrapper).attributes('aria-label')).toBe('Série 1 non enregistrée')
        expect(wrapper.vm.localWorkout.workout_lines[0].sets[0].weight).toBe(95)
        expect(draft().syncRejected).toBe(true)
        expect(draft().weight).toBe(95)

        wrapper.unmount()
    })

    it('stops re-sending a draft the server has already refused', async () => {
        localStorage.setItem(DRAFT_KEY, JSON.stringify({ weight: 95, syncRejected: true }))

        const wrapper = await mountPage()

        expect(patch).not.toHaveBeenCalled()
        expect(marker(wrapper).exists()).toBe(true)
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
        expect(marker(wrapper).exists()).toBe(true)

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

        expect(marker(wrapper).exists()).toBe(true)

        wrapper.unmount()
    })

    /**
     * The bucket is written on every refusal and was emptied by nobody:
     * clearFailedRequests() had no caller anywhere in the app. One create the
     * server turned down therefore announced itself on every visit to the
     * session, for ever, with no way to acknowledge it — which is what it was
     * reported as, a banner that "appears by default when you open a session".
     */
    it('acknowledges the stored refusals it has just shown', async () => {
        failedRequests.mockReturnValue([{ url: '/api/v1/sets', data: { workout_line_id: 10 } }])

        const wrapper = await mountPage()

        expect(clearFailedRequests).toHaveBeenCalledTimes(1)

        wrapper.unmount()
    })

    it('says nothing on a mount with nothing to report', async () => {
        failedRequests.mockReturnValue([])

        const wrapper = await mountPage()

        expect(clearFailedRequests).not.toHaveBeenCalled()
        expect(wrapper.find('[dusk="set-edit-error"]').exists()).toBe(false)

        wrapper.unmount()
    })

    it('marks a set when a refusal arrives while the page is open', async () => {
        const wrapper = await mountPage()

        expect(marker(wrapper).exists()).toBe(false)

        window.dispatchEvent(new CustomEvent('sync:failed', { detail: { url: '/api/v1/sets/42', status: 422 } }))
        await flushPromises()

        expect(marker(wrapper).exists()).toBe(true)

        wrapper.unmount()
    })

    /**
     * This asserted expect(true).toBe(true) after dispatching the event, on the
     * theory that a surviving listener would throw against a detached
     * component. It does not — a stale handler quietly mutates a ref nobody is
     * rendering — so the test passed whether or not anything was cleaned up.
     *
     * Every window listener the page registers is now checked to have been
     * handed back, by identity.
     */
    it('hands back every window listener it took', async () => {
        const added = vi.spyOn(window, 'addEventListener')
        const removed = vi.spyOn(window, 'removeEventListener')

        const wrapper = await mountPage()

        const taken = added.mock.calls.filter(([type]) => type.startsWith('sync:') || type === 'open-add-exercise')

        expect(taken.length).toBeGreaterThan(0)

        wrapper.unmount()

        const givenBack = removed.mock.calls
        taken.forEach(([type, handler]) => {
            expect(
                givenBack.some(([outType, outHandler]) => outType === type && outHandler === handler),
                `listener for ${type} was never removed`,
            ).toBe(true)
        })

        added.mockRestore()
        removed.mockRestore()
    })

    it('hands an offline edit over to the queue instead of keeping two copies', async () => {
        localStorage.setItem(DRAFT_KEY, JSON.stringify({ weight: 95 }))
        patch.mockRejectedValue({ isOffline: true })

        const wrapper = await mountPage()

        expect(localStorage.getItem(DRAFT_KEY)).toBeNull()
        expect(marker(wrapper).exists()).toBe(false)

        wrapper.unmount()
    })
})

/**
 * A live edit that the server refuses reverts on screen — right, while the user
 * is looking at the field. But the only signal was a haptic pulse, which does
 * nothing on a desktop and nothing for anyone who turned haptics off: the value
 * snapped back with no reason given.
 */
describe('Workouts/Show — a live edit the server refuses', () => {
    const editWeight = async (wrapper, value) => {
        vi.useFakeTimers()
        const set = wrapper.vm.localWorkout.workout_lines[0].sets[0]
        wrapper.vm.updateSet(set, 'weight', value)
        await vi.runOnlyPendingTimersAsync()
        vi.useRealTimers()
        await flushPromises()

        return set
    }

    const alert = (wrapper) => wrapper.find('[dusk="set-edit-error"]')

    it('says why the value came back, in words', async () => {
        patch.mockRejectedValue({ response: { status: 422 } })

        const wrapper = await mountPage()
        const set = await editWeight(wrapper, 999)

        expect(alert(wrapper).exists()).toBe(true)
        expect(alert(wrapper).text()).toContain('refusée')
        expect(set.weight).toBe(80)

        wrapper.unmount()
    })

    it('distinguishes a server fault from a refusal', async () => {
        patch.mockRejectedValue({ response: { status: 500 } })

        const wrapper = await mountPage()
        await editWeight(wrapper, 999)

        expect(alert(wrapper).text()).toContain("Impossible d'enregistrer")

        wrapper.unmount()
    })

    it('stays quiet when the edit goes through', async () => {
        patch.mockResolvedValue({ data: {} })

        const wrapper = await mountPage()
        await editWeight(wrapper, 90)

        expect(alert(wrapper).exists()).toBe(false)

        wrapper.unmount()
    })

    /**
     * Offline is not a failure here: SyncService has queued the write, so the
     * value stands and there is nothing to tell the user.
     */
    it('says nothing and keeps the value when offline', async () => {
        patch.mockRejectedValue({ isOffline: true })

        const wrapper = await mountPage()
        const set = await editWeight(wrapper, 999)

        expect(alert(wrapper).exists()).toBe(false)
        expect(set.weight).toBe(999)

        wrapper.unmount()
    })
})
