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
    usePage: () => ({ props: { auth: { user: { id: 1, auto_rest_timer: true } }, is_testing: true } }),
    router: { visit: vi.fn(), post: vi.fn(), reload: vi.fn(), delete: vi.fn(), patch: vi.fn() },
    useForm: (data) => ({ ...data, processing: false, errors: {}, post: vi.fn(), put: vi.fn(), reset: vi.fn() }),
}))

import WorkoutShow from '@/Pages/Workouts/Show.vue'
import AjoutDExerciceModal from '@/Components/Workout/AjoutDExerciceModal.vue'
import RangeeDeSerie from '@/Components/Workout/RangeeDeSerie.vue'

const STRENGTH = { id: 5, name: 'Développé couché', type: 'strength', category: 'Pectoraux', default_rest_time: 90 }
const CARDIO = { id: 6, name: 'Course', type: 'cardio', category: 'Cardio', default_rest_time: 60 }

const strengthWorkout = {
    id: 1,
    name: 'Séance',
    started_at: '2026-07-29T08:00:00.000000Z',
    ended_at: null,
    workout_lines: [
        { id: 10, order: 0, exercise: STRENGTH, sets: [{ id: 42, weight: 80, reps: 5, is_completed: false }] },
    ],
}

const cardioWorkout = {
    id: 1,
    name: 'Séance',
    started_at: '2026-07-29T08:00:00.000000Z',
    ended_at: null,
    workout_lines: [
        {
            id: 10,
            order: 0,
            exercise: CARDIO,
            sets: [{ id: 42, distance_km: 5, duration_seconds: 600, is_completed: false }],
        },
    ],
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

const mountPage = async (workout) => {
    const wrapper = mount(WorkoutShow, {
        props: { workout: JSON.parse(JSON.stringify(workout)), exercises: [STRENGTH, CARDIO] },
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

    return { promise, resolve, reject }
}

const firstSet = (wrapper) => wrapper.vm.localWorkout.workout_lines[0].sets[0]

/**
 * Types into a field the way the browser does: the value lands on the element,
 * then both events fire. Firing only the one the current binding listens to
 * would make these guards incapable of catching a regression back to the other.
 */
const type = async (wrapper, dusk, value) => {
    const input = wrapper.find(`[dusk="${dusk}"]`)

    expect(input.exists(), `no input marked ${dusk}`).toBe(true)

    input.element.value = value
    await input.trigger('input')
    await input.trigger('change')

    return input
}

const payloads = () => patch.mock.calls.map(([, body]) => body)

beforeEach(() => {
    localStorage.clear()
    post.mockReset()
    patch.mockReset()
    patch.mockResolvedValue({ data: {} })
})

afterEach(() => localStorage.clear())

/**
 * The duration used to be read out of an `<input type="time">`, which reports an
 * EMPTY value while its segments are incomplete — and the handler ran
 * `val.split(':').map(Number)` on it regardless, so a NaN was written onto the
 * set, wiped the field mid-entry and reached the database as null.
 *
 * That whole class of failure is gone with the string: DurationWheel emits whole
 * seconds. What is worth guarding now is the seam — that the page records the
 * seconds it is handed, and still refuses anything that is not a number, since
 * `updateSet` is what stood between NaN and the payload.
 */
describe('Workouts/Show — duration entry', () => {
    const wheel = (wrapper, dusk) => {
        const component = wrapper.findComponent(`[dusk="${dusk}"]`)

        expect(component.exists(), `no duration wheel marked ${dusk}`).toBe(true)

        return component
    }

    it('records the seconds the wheel emits, and sends them', async () => {
        const wrapper = await mountPage(cardioWorkout)

        await wheel(wrapper, 'duration-input-0-0').vm.$emit('update:modelValue', 1530)

        expect(firstSet(wrapper).duration_seconds).toBe(1530)

        await new Promise((resolve) => setTimeout(resolve, 1100))
        await flushPromises()

        expect(patch).toHaveBeenCalledWith('/api/v1/sets/42', { duration_seconds: 1530 })
    })

    it('hands the wheel the seconds the set holds', async () => {
        const wrapper = await mountPage(cardioWorkout)

        expect(wheel(wrapper, 'duration-input-0-0').props('modelValue')).toBe(600)
    })

    it('records a zero duration, which is a value someone chose', async () => {
        const wrapper = await mountPage(cardioWorkout)

        await wheel(wrapper, 'duration-input-0-0').vm.$emit('update:modelValue', 0)

        expect(firstSet(wrapper).duration_seconds).toBe(0)
    })

    /**
     * `updateSet` is the last guard before the payload, and NaN reaching it is
     * exactly how a duration nobody entered used to be saved — `JSON.stringify`
     * turns it into null. The wheel cannot emit one, but the guard is what makes
     * that a component detail rather than the only thing standing in the way.
     */
    it('refuses a duration that is not a number', async () => {
        const wrapper = await mountPage(cardioWorkout)

        // Not `undefined` or `null`: those mean "the field was cleared", which is
        // a value someone can legitimately arrive at. What must never land is a
        // number that is not one — NaN is what `JSON.stringify` turns into null
        // on the wire, and that is how a duration nobody entered got saved.
        for (const rubbish of [NaN, Infinity, -Infinity, 'abc']) {
            await wheel(wrapper, 'duration-input-0-0').vm.$emit('update:modelValue', rubbish)

            expect(firstSet(wrapper).duration_seconds, `${String(rubbish)} changed the set`).toBe(600)
        }

        await new Promise((resolve) => setTimeout(resolve, 1100))
        await flushPromises()

        expect(payloads().some((body) => 'duration_seconds' in body && !Number.isFinite(body.duration_seconds))).toBe(
            false,
        )
    })

    /**
     * `e.target.value` is a string, always. Storing it as one made `'80' !== 80`
     * true everywhere the page compares a value to itself, and let a cleared
     * field travel as an empty string rather than as the null the column takes.
     */
    it('sends a cleared distance as null and a filled one as a number', async () => {
        const wrapper = await mountPage(cardioWorkout)

        await type(wrapper, 'distance-input-0-0', '7.5')
        await new Promise((resolve) => setTimeout(resolve, 1100))
        await flushPromises()

        expect(patch).toHaveBeenLastCalledWith('/api/v1/sets/42', { distance_km: 7.5 })

        await type(wrapper, 'distance-input-0-0', '')
        await new Promise((resolve) => setTimeout(resolve, 1100))
        await flushPromises()

        expect(patch).toHaveBeenLastCalledWith('/api/v1/sets/42', { distance_km: null })
    })
})

/**
 * An optimistic row wears a placeholder id until the server answers, and
 * `tempSet.id = realSetId` then changed the v-for key under it. Vue answers a
 * changed key by destroying the node and building a new one, so the input the
 * user was typing into was replaced mid-entry: the half-typed value went with
 * it and the field re-rendered from the model.
 *
 * Adding a set and typing into it immediately is the normal way to use this
 * screen, and the faster you were the more reliably you lost the entry. A
 * browser test caught this — a duration typed straight after adding a timed set
 * came back as the pre-fill.
 */
describe('Workouts/Show — a row being typed into while its create lands', () => {
    it('keeps the same input element when the exercise is given its real id', async () => {
        const lineCreated = deferred()
        post.mockImplementation((url) =>
            url.includes('workout-lines')
                ? lineCreated.promise
                : Promise.resolve({ data: { data: { id: 55, weight: 0, reps: 10 } } }),
        )

        const wrapper = await mountPage({
            id: 1,
            name: 'Séance',
            started_at: '2026-07-29T08:00:00.000000Z',
            ended_at: null,
            workout_lines: [],
        })

        wrapper.vm.showAddExercise = true
        await wrapper.vm.$nextTick()
        await wrapper.find(`[dusk="select-exercise-${STRENGTH.id}"]`).trigger('click')
        await flushPromises()

        await wrapper.find('[dusk="add-set-0"]').trigger('click')
        await flushPromises()

        const before = wrapper.find('[dusk="weight-input-0-0"]').element

        lineCreated.resolve({ data: { data: { id: 10, order: 0, exercise: STRENGTH, sets: [] } } })
        await flushPromises()

        expect(wrapper.vm.localWorkout.workout_lines[0].id).toBe(10)
        expect(wrapper.find('[dusk="weight-input-0-0"]').element).toBe(before)
    })
})

/**
 * A new set was filled in with all four measurements whatever the exercise
 * measured, so a cardio set was created carrying `reps: 10` and a weight of 0 —
 * fields its row does not even render — and a timed one carried a distance of 0
 * on top. Numbers the user never entered, on an exercise that has no such field.
 * The reported "10 out of nowhere" is the reps pre-fill landing in rows that
 * have no reps.
 */
describe('Workouts/Show — a new set carries only what its exercise measures', () => {
    const createdSet = { id: 77 }

    const addSetOn = async (exercise) => {
        post.mockResolvedValue({ data: { data: createdSet } })

        const workout = {
            id: 1,
            name: 'Séance',
            started_at: '2026-07-29T08:00:00.000000Z',
            ended_at: null,
            workout_lines: [{ id: 10, order: 0, exercise, sets: [] }],
        }

        const wrapper = await mountPage(workout)

        await wrapper.find('[dusk="add-set-0"]').trigger('click')
        await flushPromises()

        return wrapper
    }

    it('sends a cardio set with no weight and no reps', async () => {
        await addSetOn(CARDIO)

        const [, body] = post.mock.calls.at(-1)

        expect(Object.keys(body).sort()).toEqual(
            ['distance_km', 'duration_seconds', 'is_completed', 'workout_line_id'].sort(),
        )
        expect(body).not.toHaveProperty('reps')
        expect(body).not.toHaveProperty('weight')
    })

    it('sends a timed set with only a duration', async () => {
        await addSetOn({ ...CARDIO, id: 7, type: 'timed' })

        const [, body] = post.mock.calls.at(-1)

        expect(Object.keys(body).sort()).toEqual(['duration_seconds', 'is_completed', 'workout_line_id'].sort())
        expect(body).not.toHaveProperty('distance_km')
        expect(body).not.toHaveProperty('reps')
    })

    it('sends a strength set with no distance and no duration', async () => {
        await addSetOn(STRENGTH)

        const [, body] = post.mock.calls.at(-1)

        expect(Object.keys(body).sort()).toEqual(['is_completed', 'reps', 'weight', 'workout_line_id'].sort())
        expect(body).not.toHaveProperty('distance_km')
        expect(body).not.toHaveProperty('duration_seconds')
    })

    it('leaves the row itself without the fields its exercise does not measure', async () => {
        const wrapper = await addSetOn(CARDIO)
        const set = wrapper.vm.localWorkout.workout_lines[0].sets[0]

        expect(set).not.toHaveProperty('reps')
        expect(set).not.toHaveProperty('weight')
        expect(set.distance_km).toBe(0)
    })
})

/**
 * Two PATCHes for one field are ordinary — the debounce is flushed by validating
 * a set, and the next keystroke starts another — and nothing makes them come
 * home in the order they left.
 */
describe('Workouts/Show — replies that arrive out of order', () => {
    it('does not let an older reply overwrite a newer value', async () => {
        const first = deferred()
        const second = deferred()
        patch.mockImplementationOnce(() => first.promise).mockImplementationOnce(() => second.promise)

        const wrapper = await mountPage(strengthWorkout)
        const set = firstSet(wrapper)

        wrapper.vm.updateSet(set, 'weight', 100)
        wrapper.vm.flushPendingUpdates(42)
        await flushPromises()

        wrapper.vm.updateSet(set, 'weight', 110)
        wrapper.vm.flushPendingUpdates(42)
        await flushPromises()

        // The newer write lands first; the older one is still out there.
        second.resolve({ data: { data: { weight: 110, updated_at: 'b' } } })
        await flushPromises()

        first.resolve({ data: { data: { weight: 100, updated_at: 'a' } } })
        await flushPromises()

        expect(firstSet(wrapper).weight).toBe(110)
    })

    /**
     * Guarding which answer to believe protects the screen and leaves the
     * database to whichever request the server handled last. With both PATCHes
     * in flight at once the older value could be written over the newer one,
     * permanently — so they go out one at a time.
     */
    it('does not put two writes for one field on the wire at once', async () => {
        const first = deferred()
        const sent = []

        patch.mockImplementation((url, body) => {
            sent.push(body.weight)

            return sent.length === 1 ? first.promise : Promise.resolve({ data: { data: { weight: body.weight } } })
        })

        const wrapper = await mountPage(strengthWorkout)
        const set = firstSet(wrapper)

        wrapper.vm.updateSet(set, 'weight', 100)
        wrapper.vm.flushPendingUpdates(42)
        await flushPromises()

        wrapper.vm.updateSet(set, 'weight', 110)
        wrapper.vm.flushPendingUpdates(42)
        await flushPromises()

        // The second write is still queued: the first has not come back.
        expect(sent).toEqual([100])

        first.resolve({ data: { data: { weight: 100 } } })
        await flushPromises()

        // Sent second, so it is the row's final value on the server too.
        expect(sent).toEqual([100, 110])
        expect(firstSet(wrapper).weight).toBe(110)
    })

    /**
     * `previousValue` was read off the set at call time, so typing twice inside
     * the debounce window captured the first typed value — one the server had
     * never accepted — and a refusal "restored" it.
     */
    it('rolls back to the value the server confirmed, not to an intermediate one', async () => {
        const wrapper = await mountPage(strengthWorkout)
        const set = firstSet(wrapper)

        patch.mockResolvedValueOnce({ data: { data: { weight: 90, updated_at: 'a' } } })
        wrapper.vm.updateSet(set, 'weight', 90)
        wrapper.vm.flushPendingUpdates(42)
        await flushPromises()

        expect(firstSet(wrapper).weight).toBe(90)

        // Two edits inside one debounce window: only the last one is ever sent.
        patch.mockRejectedValueOnce({ response: { status: 422 }, request: {} })
        wrapper.vm.updateSet(set, 'weight', 95)
        wrapper.vm.updateSet(set, 'weight', 999)
        wrapper.vm.flushPendingUpdates(42)
        await flushPromises()

        expect(firstSet(wrapper).weight).toBe(90)
    })
})

/**
 * The flush was started and not awaited, so the value writes and the completion
 * PATCH were in flight against the same row at once with no ordering between
 * them — and whichever answer came back second was applied over the first.
 */
describe('Workouts/Show — validating a set right after typing into it', () => {
    it('waits for the value to be accepted before marking the set done', async () => {
        const weightAccepted = deferred()
        const sent = []

        patch.mockImplementation((url, body) => {
            sent.push(Object.keys(body)[0])

            return 'weight' in body ? weightAccepted.promise : Promise.resolve({ data: {} })
        })

        const wrapper = await mountPage(strengthWorkout)
        const set = firstSet(wrapper)

        wrapper.vm.updateSet(set, 'weight', 100)

        const toggling = wrapper.vm.toggleSetCompletion(set, 90)
        await flushPromises()

        expect(sent).toEqual(['weight'])

        weightAccepted.resolve({ data: { data: { weight: 100, updated_at: 'a' } } })
        await toggling
        await flushPromises()

        expect(sent).toEqual(['weight', 'is_completed'])
        expect(firstSet(wrapper).weight).toBe(100)
    })
})

/**
 * The draft was one blob per set, deleted outright as soon as ANY field came
 * back accepted — so correcting a weight and then the reps within the same
 * second meant the weight's success threw away the draft protecting the reps,
 * whose PATCH had not left yet.
 */
describe('Workouts/Show — drafts of edits still in flight', () => {
    it('keeps the draft of a field whose write has not landed', async () => {
        const repsPending = deferred()

        patch.mockImplementation((url, body) =>
            'weight' in body ? Promise.resolve({ data: { data: { weight: 100 } } }) : repsPending.promise,
        )

        const wrapper = await mountPage(strengthWorkout)
        const set = firstSet(wrapper)

        wrapper.vm.updateSet(set, 'weight', 100)
        wrapper.vm.updateSet(set, 'reps', 8)

        expect(JSON.parse(localStorage.getItem('draft_set_42'))).toEqual({ weight: 100, reps: 8 })

        wrapper.vm.flushPendingUpdates(42)
        await flushPromises()

        // The weight is safe on the server; the reps are not, and nothing else
        // is holding them.
        expect(JSON.parse(localStorage.getItem('draft_set_42'))).toEqual({ reps: 8 })

        repsPending.resolve({ data: { data: { reps: 8 } } })
        await flushPromises()

        expect(localStorage.getItem('draft_set_42')).toBeNull()
    })

    it('drops the draft of a set the user deleted', async () => {
        const wrapper = await mountPage(strengthWorkout)

        wrapper.vm.updateSet(firstSet(wrapper), 'weight', 100)
        expect(localStorage.getItem('draft_set_42')).not.toBeNull()

        wrapper.vm.removeSet(42)
        await flushPromises()

        expect(localStorage.getItem('draft_set_42')).toBeNull()
    })
})

/**
 * A set has no order of its own: the database hands them back by id, and the id
 * goes to whichever INSERT arrives first. Two creates in flight at once could
 * therefore be written in the opposite order to the taps that made them, and the
 * sets came back swapped on the next load.
 */
describe('Workouts/Show — two sets added in quick succession', () => {
    it('creates them one after the other rather than in parallel', async () => {
        const first = deferred()
        const second = deferred()
        post.mockImplementationOnce(() => first.promise).mockImplementationOnce(() => second.promise)

        const wrapper = await mountPage(strengthWorkout)

        await wrapper.find('[dusk="add-set-0"]').trigger('click')
        await wrapper.find('[dusk="add-set-0"]').trigger('click')
        await flushPromises()

        // Both rows are on screen immediately; only the first has been sent.
        expect(wrapper.vm.localWorkout.workout_lines[0].sets).toHaveLength(3)
        expect(post).toHaveBeenCalledTimes(1)

        first.resolve({ data: { data: { id: 100, weight: 80, reps: 5 } } })
        await flushPromises()

        expect(post).toHaveBeenCalledTimes(2)

        second.resolve({ data: { data: { id: 101, weight: 80, reps: 5 } } })
        await flushPromises()

        expect(wrapper.vm.localWorkout.workout_lines[0].sets.map((s) => s.id)).toEqual([42, 100, 101])
    })
})

/**
 * Le contrat que #1489 attendait : une valeur tapée pendant la creation de la
 * serie doit atteindre le serveur.
 *
 * `Show.vue` calcule le rattrapage en comparant le MODELE a ce qui a ete envoye :
 *
 *     Object.keys(sent).filter((field) => tempSet[field] !== sent[field])
 *
 * Or les quatre champs numeriques sont lies a sens unique — `:value="set.weight"`
 * — et le modele n'est ecrit que sur `@change`, c'est-a-dire au blur. Une valeur
 * tapee et pas encore quittee n'existe donc que dans le DOM : le diff la croit
 * inchangee, `edited` est vide, et le serveur n'en entend jamais parler.
 *
 * Le commentaire de ce chemin dit « Typed after the payload left, so the server
 * has never heard it » — il ne peut voir que ce qui a franchi le blur.
 */
describe('une valeur tapée pendant la création de la série', () => {
    it('parvient au serveur même sans avoir quitté le champ', async () => {
        const serieCreee = deferred()
        post.mockImplementation((url) =>
            url.includes('workout-lines')
                ? Promise.resolve({ data: { data: { id: 10, order: 0, exercise: STRENGTH, sets: [] } } })
                : serieCreee.promise,
        )
        patch.mockResolvedValue({ data: { data: {} } })

        const wrapper = await mountPage({
            id: 1,
            name: 'Séance',
            started_at: '2026-07-29T08:00:00.000000Z',
            ended_at: null,
            workout_lines: [{ id: 10, order: 0, exercise: STRENGTH, sets: [] }],
        })

        await wrapper.find('[dusk="add-set-0"]').trigger('click')
        await flushPromises()

        /*
         * L'utilisateur tape, et NE QUITTE PAS le champ : c'est exactement la
         * façon normale de se servir de cet écran.
         *
         * `setValue()` ne convient pas ici — il valide le champ, donc il simule
         * une saisie déjà quittée et le test passerait pour la mauvaise raison.
         * Ce qu'on veut, c'est le DOM en avance sur le modèle.
         */
        const champ = wrapper.find('[dusk="weight-input-0-0"]')
        champ.element.value = '50'
        await champ.trigger('input')

        // La réponse de création arrive pendant ce temps, avec les valeurs que la
        // requête portait au départ.
        serieCreee.resolve({
            data: { data: { id: 77, weight: 0, reps: 10, created_at: 'x', updated_at: 'y', personal_record: null } },
        })
        await flushPromises()

        const rattrapage = patch.mock.calls.find(([url]) => url === '/api/v1/sets/77')

        expect(rattrapage, 'aucun rattrapage envoyé : la frappe est restée dans le DOM').toBeTruthy()
        expect(Number(rattrapage[1].weight)).toBe(50)
    })
})

/*
 * La contrepartie du correctif ci-dessus, qui doit être tenue.
 *
 * Écrire le modèle à chaque frappe rapproche le DOM du modèle, mais rapproche
 * aussi Vue du champ : `:value` est réécrit dès que la valeur rendue diffère du
 * texte saisi. Un `input[type=number]` rend `''` pour toute saisie incomplète —
 * « 12. » en cours de frappe en est une — et écrire ce `''` dans le modèle
 * ferait effacer le point sous les doigts de l'utilisateur.
 *
 * D'où la seule exception de `saisieEnCours`. Vider réellement un champ reste
 * traité, mais au blur, par `@change`.
 *
 * Le rendu qui suit ne se teste pas ici : jsdom assainit lui-même la valeur
 * d'un champ numérique, donc « 12. » n'y est pas représentable et un test qui
 * prétendrait l'observer échouerait pareillement sans le correctif. Ce qui se
 * vérifie, et qui suffit, c'est que la frappe incomplète n'atteint pas le modèle.
 */
describe('une frappe incomplète', () => {
    it('ne vide pas le modèle avant que le champ soit quitté', async () => {
        const wrapper = await mountPage(strengthWorkout)

        const serie = () => wrapper.vm.localWorkout.workout_lines[0].sets[0]
        expect(serie().weight).toBe(80)

        const champ = wrapper.find('[dusk="weight-input-0-0"]')
        champ.element.value = ''
        await champ.trigger('input')

        expect(serie().weight, 'une frappe incomplète a effacé la valeur').toBe(80)

        // Vider pour de bon reste possible : c'est le blur qui en décide.
        await champ.trigger('change')
        expect(serie().weight).toBeNull()
    })
})
