import { describe, it, expect, vi, beforeAll, beforeEach, afterEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'

/**
 * The session's start time makes a round trip through local time — the form
 * holds `<input type="datetime-local">` text, the server holds UTC — so the
 * clock has to be pinned or the expected strings are whatever machine ran the
 * suite. New York in July is UTC-4, which is a real offset rather than the
 * no-op UTC would be: a round trip that drops the conversion still passes under
 * TZ=UTC and cannot here.
 */
vi.hoisted(() => {
    process.env.TZ = 'America/New_York'
})

const post = vi.fn()
const patch = vi.fn()
const destroy = vi.fn()
const routerPost = vi.fn()
const routerPatch = vi.fn()
const formPatch = vi.fn()
const fetchMock = vi.fn()

/**
 * The page object `usePage()` hands back, kept as one identity across the two
 * places the component asks for it. `reportSyncFailure` writes onto
 * `props.flash`, which is the layout's toast channel and the only place a
 * failed background write is ever said out loud.
 */
const pageStub = vi.hoisted(() => ({
    props: {
        auth: { user: { id: 1, default_rest_time: 120, auto_rest_timer: true } },
        is_testing: true,
        flash: undefined,
    },
}))

vi.mock('@/Utils/SyncService', () => ({
    default: {
        post: (...args) => post(...args),
        patch: (...args) => patch(...args),
        delete: (...args) => destroy(...args),
        get: vi.fn(),
        failedRequests: () => [],
        clearFailedRequests: vi.fn(),
    },
}))

const reordonnancements = vi.hoisted(() => [])

vi.mock('@formkit/drag-and-drop/vue', () => ({
    dragAndDrop: (config) => reordonnancements.push(config),
}))

const haptics = vi.hoisted(() => ({ triggerHaptic: vi.fn() }))
vi.mock('@/composables/useHaptics', () => ({ triggerHaptic: (...args) => haptics.triggerHaptic(...args) }))

/**
 * `useForm` is mocked reactive, not as a plain object: the create-exercise form
 * puts the server's validation errors back on screen, and a non-reactive `errors`
 * bag means the component never re-renders and the assertion reads a value
 * nothing would have shown the user.
 */
vi.mock('@inertiajs/vue3', async () => {
    const { reactive } = await vi.importActual('vue')

    return {
        Head: { template: '<div />' },
        Link: { template: '<a><slot /></a>' },
        usePage: () => pageStub,
        router: {
            visit: vi.fn(),
            reload: vi.fn(),
            delete: vi.fn(),
            post: (...args) => routerPost(...args),
            patch: (...args) => routerPatch(...args),
        },
        useForm: (data) => {
            const fields = Object.keys(data)
            let transformer = null

            const form = reactive({
                ...data,
                processing: false,
                errors: {},
                transform(fn) {
                    transformer = fn

                    return form
                },
                patch(url, options) {
                    const raw = Object.fromEntries(fields.map((field) => [field, form[field]]))

                    return formPatch(url, transformer ? transformer(raw) : raw, options)
                },
                post: vi.fn(),
                put: vi.fn(),
                reset: vi.fn(),
                clearErrors() {
                    form.errors = {}
                },
                setError(field, message) {
                    form.errors = { ...form.errors, [field]: message }
                },
            })

            return form
        },
    }
})

import WorkoutShow from '@/Pages/Workouts/Show.vue'

const STRENGTH = { id: 5, name: 'Développé couché', type: 'strength', category: 'Pectoraux', default_rest_time: 90 }
const CARDIO = { id: 6, name: 'Course', type: 'cardio', category: 'Cardio', default_rest_time: 60 }
const TIMED = { id: 7, name: 'Gainage', type: 'timed', category: 'Abdominaux', default_rest_time: 45 }

const session = (overrides = {}) => ({
    id: 1,
    name: 'Séance',
    started_at: '2026-07-29T08:00:00.000000Z',
    ended_at: null,
    notes: '',
    workout_lines: [],
    ...overrides,
})

const strengthWorkout = session({
    workout_lines: [
        { id: 10, order: 0, exercise: STRENGTH, sets: [{ id: 42, weight: 80, reps: 5, is_completed: false }] },
    ],
})

const cardioWorkout = session({
    workout_lines: [
        {
            id: 10,
            order: 0,
            exercise: CARDIO,
            sets: [{ id: 42, distance_km: 5, duration_seconds: 600, is_completed: false }],
        },
    ],
})

const timedWorkout = session({
    workout_lines: [
        { id: 10, order: 0, exercise: TIMED, sets: [{ id: 42, duration_seconds: 60, is_completed: false }] },
    ],
})

beforeAll(() => {
    globalThis.route = (name, params) => {
        if (name === 'api.v1.sets.update') return `/api/v1/sets/${params.set}`
        if (name === 'api.v1.sets.store') return '/api/v1/sets'
        if (name === 'api.v1.sets.destroy') return `/api/v1/sets/${params.set}`
        if (name === 'api.v1.workout-lines.store') return '/api/v1/workout-lines'
        if (name === 'api.v1.workout-lines.destroy') return `/api/v1/workout-lines/${params.workout_line}`
        if (name === 'workouts.update') return `/workouts/${params.workout}`
        if (name === 'templates.save-from-workout') return `/workouts/${params.workout}/template`
        if (name === 'exercises.store') return '/exercises'

        return `/${name}`
    }
})

const passesSlot = { template: '<div><slot /></div>' }
/** The session's own controls live in the layout's header slot, not the default one. */
const LayoutStub = { name: 'LayoutStub', template: '<div><slot name="header-actions" /><slot /></div>' }
/** The delete behind a swipe is a named slot, and it is a real control. */
const SwipeStub = { name: 'SwipeStub', template: '<div><slot name="action-right" /><slot /></div>' }
const ModalStub = { name: 'ModalStub', template: '<div><slot /></div>' }
/** A real <button>, so `@click` lands as a native listener and the slot renders. */
const ButtonStub = { name: 'ButtonStub', template: '<button><slot /></button>' }
const FieldStub = { name: 'FieldStub', template: '<div />' }

/**
 * Un bouton-icône rend un vrai `<button>` portant son nom accessible : c'est par
 * ce nom que les tests le trouvent, et un bouchon muet le leur retirerait.
 */
const IconButtonStub = {
    name: 'IconButtonStub',
    props: ['icon', 'label', 'disabled'],
    /*
     * `emits` déclaré, sinon `@click` arrive DEUX fois : une par l'émission, une
     * par la retombée native sur le `<button>`. Un déplacement joué deux fois
     * revient à son point de départ, et le témoin passe pour la mauvaise raison.
     */
    emits: ['click'],
    template: '<button type="button" :aria-label="label" :disabled="disabled" @click="$emit(\'click\')" />',
}
const SettingsModalStub = { name: 'SettingsModalStub', template: '<div />' }
const FinishModalStub = { name: 'FinishModalStub', template: '<div />' }
const RestTimerStub = { name: 'RestTimerStub', props: ['duration', 'autoRestTimer'], template: '<div />' }

const mountPage = async (workout = strengthWorkout, exercises = [STRENGTH, CARDIO, TIMED]) => {
    const wrapper = mount(WorkoutShow, {
        props: { workout: JSON.parse(JSON.stringify(workout)), exercises },
        shallow: true,
        global: {
            directives: { press: {} },
            stubs: {
                AuthenticatedLayout: LayoutStub,
                GlassCard: passesSlot,
                SwipeableRow: SwipeStub,
                Modal: ModalStub,
                GlassButton: ButtonStub,
                GlassIconButton: IconButtonStub,
                GlassInput: FieldStub,
                GlassSelect: FieldStub,
                WorkoutSettingsModal: SettingsModalStub,
                WorkoutFinishModal: FinishModalStub,
                RestTimer: RestTimerStub,
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

const lines = (wrapper) => wrapper.vm.localWorkout.workout_lines
const flash = () => pageStub.props.flash?.error

const click = async (wrapper, dusk) => {
    const target = wrapper.find(`[dusk="${dusk}"]`)

    expect(target.exists(), `no element marked ${dusk}`).toBe(true)
    await target.trigger('click')
    await flushPromises()
}

const buttonLabelled = (wrapper, text) => wrapper.findAll('button').find((button) => button.text() === text)

const emitOn = async (wrapper, stub, event, ...args) => {
    const component = wrapper.findComponent(stub)

    expect(component.exists(), `no ${stub.name} rendered`).toBe(true)
    component.vm.$emit(event, ...args)
    await flushPromises()
}

const search = async (wrapper, text) => {
    const input = wrapper.findComponent('#search-workout-exercise')

    expect(input.exists(), 'no exercise search field').toBe(true)
    input.vm.$emit('update:modelValue', text)
    await flushPromises()
}

beforeEach(() => {
    localStorage.clear()
    post.mockReset()
    patch.mockReset()
    destroy.mockReset()
    routerPost.mockReset()
    routerPatch.mockReset()
    formPatch.mockReset()
    fetchMock.mockReset()
    haptics.triggerHaptic.mockReset()
    pageStub.props.flash = undefined
    patch.mockResolvedValue({ data: {} })
    destroy.mockResolvedValue({ data: {} })
    post.mockResolvedValue({ data: { data: { id: 999 } } })
    vi.stubGlobal('fetch', fetchMock)
})

afterEach(() => {
    localStorage.clear()
    vi.unstubAllGlobals()
    vi.useRealTimers()
})

/**
 * Every background write on this page can fail, and for a long time the only
 * thing that happened when one did was a haptic pulse: nothing at all on a
 * desktop, nothing for anyone who turned haptics off. The row silently went
 * back the way it was. `reportSyncFailure` is the channel that says so, and it
 * borrows the layout's flash toast rather than inventing a second one.
 */
describe('Workouts/Show — a background write the server refuses says so', () => {
    it('puts back the tick and names what failed when validating a set is refused', async () => {
        patch.mockRejectedValue({ response: { status: 500 } })

        const wrapper = await mountPage()

        await click(wrapper, 'complete-set-0-0')

        expect(lines(wrapper)[0].sets[0].is_completed).toBe(false)
        expect(flash()).toBe('La série n’a pas pu être validée. Réessaie.')
        expect(haptics.triggerHaptic).toHaveBeenCalledWith('error')
    })

    it('leaves the tick alone and says nothing when the validation is merely queued', async () => {
        patch.mockRejectedValue({ isOffline: true })

        const wrapper = await mountPage()

        await click(wrapper, 'complete-set-0-0')

        expect(lines(wrapper)[0].sets[0].is_completed).toBe(true)
        expect(flash()).toBeUndefined()
    })

    it('takes the record flag and the timestamp from the answer, and shows the trophy', async () => {
        patch.mockResolvedValue({
            data: { data: { is_completed: true, personal_record: true, updated_at: '2026-07-29T09:00:00.000000Z' } },
        })

        const wrapper = await mountPage()

        // The other side of the badge: a trophy on every row is a trophy on
        // none, and only the "before" assertion can tell the two apart.
        expect(wrapper.find('[dusk="pr-trophy-0-0"]').exists()).toBe(false)

        await click(wrapper, 'complete-set-0-0')

        const set = lines(wrapper)[0].sets[0]

        expect(set.personal_record).toBe(true)
        expect(set.updated_at).toBe('2026-07-29T09:00:00.000000Z')
        expect(wrapper.find('[dusk="pr-trophy-0-0"]').exists()).toBe(true)
    })

    it('rests for the exercise’s own time, and stops resting when the timer says it is done', async () => {
        const wrapper = await mountPage()

        await click(wrapper, 'complete-set-0-0')

        const timer = wrapper.findComponent(RestTimerStub)

        expect(timer.exists()).toBe(true)
        // 90 is the exercise's default_rest_time; 120 is the user's, which only
        // applies when the exercise has none.
        expect(timer.props('duration')).toBe(90)

        await emitOn(wrapper, RestTimerStub, 'finished')

        expect(wrapper.findComponent(RestTimerStub).exists()).toBe(false)
    })

    it('falls back to the user’s own rest time when the exercise has none', async () => {
        const wrapper = await mountPage(
            session({
                workout_lines: [
                    {
                        id: 10,
                        order: 0,
                        exercise: { ...STRENGTH, default_rest_time: null },
                        sets: [{ id: 42, weight: 80, reps: 5, is_completed: false }],
                    },
                ],
            }),
        )

        await click(wrapper, 'complete-set-0-0')

        expect(wrapper.findComponent(RestTimerStub).props('duration')).toBe(120)
    })

    it('closes the rest timer when the user dismisses it', async () => {
        const wrapper = await mountPage()

        await click(wrapper, 'complete-set-0-0')
        await emitOn(wrapper, RestTimerStub, 'close')

        expect(wrapper.findComponent(RestTimerStub).exists()).toBe(false)
    })

    /**
     * Sans ce bouton, couper le démarrage automatique était irréversible depuis
     * l'interface : plus rien n'ouvrait le minuteur, et l'interrupteur qui le
     * rallume vit DANS le minuteur.
     */
    it('opens the timer on demand even when auto-start is off', async () => {
        pageStub.props.auth.user.auto_rest_timer = false

        try {
            const wrapper = await mountPage()

            await click(wrapper, 'complete-set-0-0')
            expect(wrapper.findComponent(RestTimerStub).exists()).toBe(false)

            await click(wrapper, 'open-rest-timer')

            const timer = wrapper.findComponent(RestTimerStub)

            expect(timer.exists()).toBe(true)
            // Le temps de repos du COMPTE, pas celui de l'exercice : le geste
            // n'est attaché à aucune série.
            expect(timer.props('duration')).toBe(120)
            expect(timer.props('autoRestTimer')).toBe(false)
        } finally {
            pageStub.props.auth.user.auto_rest_timer = true
        }
    })

    /**
     * Le contrat de #1313 : le minuteur s'ouvrait TOUJOURS. Le réglage doit
     * pouvoir le taire — sans quoi il n'y a pas de réglage, seulement un
     * interrupteur qui ne commande rien.
     */
    it('does not open the timer at all when auto-start is off', async () => {
        pageStub.props.auth.user.auto_rest_timer = false

        try {
            const wrapper = await mountPage()

            await click(wrapper, 'complete-set-0-0')

            expect(wrapper.findComponent(RestTimerStub).exists()).toBe(false)
        } finally {
            pageStub.props.auth.user.auto_rest_timer = true
        }
    })

    it('writes the preference back without leaving the session', async () => {
        const wrapper = await mountPage()

        await click(wrapper, 'complete-set-0-0')
        await emitOn(wrapper, RestTimerStub, 'update:autoRestTimer', false)

        expect(routerPatch).toHaveBeenCalledWith(
            expect.stringContaining('profile.rest-timer.update'),
            { auto_rest_timer: false },
            expect.objectContaining({ preserveScroll: true, preserveState: true }),
        )

        // Et l'effet est immédiat, sans attendre la réponse : décocher puis
        // recocher la série ne rouvre plus rien.
        await emitOn(wrapper, RestTimerStub, 'close')
        await click(wrapper, 'complete-set-0-0')
        await click(wrapper, 'complete-set-0-0')

        expect(wrapper.findComponent(RestTimerStub).exists()).toBe(false)
    })
})

/**
 * Removing an exercise takes every set under it with it, so it asks first. The
 * confirmation is a modal holding a closure, and the closure is where all the
 * work is: cancelling must leave the session untouched, and confirming must not
 * leave a debounced edit pointing at a row that is on its way out.
 */
describe('Workouts/Show — removing an exercise', () => {
    it('names the exercise in the question it asks', async () => {
        const wrapper = await mountPage()

        await click(wrapper, 'remove-line-0')

        expect(wrapper.text()).toContain('Supprimer Développé couché ?')
        expect(lines(wrapper)).toHaveLength(1)
    })

    it('changes nothing when the question is answered no', async () => {
        const wrapper = await mountPage()

        await click(wrapper, 'remove-line-0')
        await buttonLabelled(wrapper, 'Annuler').trigger('click')
        await flushPromises()

        expect(wrapper.vm.showConfirmModal).toBe(false)
        expect(lines(wrapper)).toHaveLength(1)
        expect(destroy).not.toHaveBeenCalled()
    })

    it('changes nothing when the question is dismissed rather than answered', async () => {
        const wrapper = await mountPage()

        await click(wrapper, 'remove-line-0')

        // The confirmation is the second modal on the page; the first is the
        // exercise picker.
        wrapper.findAllComponents(ModalStub)[1].vm.$emit('close')
        await flushPromises()

        expect(wrapper.vm.showConfirmModal).toBe(false)
        expect(lines(wrapper)).toHaveLength(1)
        expect(destroy).not.toHaveBeenCalled()
    })

    it('removes it on screen and tells the server', async () => {
        const wrapper = await mountPage()

        await click(wrapper, 'remove-line-0')
        await click(wrapper, 'confirm-delete-button')

        expect(lines(wrapper)).toHaveLength(0)
        expect(destroy).toHaveBeenCalledWith('/api/v1/workout-lines/10')
    })

    /**
     * The edit was debounced by a second and the row it belongs to is gone, so
     * the PATCH could only ever 404 — and until it was cancelled it went out
     * anyway, a second after the exercise had left the screen.
     */
    it('cancels a debounced edit belonging to a set it is taking away', async () => {
        const wrapper = await mountPage()

        const weight = wrapper.find('[dusk="weight-input-0-0"]')
        weight.element.value = '95'
        await weight.trigger('change')

        await click(wrapper, 'remove-line-0')
        await click(wrapper, 'confirm-delete-button')

        await new Promise((resolve) => setTimeout(resolve, 1200))
        await flushPromises()

        expect(patch).not.toHaveBeenCalled()
    })

    it('puts it back where it was when the server refuses the removal', async () => {
        destroy.mockRejectedValue({ response: { status: 500 } })

        const wrapper = await mountPage(
            session({
                workout_lines: [
                    { id: 10, order: 0, exercise: STRENGTH, sets: [] },
                    { id: 11, order: 1, exercise: CARDIO, sets: [] },
                ],
            }),
        )

        await click(wrapper, 'remove-line-0')
        await click(wrapper, 'confirm-delete-button')

        expect(lines(wrapper).map((line) => line.id)).toEqual([10, 11])
        expect(flash()).toBe('L’exercice n’a pas pu être retiré de la séance. Réessaie.')
    })

    it('leaves it removed when the removal was merely queued', async () => {
        destroy.mockRejectedValue({ isOffline: true })

        const wrapper = await mountPage()

        await click(wrapper, 'remove-line-0')
        await click(wrapper, 'confirm-delete-button')

        expect(lines(wrapper)).toHaveLength(0)
        expect(flash()).toBeUndefined()
    })

    /**
     * `temp-1` is not a row the server has, so deleting it 404s. The exercise is
     * simply forgotten instead — and nothing is sent.
     */
    it('sends nothing for an exercise the server never got', async () => {
        post.mockRejectedValue({ isOffline: true })

        const wrapper = await mountPage(session())

        wrapper.vm.showAddExercise = true
        await wrapper.vm.$nextTick()
        await click(wrapper, `select-exercise-${STRENGTH.id}`)

        expect(String(lines(wrapper)[0].id)).toMatch(/^temp-/)

        await click(wrapper, 'remove-line-0')
        await click(wrapper, 'confirm-delete-button')

        expect(lines(wrapper)).toHaveLength(0)
        expect(destroy).not.toHaveBeenCalled()
    })
})

/**
 * Creating an exercise from inside the session used to swallow everything that
 * was not a 2xx: the modal stayed open with no message and the user just kept
 * tapping the button.
 */
describe('Workouts/Show — creating an exercise without leaving the session', () => {
    const openCreateForm = async (wrapper, name = 'Rowing') => {
        wrapper.vm.showAddExercise = true
        await wrapper.vm.$nextTick()
        await search(wrapper, name)
        await click(wrapper, 'quick-create-exercise')

        return wrapper.find('form')
    }

    it('offers to create what was searched for when nothing matches, pre-filled', async () => {
        const wrapper = await mountPage()

        wrapper.vm.showAddExercise = true
        await wrapper.vm.$nextTick()

        // A search that matches narrows the list rather than offering a creation.
        await search(wrapper, 'développé')
        expect(wrapper.findAll('[dusk^="select-exercise-"]')).toHaveLength(1)
        expect(wrapper.find('[dusk="quick-create-exercise"]').exists()).toBe(false)

        await search(wrapper, 'Rowing')
        expect(wrapper.find('[dusk="quick-create-exercise"]').exists()).toBe(true)

        await click(wrapper, 'quick-create-exercise')

        expect(wrapper.vm.showCreateForm).toBe(true)
        expect(wrapper.vm.createExerciseForm.name).toBe('Rowing')
        // Kept across a reload of the modal, which is why it is written down.
        expect(localStorage.getItem('gymtracker_add_exercise_search')).toBe('Rowing')
    })

    it('adds the created exercise to the session and to the picker', async () => {
        const created = { id: 77, name: 'Rowing', type: 'strength', category: 'Dos' }
        fetchMock.mockResolvedValue({ ok: true, status: 201, json: async () => ({ exercise: created }) })
        post.mockResolvedValue({ data: { data: { id: 30, order: 0, exercise: created, sets: [] } } })

        document.head.innerHTML = '<meta name="csrf-token" content="tok-42">'

        const wrapper = await mountPage()
        const form = await openCreateForm(wrapper)

        await form.trigger('submit')
        await flushPromises()

        const [url, request] = fetchMock.mock.calls[0]

        expect(url).toBe('/exercises')
        expect(request.headers['X-CSRF-TOKEN']).toBe('tok-42')
        expect(JSON.parse(request.body)).toEqual({ name: 'Rowing', type: 'strength', category: 'Pectoraux' })

        expect(wrapper.vm.showCreateForm).toBe(false)
        expect(wrapper.vm.createExerciseForm.processing).toBe(false)
        expect(post).toHaveBeenCalledWith('/api/v1/workout-lines', { workout_id: 1, exercise_id: 77 })
        expect(lines(wrapper).map((line) => line.exercise.name)).toContain('Rowing')

        document.head.innerHTML = ''
    })

    it('shows the field errors the server sent back instead of staying blank', async () => {
        fetchMock.mockResolvedValue({
            ok: false,
            status: 422,
            json: async () => ({ errors: { name: ['Ce nom est déjà pris.'] } }),
        })

        const wrapper = await mountPage()
        const form = await openCreateForm(wrapper)

        await form.trigger('submit')
        await flushPromises()

        expect(wrapper.vm.createExerciseForm.errors.name).toBe('Ce nom est déjà pris.')
        expect(wrapper.vm.showCreateForm).toBe(true)
        expect(haptics.triggerHaptic).toHaveBeenCalledWith('error')
        expect(post).not.toHaveBeenCalled()
    })

    /**
     * The `processing` assertions in this describe are all "false afterwards",
     * and that alone is satisfied by a button which never went busy at all. The
     * request is held open here so the busy state is witnessed before the
     * release is asserted.
     */
    it('says something of its own when the server fails without saying why', async () => {
        const answer = deferred()
        fetchMock.mockReturnValue(answer.promise)

        const wrapper = await mountPage()
        const form = await openCreateForm(wrapper)

        await form.trigger('submit')
        await flushPromises()

        expect(wrapper.vm.createExerciseForm.processing, 'the button never went busy').toBe(true)

        answer.resolve({ ok: false, status: 500, json: async () => ({}) })
        await flushPromises()

        expect(wrapper.vm.createExerciseForm.errors.name).toBe('La création a échoué. Réessaie dans un instant.')
        expect(wrapper.vm.createExerciseForm.processing).toBe(false)
    })

    it('blames the connection when the request never lands', async () => {
        const silenced = vi.spyOn(console, 'error').mockImplementation(() => {})
        fetchMock.mockRejectedValue(new TypeError('Failed to fetch'))

        const wrapper = await mountPage()
        const form = await openCreateForm(wrapper)

        await form.trigger('submit')
        await flushPromises()

        expect(wrapper.vm.createExerciseForm.errors.name).toBe('Connexion impossible. Vérifie ta connexion réseau.')
        expect(wrapper.vm.createExerciseForm.processing).toBe(false)

        silenced.mockRestore()
    })

    it('carries the type and category the user picked', async () => {
        fetchMock.mockResolvedValue({ ok: false, status: 500, json: async () => ({}) })

        const wrapper = await mountPage()
        const form = await openCreateForm(wrapper)

        wrapper.findComponent('[dusk="new-exercise-name"]').vm.$emit('update:modelValue', 'Tirage')
        wrapper.findComponent('[dusk="new-exercise-type"]').vm.$emit('update:modelValue', 'timed')
        wrapper.findComponent('[dusk="new-exercise-category"]').vm.$emit('update:modelValue', 'Dos')
        await flushPromises()

        await form.trigger('submit')
        await flushPromises()

        expect(JSON.parse(fetchMock.mock.calls[0][1].body)).toEqual({
            name: 'Tirage',
            type: 'timed',
            category: 'Dos',
        })
    })

    it('goes back to the picker without losing the session', async () => {
        const wrapper = await mountPage()

        await openCreateForm(wrapper)
        await wrapper.find('button[aria-label="Retour"]').trigger('click')

        expect(wrapper.vm.showCreateForm).toBe(false)
        expect(wrapper.find('#search-workout-exercise').exists()).toBe(true)
    })

    it('forgets the search and the half-filled form when the modal is closed', async () => {
        const wrapper = await mountPage()

        await openCreateForm(wrapper)
        await emitOn(wrapper, ModalStub, 'close')

        expect(wrapper.vm.showAddExercise).toBe(false)
        expect(wrapper.vm.showCreateForm).toBe(false)
        expect(wrapper.vm.searchQuery).toBe('')
    })
})

/**
 * The two other things the header offers — renaming the session and finishing
 * it — both leave through Inertia rather than the sync queue, and both have a
 * modal in the way.
 */
describe('Workouts/Show — the session’s own settings', () => {
    it('sends the start time back as UTC after the form held it in local time', async () => {
        const wrapper = await mountPage()

        await click(wrapper, 'workout-settings-button')

        expect(wrapper.vm.showSettingsModal).toBe(true)
        // 08:00Z is 04:00 in New York, and the field holds what the clock says.
        expect(wrapper.vm.settingsForm.started_at).toBe('2026-07-29T04:00')

        wrapper.vm.settingsForm.name = 'Séance du soir'
        await emitOn(wrapper, SettingsModalStub, 'submit')

        const [url, payload, options] = formPatch.mock.calls[0]

        expect(url).toBe('/workouts/1')
        expect(payload).toEqual({
            name: 'Séance du soir',
            started_at: '2026-07-29T08:00:00.000Z',
            notes: '',
        })

        options.onSuccess()
        await wrapper.vm.$nextTick()

        expect(wrapper.vm.showSettingsModal).toBe(false)
    })

    it('closes the settings without sending anything', async () => {
        const wrapper = await mountPage()

        await click(wrapper, 'workout-settings-button')
        await emitOn(wrapper, SettingsModalStub, 'close')

        expect(wrapper.vm.showSettingsModal).toBe(false)
        expect(formPatch).not.toHaveBeenCalled()
    })

    it('asks before finishing, and only then closes the session', async () => {
        const wrapper = await mountPage()

        await buttonLabelled(wrapper, 'Terminer').trigger('click')
        await flushPromises()

        expect(wrapper.vm.showFinishModal).toBe(true)
        expect(routerPatch).not.toHaveBeenCalled()

        await emitOn(wrapper, FinishModalStub, 'confirm')

        const [url, payload, options] = routerPatch.mock.calls[0]

        expect(url).toBe('/workouts/1')
        expect(payload).toEqual({ is_finished: true })

        options.onStart()
        await wrapper.vm.$nextTick()
        expect(wrapper.vm.showFinishModal).toBe(false)

        options.onSuccess()
        expect(haptics.triggerHaptic).toHaveBeenCalledWith('success')
    })

    it('changes its mind about finishing', async () => {
        const wrapper = await mountPage()

        await buttonLabelled(wrapper, 'Terminer').trigger('click')
        await emitOn(wrapper, FinishModalStub, 'close')

        expect(wrapper.vm.showFinishModal).toBe(false)
        expect(routerPatch).not.toHaveBeenCalled()
    })

    it('keeps the button busy until saving the session as a template comes back', async () => {
        const wrapper = await mountPage()

        await buttonLabelled(wrapper, 'Modèle').trigger('click')
        await flushPromises()

        const [url, payload, options] = routerPost.mock.calls[0]

        expect(url).toBe('/workouts/1/template')
        expect(payload).toEqual({})
        expect(wrapper.vm.savingTemplate).toBe(true)

        options.onFinish()
        await wrapper.vm.$nextTick()

        expect(wrapper.vm.savingTemplate).toBe(false)
    })
})

/**
 * The picker is reached three ways — the empty state, the button under a
 * populated session, and the floating button the layout owns, which talks to
 * this page through a window event.
 */
describe('Workouts/Show — reaching the exercise picker', () => {
    it('opens from the empty session', async () => {
        const wrapper = await mountPage(session())

        expect(wrapper.text()).toContain('Séance vide')

        await click(wrapper, 'add-first-exercise')

        expect(wrapper.vm.showAddExercise).toBe(true)
    })

    it('opens from under a session that already has exercises', async () => {
        const wrapper = await mountPage()

        await click(wrapper, 'add-exercise-existing')

        expect(wrapper.vm.showAddExercise).toBe(true)
    })

    it('opens when the layout’s floating button asks it to', async () => {
        const wrapper = await mountPage()

        expect(wrapper.vm.showAddExercise).toBe(false)

        window.dispatchEvent(new CustomEvent('open-add-exercise'))
        await flushPromises()

        expect(wrapper.vm.showAddExercise).toBe(true)
    })

    it('ignores an exercise that is not in the picker', async () => {
        const wrapper = await mountPage()

        wrapper.vm.addExercise(4242)
        await flushPromises()

        expect(lines(wrapper)).toHaveLength(1)
        expect(post).not.toHaveBeenCalled()
    })
})

/**
 * The server's copy is authoritative about everything it knows, and about
 * nothing else. These are the shapes it can arrive in, and the things it must
 * not take away.
 */
describe('Workouts/Show — reconciling with what the server sends', () => {
    const objectMap = (linesArray) => Object.fromEntries(linesArray.map((line, index) => [String(index), line]))

    it('reads lines handed over as an object map on first render', async () => {
        const wrapper = await mountPage(session({ workout_lines: objectMap(strengthWorkout.workout_lines) }))

        expect(Array.isArray(lines(wrapper))).toBe(true)
        expect(wrapper.find('[dusk="exercise-card-0"]').exists()).toBe(true)
    })

    it('reads lines handed over as an object map on a later round trip', async () => {
        const wrapper = await mountPage(session())

        await wrapper.setProps({
            workout: session({ workout_lines: objectMap(strengthWorkout.workout_lines) }),
        })
        await flushPromises()

        expect(Array.isArray(lines(wrapper))).toBe(true)
        expect(lines(wrapper)[0].id).toBe(10)
    })

    it('survives a round trip that carries no lines at all', async () => {
        const wrapper = await mountPage()

        await wrapper.setProps({ workout: session({ workout_lines: undefined }) })
        await flushPromises()

        expect(lines(wrapper)).toEqual([])
        expect(wrapper.text()).toContain('Séance vide')
    })

    it('survives a line that arrives with no sets array', async () => {
        const wrapper = await mountPage()

        await wrapper.setProps({
            workout: session({ workout_lines: [{ id: 10, order: 0, exercise: STRENGTH, sets: null }] }),
        })
        await flushPromises()

        expect(lines(wrapper)[0].sets).toEqual([])
        expect(wrapper.find('[dusk="exercise-card-0"]').exists()).toBe(true)
    })

    it('takes an exercise it has never seen straight from the server', async () => {
        const wrapper = await mountPage()

        await wrapper.setProps({
            workout: session({
                workout_lines: [
                    ...strengthWorkout.workout_lines,
                    { id: 11, order: 1, exercise: CARDIO, sets: [{ id: 50, distance_km: 3, is_completed: false }] },
                ],
            }),
        })
        await flushPromises()

        expect(lines(wrapper).map((line) => line.id)).toEqual([10, 11])
        expect(lines(wrapper)[1].sets).toHaveLength(1)
    })

    it('keeps an exercise the server has never heard of', async () => {
        const creation = deferred()
        post.mockImplementation((url) => (url.includes('workout-lines') ? creation.promise : Promise.resolve({})))

        const wrapper = await mountPage(session())

        wrapper.vm.showAddExercise = true
        await wrapper.vm.$nextTick()
        await click(wrapper, `select-exercise-${STRENGTH.id}`)

        expect(String(lines(wrapper)[0].id)).toMatch(/^temp-/)

        await wrapper.setProps({ workout: session({ name: 'Renommée' }) })
        await flushPromises()

        expect(wrapper.vm.localWorkout.name).toBe('Renommée')
        expect(lines(wrapper)).toHaveLength(1)
        expect(String(lines(wrapper)[0].id)).toMatch(/^temp-/)

        creation.resolve({ data: { data: { id: 10, order: 0, exercise: STRENGTH, sets: [] } } })
        await flushPromises()
    })
})

/**
 * A create can come back with nothing usable, or not come back at all. Neither
 * may leave a row on screen pretending to be saved.
 */
describe('Workouts/Show — a create that answers with nothing', () => {
    it('leaves the exercise on its placeholder when the answer carries no record', async () => {
        post.mockResolvedValue({ data: {} })

        const wrapper = await mountPage(session())

        wrapper.vm.showAddExercise = true
        await wrapper.vm.$nextTick()
        await click(wrapper, `select-exercise-${STRENGTH.id}`)

        expect(String(lines(wrapper)[0].id)).toMatch(/^temp-/)
    })

    it('takes the sets a replayed create came back carrying, once each', async () => {
        post.mockResolvedValue({
            data: {
                data: {
                    id: 10,
                    order: 0,
                    exercise: STRENGTH,
                    sets: [
                        { id: 60, weight: 40, reps: 12, is_completed: false },
                        { id: 60, weight: 40, reps: 12, is_completed: false },
                    ],
                },
            },
        })

        const wrapper = await mountPage(session())

        wrapper.vm.showAddExercise = true
        await wrapper.vm.$nextTick()
        await click(wrapper, `select-exercise-${STRENGTH.id}`)

        expect(lines(wrapper)[0].id).toBe(10)
        expect(lines(wrapper)[0].sets.map((set) => set.id)).toEqual([60])
    })

    it('takes the exercise back off screen when the server refuses it', async () => {
        post.mockRejectedValue({ response: { status: 500 } })

        const wrapper = await mountPage(session())

        wrapper.vm.showAddExercise = true
        await wrapper.vm.$nextTick()
        await click(wrapper, `select-exercise-${STRENGTH.id}`)

        expect(lines(wrapper)).toHaveLength(0)
        expect(flash()).toBe('L’exercice n’a pas pu être ajouté à la séance. Réessaie.')
    })

    it('leaves the set on its placeholder when the answer carries no record', async () => {
        post.mockResolvedValue({ data: {} })

        const wrapper = await mountPage()

        await click(wrapper, 'add-set-0')

        expect(String(lines(wrapper)[0].sets[1].id)).toMatch(/^temp-/)
    })

    it('takes the set back off screen when the server refuses it', async () => {
        post.mockRejectedValue({ response: { status: 422 } })

        const wrapper = await mountPage()

        await click(wrapper, 'add-set-0')

        expect(lines(wrapper)[0].sets).toHaveLength(1)
        expect(flash()).toBe('La série n’a pas pu être ajoutée. Réessaie.')
    })

    /**
     * The chain that keeps two creates from racing must not become a chain that
     * stops after the first failure: a refused create may hold the next one
     * back, never cancel it.
     */
    it('still sends the next set after one was refused', async () => {
        post.mockRejectedValueOnce({ response: { status: 422 } }).mockResolvedValue({ data: { data: { id: 61 } } })

        const wrapper = await mountPage()

        await click(wrapper, 'add-set-0')
        await click(wrapper, 'add-set-0')

        expect(post).toHaveBeenCalledTimes(2)
        expect(lines(wrapper)[0].sets.map((set) => set.id)).toEqual([42, 61])
    })

    it('leaves the set on screen and marks it when the create was merely queued', async () => {
        post.mockRejectedValue({ isOffline: true })

        const wrapper = await mountPage()

        await click(wrapper, 'add-set-0')

        expect(lines(wrapper)[0].sets).toHaveLength(2)
        expect(wrapper.find('[dusk="set-unsynced-0-1"]').exists()).toBe(true)
        expect(flash()).toBeUndefined()
    })

    it('ignores a set added to an exercise that is not there', async () => {
        const wrapper = await mountPage()

        wrapper.vm.addSet('no-such-line')
        await flushPromises()

        expect(lines(wrapper)[0].sets).toHaveLength(1)
        expect(post).not.toHaveBeenCalled()
    })

    it('gives a line whose sets are not an array one it can push into', async () => {
        const wrapper = await mountPage(
            session({ workout_lines: [{ id: 10, order: 0, exercise: STRENGTH, sets: {} }] }),
        )

        await click(wrapper, 'add-set-0')

        expect(Array.isArray(lines(wrapper)[0].sets)).toBe(true)
        expect(lines(wrapper)[0].sets).toHaveLength(1)
        // No previous set to copy, and no recommendation: the bare pre-fill.
        expect(post).toHaveBeenCalledWith(
            '/api/v1/sets',
            expect.objectContaining({ workout_line_id: 10, weight: 0, reps: 10 }),
        )
    })

    it('copies the recommendation when there is no previous set to copy', async () => {
        const wrapper = await mountPage(
            session({
                workout_lines: [
                    {
                        id: 10,
                        order: 0,
                        exercise: STRENGTH,
                        sets: [],
                        recommended_values: { weight: 62.5, reps: 8 },
                    },
                ],
            }),
        )

        await click(wrapper, 'add-set-0')

        expect(post).toHaveBeenCalledWith(
            '/api/v1/sets',
            expect.objectContaining({ workout_line_id: 10, weight: 62.5, reps: 8 }),
        )
    })
})

/**
 * A set added onto an exercise that is only queued has nowhere to go. Nothing
 * about it may be sent — a PATCH would name a row the server does not have —
 * and the row has to say so on screen rather than look saved.
 */
describe('Workouts/Show — a set on an exercise that never reached the server', () => {
    const queuedLine = async () => {
        post.mockImplementation((url) =>
            url.includes('workout-lines')
                ? Promise.reject({ isOffline: true })
                : Promise.resolve({ data: { data: { id: 88 } } }),
        )

        const wrapper = await mountPage(session())

        wrapper.vm.showAddExercise = true
        await wrapper.vm.$nextTick()
        await click(wrapper, `select-exercise-${STRENGTH.id}`)
        await click(wrapper, 'add-set-0')

        return wrapper
    }

    /**
     * Said now, not when the drain eventually reports. The exercise is sitting
     * in the queue with a drain that has not come, so the row cannot be resolved
     * either way yet — and while it cannot, it must not look saved. A queue id
     * is deliberately given here so the only marking that can have happened is
     * the eager one.
     */
    it('marks it unsaved the moment it is added, not when the queue drains', async () => {
        post.mockImplementation((url) =>
            url.includes('workout-lines')
                ? Promise.reject({ isOffline: true, queueId: 'q-mine' })
                : Promise.resolve({ data: { data: { id: 88 } } }),
        )

        const wrapper = await mountPage(session())

        wrapper.vm.showAddExercise = true
        await wrapper.vm.$nextTick()
        await click(wrapper, `select-exercise-${STRENGTH.id}`)
        await click(wrapper, 'add-set-0')

        expect(post).toHaveBeenCalledTimes(1)
        expect(wrapper.find('[dusk="set-unsynced-0-0"]').exists()).toBe(true)
    })

    it('marks it unsaved and sends nothing about it', async () => {
        const wrapper = await queuedLine()

        expect(post).toHaveBeenCalledTimes(1)
        expect(wrapper.find('[dusk="set-unsynced-0-0"]').exists()).toBe(true)
    })

    it('sends no completion for it, and leaves the tick where the user put it', async () => {
        const wrapper = await queuedLine()

        await click(wrapper, 'complete-set-0-0')

        expect(patch).not.toHaveBeenCalled()
        expect(lines(wrapper)[0].sets[0].is_completed).toBe(true)
        expect(flash()).toBeUndefined()
    })

    it('deletes it from the screen alone, with no request the server could refuse', async () => {
        const wrapper = await queuedLine()

        await click(wrapper, 'remove-set-0-0')

        expect(lines(wrapper)[0].sets).toHaveLength(0)
        expect(destroy).not.toHaveBeenCalled()
        expect(flash()).toBeUndefined()
    })

    /**
     * A queued create with no queue id cannot be waited on: there is no drain
     * that will ever name it. Waiting anyway leaves the row hanging for the life
     * of the page, so it settles to "no such row" instead.
     *
     * The set is added BEFORE the create comes back, so the eager marking has
     * not run: the only thing that can mark this row is the create settling.
     */
    it('does not wait forever on a queued create that has no queue id', async () => {
        const lineCreate = deferred()
        post.mockImplementation((url) =>
            url.includes('workout-lines') ? lineCreate.promise : Promise.resolve({ data: { data: { id: 88 } } }),
        )

        const wrapper = await mountPage(session())

        wrapper.vm.showAddExercise = true
        await wrapper.vm.$nextTick()
        await click(wrapper, `select-exercise-${STRENGTH.id}`)
        await click(wrapper, 'add-set-0')

        expect(wrapper.find('[dusk="set-unsynced-0-0"]').exists()).toBe(false)

        lineCreate.reject({ isOffline: true })
        await flushPromises()

        expect(wrapper.find('[dusk="set-unsynced-0-0"]').exists()).toBe(true)
        expect(post).toHaveBeenCalledTimes(1)
    })

    it('ignores a drain that announces some other queued request', async () => {
        post.mockImplementation((url) =>
            url.includes('workout-lines')
                ? Promise.reject({ isOffline: true, queueId: 'q-mine' })
                : Promise.resolve({ data: { data: { id: 88 } } }),
        )

        const wrapper = await mountPage(session())

        wrapper.vm.showAddExercise = true
        await wrapper.vm.$nextTick()
        await click(wrapper, `select-exercise-${STRENGTH.id}`)
        await click(wrapper, 'add-set-0')

        window.dispatchEvent(new CustomEvent('sync:replayed', { detail: { queueId: 'q-other', data: { id: 99 } } }))
        await flushPromises()

        expect(post).toHaveBeenCalledTimes(1)

        window.dispatchEvent(new CustomEvent('sync:replayed', { detail: { queueId: 'q-mine', data: { id: 31 } } }))
        await flushPromises()

        expect(post).toHaveBeenCalledWith('/api/v1/sets', expect.objectContaining({ workout_line_id: 31 }))
    })

    /**
     * The listener is one per create still waiting on the drain, and the page is
     * very likely to be left before the drain ever comes.
     */
    it('hands back the drain listener when the page is left', async () => {
        post.mockRejectedValue({ isOffline: true, queueId: 'q-mine' })

        const removed = vi.spyOn(window, 'removeEventListener')

        const wrapper = await mountPage(session())

        wrapper.vm.showAddExercise = true
        await wrapper.vm.$nextTick()
        await click(wrapper, `select-exercise-${STRENGTH.id}`)

        wrapper.unmount()

        expect(removed.mock.calls.some(([type]) => type === 'sync:replayed')).toBe(true)

        removed.mockRestore()
    })
})

/**
 * Deleting a set, and what happens when the server will not have it.
 */
describe('Workouts/Show — deleting a set', () => {
    it('deletes from the row’s own button', async () => {
        const wrapper = await mountPage()

        await click(wrapper, 'remove-set-0-0')

        expect(lines(wrapper)[0].sets).toHaveLength(0)
        expect(destroy).toHaveBeenCalledWith('/api/v1/sets/42')
    })

    it('deletes from behind the swipe', async () => {
        const wrapper = await mountPage()

        await click(wrapper, 'swipe-remove-set-0-0')

        expect(lines(wrapper)[0].sets).toHaveLength(0)
        expect(destroy).toHaveBeenCalledWith('/api/v1/sets/42')
    })

    it('puts the set back in its own place when the server refuses', async () => {
        destroy.mockRejectedValue({ response: { status: 500 } })

        const wrapper = await mountPage(
            session({
                workout_lines: [
                    {
                        id: 10,
                        order: 0,
                        exercise: STRENGTH,
                        sets: [
                            { id: 41, weight: 60, reps: 5, is_completed: false },
                            { id: 42, weight: 70, reps: 5, is_completed: false },
                            { id: 43, weight: 80, reps: 5, is_completed: false },
                        ],
                    },
                ],
            }),
        )

        await click(wrapper, 'remove-set-0-1')

        expect(lines(wrapper)[0].sets.map((set) => set.id)).toEqual([41, 42, 43])
        expect(flash()).toBe('La série n’a pas pu être supprimée. Réessaie.')
    })
})

/**
 * What an edit does before it is a request: the value it writes on the row, the
 * draft it leaves behind, and which answer it is willing to believe.
 */
describe('Workouts/Show — editing a value', () => {
    it('records a value on a set the server has not created, without sending or drafting it', async () => {
        const setCreated = deferred()
        post.mockReturnValue(setCreated.promise)

        const wrapper = await mountPage()

        await click(wrapper, 'add-set-0')

        const tempSet = lines(wrapper)[0].sets[1]
        wrapper.vm.updateSet(tempSet, 'weight', '95')

        // A number, not the DOM's string: `'95' !== 95` made the post-create diff
        // fire a redundant PATCH on every set the page ever created.
        expect(tempSet.weight).toBe(95)
        expect(patch).not.toHaveBeenCalled()
        expect(localStorage.getItem(`draft_set_${tempSet.id}`)).toBeNull()

        setCreated.resolve({ data: { data: { id: 77, weight: 80, reps: 5 } } })
        await flushPromises()
    })

    /**
     * Typing into a row while its create is in flight is the normal way to use
     * this screen. The payload left with the old numbers, so the difference has
     * to follow — and when the server will not have it, the row says so.
     */
    it('marks the row when the value typed during the create is then refused', async () => {
        const setCreated = deferred()
        post.mockReturnValue(setCreated.promise)
        patch.mockRejectedValue({ response: { status: 422 } })

        const wrapper = await mountPage()

        await click(wrapper, 'add-set-0')

        wrapper.vm.updateSet(lines(wrapper)[0].sets[1], 'weight', 95)

        setCreated.resolve({ data: { data: { id: 77, weight: 80, reps: 5 } } })
        await flushPromises()

        await wrapper.vm.$nextTick()

        expect(patch).toHaveBeenCalledWith('/api/v1/sets/77', { weight: 95 })

        /**
         * Marked under the id the row now wears, not the placeholder it has
         * just shed. Marking `temp-2` would attach the warning to an id nothing
         * on screen carries any more, which is the same as not marking it.
         */
        expect([...wrapper.vm.unsyncedSetIds]).toEqual(['77'])

        /**
         * And it is on screen, which the state alone did not guarantee.
         *
         * The real id used to be written onto the raw object the closure held
         * rather than the proxy inside `line.sets`, so it landed without
         * tripping any tracker. State said 77, the row still rendered under
         * `temp-2`, and the badge keyed on `set.id` never appeared — a set the
         * server had refused looked saved (#1397). This assertion is the one
         * that was missing.
         */
        expect(wrapper.find('[dusk="set-unsynced-0-1"]').exists()).toBe(true)
    })

    /**
     * Every one of these fields arrives pre-filled from the previous set, and a
     * caret dropped at the end of "80" turns a corrected weight into 8095. The
     * value is selected on focus so the first keystroke replaces it.
     */
    it('selects what is already in a field when it is focused', async () => {
        const strength = await mountPage()

        for (const dusk of ['weight-input-0-0', 'reps-input-0-0']) {
            const input = strength.find(`[dusk="${dusk}"]`)
            const selected = vi.spyOn(input.element, 'select').mockImplementation(() => {})

            await input.trigger('focus')

            expect(selected, `${dusk} does not select its value on focus`).toHaveBeenCalled()
        }

        const cardio = await mountPage(cardioWorkout)
        const distance = cardio.find('[dusk="distance-input-0-0"]')
        const selectedDistance = vi.spyOn(distance.element, 'select').mockImplementation(() => {})

        await distance.trigger('focus')

        expect(selectedDistance).toHaveBeenCalled()
    })

    /**
     * Both boxes, not just one: they sit side by side, carry the same markup and
     * differ only by the field name in their `@change`. A weight box wired to
     * `reps` writes the load into the repetitions and is invisible to any test
     * that only ever types into the other one.
     */
    it('sends the weight and the reps the row was typed with, each under its own name', async () => {
        const wrapper = await mountPage()

        const weight = wrapper.find('[dusk="weight-input-0-0"]')
        weight.element.value = '95'
        await weight.trigger('change')

        const reps = wrapper.find('[dusk="reps-input-0-0"]')
        reps.element.value = '12'
        await reps.trigger('change')

        await new Promise((resolve) => setTimeout(resolve, 1100))
        await flushPromises()

        expect(patch).toHaveBeenCalledWith('/api/v1/sets/42', { weight: 95 })
        expect(patch).toHaveBeenCalledWith('/api/v1/sets/42', { reps: 12 })
    })

    it('sends the duration a timed exercise’s wheel emits', async () => {
        const wrapper = await mountPage(timedWorkout)

        wrapper.findComponent('[dusk="duration-input-0-0"]').vm.$emit('update:modelValue', 240)
        await flushPromises()

        await new Promise((resolve) => setTimeout(resolve, 1100))
        await flushPromises()

        expect(patch).toHaveBeenCalledWith('/api/v1/sets/42', { duration_seconds: 240 })
    })

    /**
     * The refusal of a write that has already been superseded describes a value
     * nobody is looking at any more. Reverting on it would undo the edit that
     * replaced it, which is on screen and has its own request in flight.
     */
    it('does not revert on the refusal of a write a later edit has replaced', async () => {
        const firstSend = deferred()
        patch.mockImplementationOnce(() => firstSend.promise)

        const wrapper = await mountPage()
        const set = lines(wrapper)[0].sets[0]

        wrapper.vm.updateSet(set, 'weight', 100)
        wrapper.vm.flushPendingUpdates(42)
        await flushPromises()

        wrapper.vm.updateSet(set, 'weight', 110)

        firstSend.reject({ response: { status: 422 } })
        await flushPromises()

        expect(set.weight).toBe(110)
        expect(wrapper.find('[dusk="set-edit-error"]').exists()).toBe(false)
    })

    it('starts a clean draft when the one on disk is unreadable', async () => {
        const wrapper = await mountPage()

        localStorage.setItem('draft_set_42', '{not json')

        wrapper.vm.updateSet(lines(wrapper)[0].sets[0], 'weight', 100)

        expect(JSON.parse(localStorage.getItem('draft_set_42'))).toEqual({ weight: 100 })
    })
})

/**
 * Drafts written offline are replayed on the next mount. Every measured field
 * has to travel — a cardio session's distance and duration used to be dropped
 * on the floor while the weight and reps of a strength one went through.
 */
describe('Workouts/Show — replaying a draft on mount', () => {
    it('replays the distance and the duration of a cardio set', async () => {
        localStorage.setItem('draft_set_42', JSON.stringify({ distance_km: 7.5, duration_seconds: 1500 }))

        const wrapper = await mountPage(cardioWorkout)

        expect(patch).toHaveBeenCalledWith('/api/v1/sets/42', { distance_km: 7.5, duration_seconds: 1500 })
        expect(lines(wrapper)[0].sets[0].distance_km).toBe(7.5)
        expect(lines(wrapper)[0].sets[0].duration_seconds).toBe(1500)

        wrapper.unmount()
    })

    it('replays the weight and the reps of a strength set', async () => {
        localStorage.setItem('draft_set_42', JSON.stringify({ weight: 95, reps: 3 }))

        const wrapper = await mountPage()

        expect(patch).toHaveBeenCalledWith('/api/v1/sets/42', { weight: 95, reps: 3 })
        expect(lines(wrapper)[0].sets[0].reps).toBe(3)

        wrapper.unmount()
    })

    it('throws away a draft it cannot read rather than choking on it', async () => {
        localStorage.setItem('draft_set_42', '{half written')

        const wrapper = await mountPage()

        expect(localStorage.getItem('draft_set_42')).toBeNull()
        expect(lines(wrapper)[0].sets[0].weight).toBe(80)
        expect(patch).not.toHaveBeenCalled()

        wrapper.unmount()
    })
})

/**
 * The banner that names a refused create is the only thing standing between the
 * user and a set that quietly never existed, so it has to be readable and it
 * has to go away by itself.
 */
describe('Workouts/Show — the refusal banner', () => {
    const announce = (url, data) =>
        window.dispatchEvent(new CustomEvent('sync:failed', { detail: { url, status: 422, data } }))

    it('is vague rather than wrong when the payload cannot be read at all', async () => {
        const wrapper = await mountPage()

        announce('/api/v1/sets', '{ truncated')
        await wrapper.vm.$nextTick()

        expect(wrapper.find('[dusk="set-edit-error"]').text()).toContain('Un élément de la séance')
    })

    it('is vague rather than wrong when the exercise is not in the picker', async () => {
        const wrapper = await mountPage(strengthWorkout, [])

        announce('/api/v1/workout-lines', { exercise_id: 5 })
        await wrapper.vm.$nextTick()

        expect(wrapper.find('[dusk="set-edit-error"]').text()).toBe("Un exercice n'a pas pu être ajouté.")
    })

    /**
     * Both sides of the six seconds. Advancing straight to 6000 and finding the
     * banner gone says only that it leaves at some point *before* then — a
     * banner that gave up after two seconds passed that just as happily, and two
     * seconds is not long enough to read a sentence naming a set.
     */
    it('stays up for six seconds, then takes itself off screen', async () => {
        vi.useFakeTimers()

        const wrapper = await mountPage()

        announce('/api/v1/sets', { workout_line_id: 10 })
        await wrapper.vm.$nextTick()

        expect(wrapper.find('[dusk="set-edit-error"]').exists()).toBe(true)

        vi.advanceTimersByTime(5999)
        await wrapper.vm.$nextTick()

        expect(wrapper.find('[dusk="set-edit-error"]').exists(), 'gone before its six seconds were up').toBe(true)

        vi.advanceTimersByTime(1)
        await wrapper.vm.$nextTick()

        expect(wrapper.find('[dusk="set-edit-error"]').exists()).toBe(false)
    })

    /**
     * A second refusal restarts the six seconds. Without that the first one's
     * timer takes the second one's message off screen early — here, after two
     * seconds of it being visible instead of six.
     */
    it('gives a second refusal its own six seconds', async () => {
        vi.useFakeTimers()

        const wrapper = await mountPage()

        announce('/api/v1/workout-lines', { exercise_id: 5 })
        await wrapper.vm.$nextTick()

        vi.advanceTimersByTime(4000)

        announce('/api/v1/sets', { workout_line_id: 10 })
        await wrapper.vm.$nextTick()

        vi.advanceTimersByTime(4000)
        await wrapper.vm.$nextTick()

        const banner = wrapper.find('[dusk="set-edit-error"]')

        expect(banner.exists()).toBe(true)
        expect(banner.text()).toContain('Développé couché')
    })
})

describe('validating and unvalidating a set in quick succession', () => {
    /** A PATCH answer shaped like the API's, for a given completion state. */
    const completionAnswer = (id, isCompleted, updatedAt) => ({
        data: { data: { id, is_completed: isCompleted, personal_record: null, updated_at: updatedAt } },
    })

    it('keeps the last tap, whichever answer comes home first', async () => {
        const wrapper = await mountPage()
        const set = lines(wrapper)[0].sets[0]

        const first = deferred()
        const second = deferred()
        patch.mockReturnValueOnce(first.promise).mockReturnValueOnce(second.promise)

        // Tick, then untick, without waiting: the button is only disabled once
        // the session is finished, so two PATCH for the same row really can be
        // in flight together.
        wrapper.vm.toggleSetCompletion(set, 90)
        await flushPromises()
        wrapper.vm.toggleSetCompletion(set, 90)
        await flushPromises()

        // The newer answer lands first, the older one after it — the ordering
        // that used to tick the box back on and leave the server holding it.
        second.resolve(completionAnswer(set.id, false, '2026-08-13T10:00:02Z'))
        await flushPromises()
        first.resolve(completionAnswer(set.id, true, '2026-08-13T10:00:01Z'))
        await flushPromises()

        expect(set.is_completed).toBe(false)
        expect(set.updated_at).toBe('2026-08-13T10:00:02Z')
    })

    it('sends the second tap only once the first has been answered', async () => {
        const wrapper = await mountPage()
        const set = lines(wrapper)[0].sets[0]

        const first = deferred()
        patch.mockReturnValueOnce(first.promise)

        wrapper.vm.toggleSetCompletion(set, 90)
        await flushPromises()
        wrapper.vm.toggleSetCompletion(set, 90)
        await flushPromises()

        // Queued, not overlapped. Two requests racing on one row is what the
        // server sees as a lost update, whichever way the screen resolves it.
        expect(patch).toHaveBeenCalledTimes(1)

        first.resolve(completionAnswer(set.id, true, '2026-08-13T10:00:01Z'))
        await flushPromises()

        expect(patch).toHaveBeenCalledTimes(2)
    })

    it('does not roll a stale refusal back over the newer tap', async () => {
        const wrapper = await mountPage()
        const set = lines(wrapper)[0].sets[0]

        const first = deferred()
        const second = deferred()
        patch.mockReturnValueOnce(first.promise).mockReturnValueOnce(second.promise)

        wrapper.vm.toggleSetCompletion(set, 90)
        await flushPromises()
        wrapper.vm.toggleSetCompletion(set, 90)
        await flushPromises()

        second.resolve(completionAnswer(set.id, false, '2026-08-13T10:00:02Z'))
        await flushPromises()

        // The overtaken request now fails. Its rollback belongs to a tap the
        // user has already replaced, so it must not be applied.
        first.reject({ response: { status: 500 } })
        await flushPromises()

        expect(set.is_completed).toBe(false)
    })
})

/**
 * Reproduction : les deux valeurs tapees pendant la creation doivent partir.
 *
 * Le test voisin n'en tape qu'une. La seance reelle en tape deux — le poids puis
 * les repetitions — et c'est ce cas que la CI voit echouer (#1489).
 *
 * Les valeurs sont choisies loin de tout pre-remplissage : une valeur egale a
 * celle deja partie dans la charge de creation ne produit aucun ecart, donc
 * aucune requete, et le test ne separerait rien.
 */
describe('Workouts/Show — deux valeurs tapées pendant la création', () => {
    it('envoie le poids ET les répétitions saisis avant la réponse', async () => {
        const setCreated = deferred()
        post.mockReturnValue(setCreated.promise)

        const wrapper = await mountPage()

        await click(wrapper, 'add-set-0')

        const tempSet = lines(wrapper)[0].sets[1]

        wrapper.vm.updateSet(tempSet, 'weight', 123)
        wrapper.vm.updateSet(tempSet, 'reps', 7)

        setCreated.resolve({ data: { data: { id: 77, weight: 60, reps: 5 } } })
        await flushPromises()
        await wrapper.vm.$nextTick()

        const corps = patch.mock.calls.map((appel) => appel[1])

        expect(Object.assign({}, ...corps)).toEqual({ weight: 123, reps: 7 })
    })
})

/**
 * Deplacer un exercice pendant une seance.
 *
 * SortableJS ne peut pas etre exerce ici — jsdom n'a pas de pointeur — mais
 * tout ce qui SUIT le glissement l'est : la mutation du tableau, l'ecriture, le
 * repli des cartes, et le rafraichissement de props qui arrive au mauvais
 * moment.
 */
describe('reordering the exercises of a workout', () => {
    const deuxExercices = () =>
        session({
            workout_lines: [
                { id: 10, order: 0, exercise: STRENGTH, sets: [{ id: 42, weight: 80, reps: 5, is_completed: false }] },
                { id: 11, order: 0, exercise: CARDIO, sets: [] },
            ],
        })

    const troisExercices = () =>
        session({
            workout_lines: [
                { id: 10, order: 0, exercise: STRENGTH, sets: [] },
                { id: 11, order: 1, exercise: CARDIO, sets: [] },
                { id: 12, order: 2, exercise: TIMED, sets: [] },
            ],
        })

    const noms = (wrapper) => wrapper.vm.localWorkout.workout_lines.map((l) => l.exercise.name)

    it('sends the whole order, not a swap', async () => {
        const wrapper = await mountPage(deuxExercices())

        patch.mockResolvedValueOnce({})
        wrapper.vm.deplacerExercice(0, 1)
        await flushPromises()

        expect(noms(wrapper)).toEqual(['Course', 'Développé couché'])
        expect(patch).toHaveBeenCalledWith(expect.stringContaining('workouts.line-order'), { lines: [11, 10] })
    })

    it('puts the exercises back when the server refuses', async () => {
        const wrapper = await mountPage(deuxExercices())

        patch.mockRejectedValueOnce({ isOffline: false })
        wrapper.vm.deplacerExercice(0, 1)
        await flushPromises()

        expect(noms(wrapper)).toEqual(['Développé couché', 'Course'])
    })

    /**
     * `isOffline` veut dire « en file d'attente », pas « refuse ». Annuler le
     * geste jetterait un deplacement que la file compte encore envoyer.
     */
    it('keeps the new order when the write is merely queued', async () => {
        const wrapper = await mountPage(deuxExercices())

        patch.mockRejectedValueOnce({ isOffline: true })
        wrapper.vm.deplacerExercice(0, 1)
        await flushPromises()

        expect(noms(wrapper)).toEqual(['Course', 'Développé couché'])
    })

    /**
     * Un rafraichissement de props — un renommage, une correction d'heure —
     * reconstruit la liste depuis la copie du serveur, donc dans SON ordre.
     */
    it('does not let a props refresh undo an order still in flight', async () => {
        const wrapper = await mountPage(deuxExercices())

        let repondre
        patch.mockReturnValueOnce(new Promise((resolve) => (repondre = resolve)))

        wrapper.vm.deplacerExercice(0, 1)
        await wrapper.vm.$nextTick()
        expect(noms(wrapper)).toEqual(['Course', 'Développé couché'])

        // Le serveur repond a une AUTRE ecriture, avec l'ordre d'avant.
        await wrapper.setProps({ workout: JSON.parse(JSON.stringify(deuxExercices())) })
        await flushPromises()

        expect(noms(wrapper)).toEqual(['Course', 'Développé couché'])

        repondre({})
        await flushPromises()
    })

    /**
     * Les cartes ne se replient PAS pendant le geste, et c'est délibéré :
     * replier raccourcissait la page de 400 px sous le doigt, et il fallait
     * ensuite rattraper le défilement, distinguer la tape du glissement, et
     * devancer le moment où la bibliothèque photographie la carte. Trois
     * mécanismes pour un confort, et chacun ramenait un défaut.
     */
    it('leaves the cards alone while dragging', async () => {
        const wrapper = await mountPage(deuxExercices())
        await flushPromises()

        const series = () => wrapper.find('[dusk="exercise-card-0"]').findAll('div.space-y-2')

        wrapper.find('[dusk="reorder-line-0"]').element.dispatchEvent(new Event('pointerdown', { bubbles: true }))
        await wrapper.vm.$nextTick()

        expect(series()[0].attributes('style') ?? '').not.toContain('display: none')
    })

    /**
     * Les quatre rappels que la page confie à la bibliothèque. jsdom ne peut
     * pas jouer le glissement, mais il peut les appeler — et c'est par eux que
     * tout passe : le retour haptique, l'écriture, et le tableau qu'elle mute.
     */
    it('hands the library a way to read, write, and report the order', async () => {
        reordonnancements.length = 0

        const wrapper = await mountPage(deuxExercices())
        await flushPromises()

        const config = reordonnancements.find((c) => c.dragHandle === '[data-poignee-exercice]')

        // Lecture : la bibliothèque voit les lignes de la séance.
        expect(config.values.value.map((l) => l.exercise.name)).toEqual(['Développé couché', 'Course'])

        // Départ du geste : le retour haptique.
        config.onDragstart({})
        expect(haptics.triggerHaptic).toHaveBeenCalledWith('tap')

        // Écriture : elle réordonne le tableau elle-même, puis la page écrit.
        patch.mockResolvedValueOnce({})
        config.values.value = [...config.values.value].reverse()
        config.onSort({ previousPosition: 0, position: 1 })
        await flushPromises()

        expect(noms(wrapper)).toEqual(['Course', 'Développé couché'])
        expect(patch).toHaveBeenCalledWith(expect.stringContaining('workouts.line-order'), { lines: [11, 10] })
    })

    /**
     * Sonde : apres un glissement, le rang que la carte connait est-il encore
     * le sien ? Il arme les fleches du clavier, donc un rang fige deplacerait
     * le mauvais exercice.
     */
    it('keeps each exercise card aware of its own rank after a sort', async () => {
        reordonnancements.length = 0

        const wrapper = await mountPage(deuxExercices())
        await flushPromises()

        const config = reordonnancements.find((c) => c.dragHandle === '[data-poignee-exercice]')

        patch.mockResolvedValueOnce({})
        config.values.value = [...config.values.value].reverse()
        config.onSort({ previousPosition: 0, position: 1 })
        await flushPromises()

        expect(noms(wrapper)).toEqual(['Course', 'Développé couché'])
        expect(wrapper.findAll('[data-exercice] [dusk^="reorder-line-"]').map((n) => n.attributes('dusk'))).toEqual([
            'reorder-line-0',
            'reorder-line-1',
        ])
    })

    it('binds the library to the exercise list itself', async () => {
        reordonnancements.length = 0

        await mountPage(deuxExercices())
        await flushPromises()

        // Aucun mode a ouvrir : la liste des cartes EST la liste deplaçable.
        // Les autres liaisons sont celles des series, une par exercice.
        expect(reordonnancements.filter((c) => c.dragHandle === '[data-poignee-exercice]')).toHaveLength(1)
    })

    /**
     * Le témoin le plus important du lot, et le seul qu'on ne peut pas trouver
     * en regardant l'écran : deux déplacements enchaînés dont les réponses
     * reviennent dans le désordre. Sans sérialisation, le serveur retient
     * l'ordre périmé.
     */
    it('sends only the latest order when two moves overlap', async () => {
        const wrapper = await mountPage(troisExercices())

        patch.mockResolvedValue({})

        wrapper.vm.deplacerExercice(0, 1)
        wrapper.vm.deplacerExercice(1, 2)
        await flushPromises()

        const envois = patch.mock.calls.filter(([url]) => String(url).includes('line-order'))

        expect(envois).toHaveLength(1)
        expect(envois[0][1]).toEqual({ lines: wrapper.vm.localWorkout.workout_lines.map((l) => l.id) })
    })

    /**
     * Le repli d'un échec part de l'ordre CONFIRMÉ. Un instantané pris à
     * l'appel rendrait l'état d'avant le premier déplacement et effacerait le
     * second sans un mot.
     */
    it('rolls back to the confirmed order, not to an intermediate snapshot', async () => {
        const wrapper = await mountPage(troisExercices())
        const depart = noms(wrapper)

        patch.mockRejectedValue({ isOffline: false })

        wrapper.vm.deplacerExercice(0, 1)
        wrapper.vm.deplacerExercice(1, 2)
        await flushPromises()

        expect(noms(wrapper)).toEqual(depart)
    })

    /**
     * Le bloc « Modèle / Terminer » était le dernier enfant du conteneur trié :
     * quand la liste se repliait, il remontait au milieu de l'écran.
     */
    it('keeps the action buttons out of the sorted container', async () => {
        const wrapper = await mountPage(deuxExercices())

        const liste = wrapper.find('[dusk="exercise-list"]').element
        const terminer = wrapper.find('[dusk="finish-workout-mobile"]').element
        const carte = wrapper.find('[dusk="exercise-card-0"]').element

        // Contrôle positif : la carte, elle, EST dedans. Sans lui, une
        // assertion négative passerait aussi sur un sélecteur qui ne trouve
        // rien.
        expect(liste.contains(carte)).toBe(true)
        expect(liste.contains(terminer)).toBe(false)
    })

    it('offers no handle when there is nothing to reorder', async () => {
        const wrapper = await mountPage()

        expect(wrapper.find('[dusk="reorder-line-0"]').exists()).toBe(false)

        const aDeux = await mountPage(deuxExercices())

        expect(aDeux.find('[dusk="reorder-line-0"]').exists()).toBe(true)
    })
})

/**
 * Les séries se réordonnent comme les exercices, mais elles vivent dans UNE
 * liste par exercice : il faut donc lier chaque conteneur séparément.
 */
describe('reordering the sets of an exercise', () => {
    const deuxSeries = () =>
        session({
            workout_lines: [
                {
                    id: 10,
                    order: 0,
                    exercise: STRENGTH,
                    sets: [
                        { id: 42, weight: 80, reps: 5, is_completed: false },
                        { id: 43, weight: 90, reps: 3, is_completed: false },
                    ],
                },
            ],
        })

    const poids = (wrapper) => wrapper.vm.localWorkout.workout_lines[0].sets.map((s) => s.weight)

    /**
     * Le numéro EST la poignée : il est toujours affiché, mais il ne devient
     * saisissable qu'à partir de deux séries. Le témoin porte donc sur le rôle,
     * pas sur la présence.
     */
    /**
     * La rangee entiere se saisit ; le numero ne porte plus que le clavier. Une
     * seule serie ne se reordonne pas, ni au doigt ni au clavier.
     */
    it('makes the whole row a handle only when there is more than one set', async () => {
        const wrapper = await mountPage(deuxSeries())

        expect(wrapper.findAll('[data-poignee-serie]')).toHaveLength(2)
        expect(wrapper.find('[dusk="reorder-set-0-0"]').element.tagName).toBe('BUTTON')

        const uneSeule = await mountPage()

        expect(uneSeule.findAll('[data-poignee-serie]')).toHaveLength(0)
        expect(uneSeule.find('[dusk="reorder-set-0-0"]').element.tagName).toBe('DIV')
    })

    /** Une commande ne demarre pas un deplacement : le geste s'arrete a elle. */
    it('keeps every control of the row out of the gesture', async () => {
        const wrapper = await mountPage(deuxSeries())
        const rangee = wrapper.find('[data-poignee-serie]')
        let vuAuDessus = 0

        rangee.element.parentElement.addEventListener('pointerdown', () => {
            vuAuDessus += 1
        })

        for (const commande of ['weight-input-0-0', 'reps-input-0-0', 'complete-set-0-0']) {
            await wrapper.find(`[dusk="${commande}"]`).trigger('pointerdown')
        }

        expect(vuAuDessus).toBe(0)

        // Le reste de la rangee, lui, porte bien le geste jusqu'a la liste — la
        // pastille du numero comprise, bouton pour le seul clavier.
        await rangee.trigger('pointerdown')
        await wrapper.find('[dusk="reorder-set-0-0"]').trigger('pointerdown')

        expect(vuAuDessus).toBe(2)
    })

    it('moves a set with the arrow keys, and sends the whole order', async () => {
        const wrapper = await mountPage(deuxSeries())

        patch.mockResolvedValue({})
        await wrapper.find('[dusk="reorder-set-0-0"]').trigger('keydown', { key: 'ArrowDown' })
        await flushPromises()

        expect(poids(wrapper)).toEqual([90, 80])
        expect(patch).toHaveBeenCalledWith(expect.stringContaining('workout-lines.set-order'), { sets: [43, 42] })
    })

    it('refuses to move a set past either end', async () => {
        const wrapper = await mountPage(deuxSeries())

        const ligne = wrapper.vm.localWorkout.workout_lines[0]
        wrapper.vm.deplacerSerie(ligne, 0, -1)
        wrapper.vm.deplacerSerie(ligne, 1, 2)
        await flushPromises()

        expect(poids(wrapper)).toEqual([80, 90])
        expect(patch).not.toHaveBeenCalled()
    })

    it('puts the sets back when the server refuses', async () => {
        const wrapper = await mountPage(deuxSeries())

        patch.mockRejectedValueOnce({ isOffline: false })
        await wrapper.find('[dusk="reorder-set-0-0"]').trigger('keydown', { key: 'ArrowDown' })
        await flushPromises()

        expect(poids(wrapper)).toEqual([80, 90])
    })

    /**
     * `isOffline` veut dire « en file d'attente », pas « refusé ». Annuler le
     * geste jetterait un déplacement que la file compte encore envoyer.
     */
    it('keeps the new order when the write is merely queued', async () => {
        const wrapper = await mountPage(deuxSeries())

        patch.mockRejectedValueOnce({ isOffline: true })
        await wrapper.find('[dusk="reorder-set-0-0"]').trigger('keydown', { key: 'ArrowDown' })
        await flushPromises()

        expect(poids(wrapper)).toEqual([90, 80])
    })

    /**
     * Les rappels que la page confie à la bibliothèque pour les séries. jsdom
     * ne peut pas jouer le glissement, mais il peut les appeler — et c'est par
     * eux que tout passe.
     */
    it('hands each set list a way to read, write, and report its order', async () => {
        reordonnancements.length = 0

        const wrapper = await mountPage(deuxSeries())
        await flushPromises()

        const config = reordonnancements.find((c) => c.dragHandle === '[data-poignee-serie]')

        expect(config.values.value.map((s) => s.weight)).toEqual([80, 90])

        config.onDragstart({})
        expect(haptics.triggerHaptic).toHaveBeenCalledWith('tap')

        // Elle réordonne le tableau elle-même, puis la page écrit.
        patch.mockResolvedValueOnce({})
        config.values.value = [...config.values.value].reverse()
        config.onSort({ previousPosition: 0, position: 1 })
        await flushPromises()

        expect(poids(wrapper)).toEqual([90, 80])
        expect(patch).toHaveBeenCalledWith(expect.stringContaining('workout-lines.set-order'), { sets: [43, 42] })
    })

    /**
     * Le temoin du numero qui ne suivait pas. La bibliotheque deplace le nœud
     * elle-meme ; si Vue reutilise ses rangees, elle ecrit les nouveaux
     * numeros dans les mauvaises et deux series portent le meme. Reconstruire
     * les rangees est ce qui remet le DOM d'accord avec le tableau : sans
     * cela, ce sont les MEMES elements qu'on retrouve apres le tri.
     */
    it('rebuilds the rows after a sort so the numbering follows', async () => {
        reordonnancements.length = 0

        const wrapper = await mountPage(deuxSeries())
        await flushPromises()

        const config = reordonnancements.find((c) => c.dragHandle === '[data-poignee-serie]')
        const avant = wrapper.findAll('[dusk^="reorder-set-0-"]').map((n) => n.element)

        patch.mockResolvedValueOnce({})
        config.values.value = [...config.values.value].reverse()
        config.onSort({ previousPosition: 0, position: 1 })
        await flushPromises()

        const apres = wrapper.findAll('[dusk^="reorder-set-0-"]')

        expect(apres.map((n) => n.text())).toEqual(['1', '2'])
        expect(apres.some((n) => avant.includes(n.element))).toBe(false)
    })

    it('binds one draggable list per exercise', async () => {
        reordonnancements.length = 0

        await mountPage(deuxSeries())
        await flushPromises()

        // Une pour les exercices — non, un seul ici — et une pour ses séries.
        const surLesSeries = reordonnancements.filter((c) => c.dragHandle === '[data-poignee-serie]')

        expect(surLesSeries).toHaveLength(1)
        expect(surLesSeries[0].values.value.map((s) => s.weight)).toEqual([80, 90])
    })
})

/**
 * Adding a set right after the exercise is the ordinary gesture on this screen,
 * and the line's recommendation only travels back with the create response.
 * A set added in that window used to be born at 0 kg, the next ones copied it,
 * and that 0 became the history of the following session. See #1677.
 */
describe('Workouts/Show — a set added before the exercise’s recommendation has arrived', () => {
    const lineAnswer = {
        data: {
            data: {
                id: 77,
                exercise_id: STRENGTH.id,
                order: 0,
                sets: [],
                recommended_values: { weight: 62.5, reps: 8, distance_km: 0, duration_seconds: 30 },
            },
        },
    }

    const addExerciseThenSet = async () => {
        const lineCreate = deferred()
        post.mockImplementation((url) =>
            url.includes('workout-lines') ? lineCreate.promise : Promise.resolve({ data: { data: { id: 88 } } }),
        )

        const wrapper = await mountPage(session())

        wrapper.vm.showAddExercise = true
        await wrapper.vm.$nextTick()
        await click(wrapper, `select-exercise-${STRENGTH.id}`)
        await click(wrapper, 'add-set-0')

        return { wrapper, lineCreate }
    }

    it('fills an untouched set with the recommendation once the line is created', async () => {
        const { wrapper, lineCreate } = await addExerciseThenSet()

        // Added before the answer: the bare pre-fill, for now.
        expect(lines(wrapper)[0].sets[0]).toMatchObject({ weight: 0, reps: 10 })

        lineCreate.resolve(lineAnswer)
        await flushPromises()

        expect(lines(wrapper)[0].sets[0]).toMatchObject({ weight: 62.5, reps: 8 })
        expect(post).toHaveBeenCalledWith(
            '/api/v1/sets',
            expect.objectContaining({ workout_line_id: 77, weight: 62.5, reps: 8 }),
        )
    })

    it('keeps what the user typed while the line was in flight', async () => {
        const { wrapper, lineCreate } = await addExerciseThenSet()

        lines(wrapper)[0].sets[0].weight = 70

        lineCreate.resolve(lineAnswer)
        await flushPromises()

        expect(lines(wrapper)[0].sets[0]).toMatchObject({ weight: 70, reps: 8 })
        expect(post).toHaveBeenCalledWith(
            '/api/v1/sets',
            expect.objectContaining({ workout_line_id: 77, weight: 70, reps: 8 }),
        )
    })

    it('copies the previous set rather than the recommendation when there is one', async () => {
        const { wrapper, lineCreate } = await addExerciseThenSet()

        lines(wrapper)[0].sets[0].weight = 70
        lineCreate.resolve(lineAnswer)
        await flushPromises()

        await click(wrapper, 'add-set-0')
        await flushPromises()

        expect(lines(wrapper)[0].sets[1]).toMatchObject({ weight: 70, reps: 8 })
    })
})

/**
 * Deux nouveaux évènements de la file hors-ligne (#1667) : une porte fermée
 * (session ou jeton expirés) et un stockage plein. Ni l'un ni l'autre n'est un
 * refus, mais l'utilisateur doit savoir quoi faire, et le savoir depuis la
 * page, pas depuis la console.
 */
describe('Workouts/Show — la file hors-ligne prévient', () => {
    it('demande de se reconnecter quand la session a expiré, en comptant ce qui attend', async () => {
        const wrapper = await mountPage()

        window.dispatchEvent(
            new CustomEvent('sync:auth-required', { detail: { url: '/api/v1/sets/1', status: 419, pending: 3 } }),
        )
        await wrapper.vm.$nextTick()

        const message = wrapper.find('[dusk="set-edit-error"]').text()
        expect(message).toContain('session a expiré')
        expect(message).toContain('3 modifications')

        wrapper.unmount()
    })

    it('prévient qu un stockage plein ne survivra pas à un rechargement', async () => {
        const wrapper = await mountPage()

        window.dispatchEvent(new CustomEvent('sync:storage-full', { detail: { pending: 1 } }))
        await wrapper.vm.$nextTick()

        expect(wrapper.find('[dusk="set-edit-error"]').text()).toContain('stockage du téléphone est plein')

        wrapper.unmount()
    })

    it('cesse d écouter une fois la page quittée', async () => {
        const wrapper = await mountPage()
        wrapper.unmount()

        expect(() =>
            window.dispatchEvent(new CustomEvent('sync:auth-required', { detail: { pending: 1 } })),
        ).not.toThrow()
    })
})
