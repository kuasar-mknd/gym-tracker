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
    usePage: () => ({ props: { auth: { user: { id: 1, auto_rest_timer: true } }, is_testing: true } }),
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
import AjoutDExerciceModal from '@/Components/Workout/AjoutDExerciceModal.vue'
import RangeeDeSerie from '@/Components/Workout/RangeeDeSerie.vue'
import CarteDExercice from '@/Components/Workout/CarteDExercice.vue'

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
                AjoutDExerciceModal,
                RangeeDeSerie,
                CarteDExercice,
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
    let reject
    const promise = new Promise((res, rej) => {
        resolve = res
        reject = rej
    })

    // La promesse est souvent gardee en vol un long moment avant d'etre
    // reglee ; sans ce puits, un rejet tardif remonte en « unhandled ».
    promise.catch(() => {})

    return { promise, resolve, reject }
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

        // A number, not the DOM's string: the column is numeric and `'80' !== 80`
        // made every comparison on this page disagree with itself.
        expect(patch).toHaveBeenCalledWith('/api/v1/sets/42', { weight: 999 })
        expect(lines(wrapper)[0].sets[0].weight).toBe(80)
    })

    /*
     * Le même refus, mais après une frappe caractère par caractère — ce que
     * fait un vrai clavier, et ce que fait WebDriver.
     *
     * `previousValue` vient de `lastConfirmed(set, field, set[field])`, et
     * `confirmedValues` n'est alimenté que par une réponse ACCEPTÉE. Tant que
     * le champ n'a jamais été enregistré, le repli est donc `set[field]` —
     * déjà écrasé par la frappe précédente.
     *
     * Au second caractère, « la valeur d'avant » vaut donc « 9 » : ce que
     * l'utilisateur venait de taper, que le serveur n'a jamais entendu. Un
     * refus restaure alors une valeur qui n'a jamais existé nulle part, et la
     * liaison à sens unique la réécrit dans le champ. L'utilisateur voit sa
     * saisie amputée de son dernier caractère, sans comprendre pourquoi.
     */
    it('restaure la valeur du serveur, même si la frappe s’est faite caractère par caractère', async () => {
        patch.mockRejectedValue({ response: { status: 422 }, request: {} })

        const wrapper = await mountPage(workoutWithSet)
        const input = wrapper.find('[dusk="weight-input-0-0"]')

        expect(lines(wrapper)[0].sets[0].weight).toBe(80)

        // Deux évènements `input`, comme deux touches.
        input.element.value = '9'
        await input.trigger('input')
        input.element.value = '99'
        await input.trigger('input')
        await input.trigger('change')

        await new Promise((resolve) => setTimeout(resolve, 1100))
        await flushPromises()

        // Le debounce fond les deux touches : une seule écriture, celle qui
        // porte la saisie complète.
        expect(patch).toHaveBeenCalledWith('/api/v1/sets/42', { weight: 99 })
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

        expect(patch).toHaveBeenCalledWith('/api/v1/sets/42', { weight: 105 })
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
    const announce = (url, data) =>
        window.dispatchEvent(new CustomEvent('sync:failed', { detail: { url, status: 422, data } }))

    const message = (wrapper) => wrapper.find('[dusk="set-edit-error"]').text()

    /**
     * "An item of the session could not be saved" is true and useless: it leaves
     * someone scrolling their own workout trying to work out which set never
     * made it. The payload travels with the event so this can name it.
     */
    it('names the exercise whose set could not be created', async () => {
        const wrapper = await mountPage(workoutWithSet)

        announce('/api/v1/sets', { workout_line_id: 10, weight: 60 })
        await wrapper.vm.$nextTick()

        expect(message(wrapper)).toContain('Développé couché')
        expect(message(wrapper)).toContain('série')
    })

    /** The queue serialises some bodies before they are replayed. */
    it('reads a payload that was already serialised', async () => {
        const wrapper = await mountPage(workoutWithSet)

        announce('/api/v1/sets', JSON.stringify({ workout_line_id: 10 }))
        await wrapper.vm.$nextTick()

        expect(message(wrapper)).toContain('Développé couché')
    })

    it('names the exercise that could not be added', async () => {
        const wrapper = await mountPage(workoutWithSet)

        announce('/api/v1/workout-lines', { exercise_id: 5 })
        await wrapper.vm.$nextTick()

        expect(message(wrapper)).toContain('Développé couché')
    })

    /**
     * Vague beats wrong: a payload that no longer matches anything on screen —
     * a line deleted since — must not name whichever row happens to be first.
     */
    it('falls back to the general wording when it cannot tell what failed', async () => {
        const wrapper = await mountPage(workoutWithSet)

        announce('/api/v1/sets', { workout_line_id: 999 })
        await wrapper.vm.$nextTick()

        expect(message(wrapper)).toContain('Un élément de la séance')
        expect(message(wrapper)).not.toContain('Développé couché')
    })

    it('still says something when the event carries no payload at all', async () => {
        const wrapper = await mountPage(workoutWithSet)

        announce('/api/v1/sets')
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

/*
 * Les deux gardes qui ordonnent les écritures de complétion — le séquenceur et
 * la file — sont indexées par une clé. Chacun des deux cas ci-dessous les met
 * en échec, et par la même faille : la clé, ou le rang, sont pris trop tard.
 *
 * Trouvés en cherchant la cause du flake #1503. Ce ne sont pas des défauts de
 * test : ils se produisent à la salle, sur une connexion lente.
 */
describe('Workouts/Show — l’ordonnancement des validations de série', () => {
    /*
     * `writeKey` vaut `completion:${set.id}`, et `set.id` est REMPLACÉ en place
     * quand la création de la série répond. Une validation faite pendant que la
     * création est encore en vol prend donc la clé `completion:temp-N`, et le
     * geste suivant prend `completion:12` — deux clés, donc deux séquenceurs et
     * deux files.
     *
     * La réponse tardive de la première interroge alors une clé que plus rien
     * n'écrit, s'entend répondre qu'elle est la plus récente, et réapplique
     * `is_completed: true` sur une série que l'utilisateur vient de décocher.
     * La case se recoche toute seule.
     */
    it('ne recoche pas une série décochée, quand la validation a précédé la création', async () => {
        const setCreated = deferred()

        post.mockImplementation((url) =>
            url.includes('workout-lines')
                ? Promise.resolve({ data: { data: { id: 10, order: 0, exercise: EXERCISE, sets: [] } } })
                : setCreated.promise,
        )

        const wrapper = await mountPage(emptyWorkout)
        await addExercise(wrapper)
        await flushPromises()

        await click(wrapper, 'add-set-0')
        await flushPromises()

        const serie = () => lines(wrapper)[0].sets[0]

        expect(String(serie().id)).toMatch(/^temp-/)

        /*
         * Toutes les réponses sont retenues, puis relâchées dans le PIRE ordre
         * que le réseau autorise : la plus récemment partie d'abord, la plus
         * ancienne en dernier.
         *
         * C'est l'ordre d'ARRIVÉE qu'on pilote, jamais le rang d'appel. La
         * réconciliation de la création envoie elle aussi un `is_completed`, et
         * avec le même corps : ni le rang ni la charge utile ne les
         * distinguent, et le nombre de requêtes est précisément ce qu'un
         * correctif voisin fera bouger. Un oracle bâti là-dessus se met à
         * retenir la mauvaise requête et le test échoue pour une raison qui
         * n'est pas la sienne.
         *
         * Un serveur renvoie ce qu'il a reçu : chaque réponse fait donc écho à
         * sa propre charge utile.
         */
        const enVol = []
        patch.mockImplementation((url, corps) => {
            const attente = deferred()
            enVol.push({ corps, resolve: attente.resolve })

            return attente.promise
        })

        await click(wrapper, 'complete-set-0-0')
        await flushPromises()

        expect(serie().is_completed).toBe(true)

        // La création répond : `set.id` est remplacé en place.
        setCreated.resolve({ data: { data: { id: 12, weight: 0, reps: 10, is_completed: true } } })
        await flushPromises()

        expect(String(serie().id)).toBe('12')

        // L'utilisateur décoche.
        await click(wrapper, 'complete-set-0-0')
        await flushPromises()

        // Les plus récentes d'abord. Une file bien tenue n'en libère qu'une à
        // la fois, d'où la reprise tant qu'il en reste.
        let relachees = 0
        while (relachees < enVol.length) {
            const restantes = enVol.slice(relachees)
            relachees = enVol.length

            for (const requete of restantes.reverse()) {
                requete.resolve({ data: { data: { is_completed: requete.corps.is_completed } } })
                await flushPromises()
            }
        }

        expect(serie().is_completed).toBe(false)
    })

    /*
     * `nextWrite` est appelé APRÈS `await flushPendingUpdates(set.id)`. Cette
     * attente est longue pour le premier appui — elle vide le debounce et
     * attend l'écriture de valeur — et vide pour le second, que le premier a
     * déjà purgé.
     *
     * Le second appui double donc le premier et prend le rang 1 ; le premier
     * prend le rang 2. Le plus ANCIEN devient « le plus récent », et c'est lui
     * que le serveur entend en dernier. L'écran peut avoir raison pendant que
     * la base a tort — ce qui est pire qu'un écran faux, parce que rien ne le
     * montre.
     *
     * Aucun identifiant provisoire ici : la série vient du serveur.
     */
    it('envoie au serveur les validations dans l’ordre où elles ont été faites', async () => {
        const valeurEcrite = deferred()
        const wrapper = await mountPage(workoutWithSet)

        // Une écriture de valeur en attente, que le premier appui devra vider.
        const champ = wrapper.find('[dusk="weight-input-0-0"]')
        champ.element.value = '105'
        await champ.trigger('input')

        patch.mockImplementationOnce(() => valeurEcrite.promise)

        await click(wrapper, 'complete-set-0-0')
        await click(wrapper, 'complete-set-0-0')
        await flushPromises()

        valeurEcrite.resolve({ data: { data: { weight: 105 } } })
        await flushPromises()

        const completions = patch.mock.calls.filter(([, corps]) => 'is_completed' in (corps ?? {}))

        expect(completions.map(([, corps]) => corps.is_completed)).toEqual([true, false])
    })
})

/*
 * Une série créée sur cette page garde son identité à travers un
 * rafraîchissement de props.
 *
 * `mergeServerWorkout` reconstruit chaque série connue du serveur par
 * `JSON.parse(JSON.stringify(...))`, et ne réinjecte l'objet local que s'il est
 * marqué non synchronisé (l.96). Une série normalement enregistrée perdait donc
 * son `_rowKey` au premier renommage de séance, à la première correction
 * d'heure, au premier « enregistrer comme modèle ».
 *
 * Deux conséquences, et la seconde ne se voit pas :
 *
 *  - `rowKey()` sert de `:key` au `v-for`. Sans `_rowKey` il retombe sur l'id,
 *    donc la clé de CHAQUE ligne change et Vue détruit puis reconstruit des
 *    rangées qui n'ont pas bougé — l'état de glissement perdu, les champs
 *    recréés, le focus qui saute en pleine frappe. C'est exactement ce que le
 *    test « leaves the rows below a deletion alone » interdit ailleurs.
 *  - l'ordonnancement des validations est indexé sur cette même identité. Deux
 *    appuis encadrant le rafraîchissement reprenaient deux clés, et les deux
 *    garde-fous sautaient ensemble.
 */
describe('Workouts/Show — l’identité d’une série survit à un rafraîchissement', () => {
    it('garde l’ordre des validations de part et d’autre d’un rafraîchissement de props', async () => {
        post.mockImplementation((url) =>
            url.includes('workout-lines')
                ? Promise.resolve({ data: { data: { id: 10, order: 0, exercise: EXERCISE, sets: [] } } })
                : Promise.resolve({ data: { data: { id: 77, weight: 0, reps: 10, is_completed: false } } }),
        )

        const wrapper = await mountPage(emptyWorkout)
        await addExercise(wrapper)
        await flushPromises()
        await click(wrapper, 'add-set-0')
        await flushPromises()

        const serie = () => lines(wrapper)[0].sets[0]

        expect(String(serie().id)).toBe('77')

        patch.mockReset()

        const premiere = deferred()
        let requetes = 0
        patch.mockImplementation(() => {
            requetes += 1

            return requetes === 1 ? premiere.promise : Promise.resolve({ data: {} })
        })

        const cleAvant = serie()._rowKey

        await click(wrapper, 'complete-set-0-0')

        // Le serveur renvoie la séance — un renommage, une correction d'heure,
        // n'importe quoi qui rafraîchisse les props.
        await wrapper.setProps({
            workout: {
                ...JSON.parse(JSON.stringify(emptyWorkout)),
                name: 'Renommée',
                workout_lines: [
                    {
                        id: 10,
                        order: 0,
                        exercise: EXERCISE,
                        sets: [{ id: 77, weight: 0, reps: 10, is_completed: false }],
                    },
                ],
            },
        })
        await flushPromises()

        // L'identité doit avoir survécu : c'est elle qui indexe l'ordonnancement,
        // et c'est aussi la `:key` du `v-for`.
        expect(serie()._rowKey).toBe(cleAvant)

        await click(wrapper, 'complete-set-0-0')
        await flushPromises()

        const completions = () => patch.mock.calls.filter(([, corps]) => 'is_completed' in (corps ?? {}))

        /*
         * Ce que la clé stable achète : la file. Le second appui attend que le
         * premier ait répondu, au lieu de partir en parallèle sur une file
         * neuve. Deux PATCH de complétion en vol sur la même ligne, c'est le
         * serveur qui arbitre — et il arbitre par ordre d'arrivée, pas par
         * ordre d'appui.
         */
        expect(completions()).toHaveLength(1)

        premiere.resolve({ data: { data: { is_completed: true } } })
        await flushPromises()

        expect(completions()).toHaveLength(2)
    })
})

/*
 * La réconciliation de la création ne double pas l'écriture de validation.
 *
 * `addSet` envoie la série avec `is_completed: false`, puis compare ce qui a
 * changé depuis et rattrape l'écart par un PATCH. Ce diff est calculé sur les
 * clés de la charge utile envoyée, `is_completed` comprise : cocher pendant que
 * la création est en vol y ajoutait donc la validation.
 *
 * Ce PATCH-là part **sans séquenceur ni file**. Il court contre la chaîne de
 * complétion, qui, elle, est ordonnée — et rien n'arbitre entre les deux
 * sinon l'ordre d'arrivée au serveur. L'écran pouvait avoir raison pendant que
 * la base gardait la valeur du perdant.
 *
 * Il est de toute façon redondant : `toggleSetCompletion` est garée sur
 * `pendingIds.resolve(set.id)`, qui se résout sur cette même promesse de
 * création. Son écriture ordonnée part donc à l'instant exact où la création
 * retombe, et elle porte déjà la validation.
 */
describe('Workouts/Show — la réconciliation d’une série créée', () => {
    it('laisse la file porter la validation, au lieu d’en envoyer une seconde', async () => {
        const setCreated = deferred()

        post.mockImplementation((url) =>
            url.includes('workout-lines')
                ? Promise.resolve({ data: { data: { id: 10, order: 0, exercise: EXERCISE, sets: [] } } })
                : setCreated.promise,
        )

        const wrapper = await mountPage(emptyWorkout)
        await addExercise(wrapper)
        await flushPromises()
        await click(wrapper, 'add-set-0')
        await flushPromises()

        expect(String(lines(wrapper)[0].sets[0].id)).toMatch(/^temp-/)

        // Coché pendant que la création est encore en vol.
        await click(wrapper, 'complete-set-0-0')
        await flushPromises()

        setCreated.resolve({ data: { data: { id: 77, weight: 0, reps: 10, is_completed: false } } })
        await flushPromises()

        // Un appui, une écriture de validation. La seconde était celle qui
        // échappait à l'ordonnancement.
        const completions = patch.mock.calls.filter(([, corps]) => 'is_completed' in (corps ?? {}))

        expect(completions).toHaveLength(1)
    })
})

/*
 * Un rafraîchissement de props ne décoche pas une série dont la validation est
 * encore en vol.
 *
 * `mergeServerWorkout` reprend la copie du serveur pour toute série qui n'est
 * pas marquée « non synchronisée » — un marquage réservé aux écritures de
 * VALEUR refusées. Une validation partie mais sans réponse n'est donc protégée
 * par rien : le serveur, qui ne l'a pas encore enregistrée, renvoie
 * légitimement `is_completed: false`, et la coche disparaît de l'écran.
 *
 * Elle revient à l'arrivée de la réponse, ce qui en fait un clignotement plutôt
 * qu'une perte — mais un clignotement au milieu d'une série, pendant que
 * l'utilisateur enchaîne, c'est exactement ce qui fait douter de ce qu'on vient
 * de valider.
 */
describe('Workouts/Show — un rafraîchissement pendant une validation en vol', () => {
    it('ne décoche pas la série que le serveur ne connaît pas encore', async () => {
        const wrapper = await mountPage(workoutWithSet)

        const validation = deferred()
        patch.mockImplementation(() => validation.promise)

        await click(wrapper, 'complete-set-0-0')
        await flushPromises()

        expect(lines(wrapper)[0].sets[0].is_completed).toBe(true)

        // Le serveur renvoie la séance — et il ignore encore la validation.
        await wrapper.setProps({
            workout: { ...JSON.parse(JSON.stringify(workoutWithSet)), name: 'Renommée' },
        })
        await flushPromises()

        expect(wrapper.vm.localWorkout.name).toBe('Renommée')
        expect(lines(wrapper)[0].sets[0].is_completed).toBe(true)

        validation.resolve({ data: { data: { is_completed: true } } })
        await flushPromises()

        expect(lines(wrapper)[0].sets[0].is_completed).toBe(true)
    })
})

/*
 * Deux séries ajoutées de part et d'autre d'un rafraîchissement partagent la
 * même chaîne de création.
 *
 * `setCreateChains` sérialise les créations d'une même ligne, pour qu'une série
 * n'aille jamais chercher un `workout_line_id` encore provisoire. Il est indexé
 * sur l'OBJET ligne, et son commentaire justifie ce choix ainsi : « l'identité
 * de l'objet est stable — addExercise mute la ligne en place et ne la remplace
 * jamais ».
 *
 * C'était vrai d'`addExercise`. Ça ne l'est pas de `mergeServerWorkout`, qui
 * reconstruit chaque ligne par `JSON.parse(JSON.stringify(...))`. Après un
 * rafraîchissement, la seconde série ouvre donc une chaîne neuve et sa création
 * part sans attendre la première.
 */
describe('Workouts/Show — deux séries de part et d’autre d’un rafraîchissement', () => {
    it('garde une seule chaîne de création pour la même ligne', async () => {
        const premiereCreation = deferred()
        const creations = []

        post.mockImplementation((url) => {
            if (url.includes('workout-lines')) {
                return Promise.resolve({ data: { data: { id: 10, order: 0, exercise: EXERCISE, sets: [] } } })
            }

            creations.push(url)

            return creations.length === 1
                ? premiereCreation.promise
                : Promise.resolve({ data: { data: { id: 78, weight: 0, reps: 10, is_completed: false } } })
        })

        const wrapper = await mountPage(emptyWorkout)
        await addExercise(wrapper)
        await flushPromises()

        await click(wrapper, 'add-set-0')
        await flushPromises()

        expect(creations).toHaveLength(1)

        // Le serveur renvoie la séance — la ligne est reconstruite.
        await wrapper.setProps({
            workout: {
                ...JSON.parse(JSON.stringify(emptyWorkout)),
                name: 'Renommée',
                workout_lines: [{ id: 10, order: 0, exercise: EXERCISE, sets: [] }],
            },
        })
        await flushPromises()

        await click(wrapper, 'add-set-0')
        await flushPromises()

        // La seconde attend : la première n'a pas répondu.
        expect(creations).toHaveLength(1)

        premiereCreation.resolve({ data: { data: { id: 77, weight: 0, reps: 10, is_completed: false } } })
        await flushPromises()

        expect(creations).toHaveLength(2)
    })
})

/*
 * Un refus restaure ce que le SERVEUR a envoyé, même quand une écriture
 * précédente est encore en vol.
 *
 * `confirmedValues` n'est alimenté que par une réponse acceptée. #1540 a fermé
 * le cas de la rafale — les touches d'une même salve partagent la valeur
 * d'avant la première — mais pas celui-ci : dès qu'une salve est partie, son
 * minuteur est oublié, et la salve suivante retombe sur `set[field]`, déjà
 * optimiste. Deux corrections coup sur coup, toutes deux refusées, laissaient
 * donc à l'écran la première des deux au lieu de la valeur du serveur.
 *
 * Le repli est désormais amorcé avec ce que le serveur envoie au chargement :
 * il y a toujours une valeur confirmée à restaurer.
 */
describe('Workouts/Show — deux corrections refusées coup sur coup', () => {
    it('revient à la valeur du serveur, pas à la correction intermédiaire', async () => {
        const premierRefus = deferred()
        let envois = 0

        patch.mockImplementation(() => {
            envois += 1

            // La première reste en vol : c'est la fenêtre qu'on éprouve.
            return envois === 1 ? premierRefus.promise : Promise.reject({ response: { status: 422 }, request: {} })
        })

        const wrapper = await mountPage(workoutWithSet)
        const input = wrapper.find('[dusk="weight-input-0-0"]')

        expect(lines(wrapper)[0].sets[0].weight).toBe(80)

        // Première salve, laissée partir — sa réponse n'arrivera qu'à la fin.
        input.element.value = '9'
        await input.trigger('input')
        await new Promise((resolve) => setTimeout(resolve, 1100))

        expect(envois).toBe(1)

        // Seconde salve, alors que la première n'a toujours pas répondu.
        input.element.value = '99'
        await input.trigger('input')
        await new Promise((resolve) => setTimeout(resolve, 1100))
        await flushPromises()

        premierRefus.reject({ response: { status: 422 }, request: {} })
        await flushPromises()

        expect(lines(wrapper)[0].sets[0].weight).toBe(80)
    })
})
