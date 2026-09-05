import { describe, it, expect, vi, beforeAll, beforeEach, afterEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'

const hoisted = vi.hoisted(() => ({
    routerDelete: vi.fn(),
    routerPost: vi.fn(),
    haptics: vi.fn(),
}))

/** The last form each page asked for, newest first — pages here build two. */
let forms = []

vi.mock('@inertiajs/vue3', async () => {
    const { reactive } = await vi.importActual('vue')

    return {
        Head: { template: '<div />' },
        Link: { template: '<a><slot /></a>' },
        // The default slot, not the fallback: the two habit charts live behind
        // it, and a fallback-only stub leaves them permanently unrendered.
        Deferred: { template: '<div><slot /></div>' },
        router: {
            delete: (...args) => hoisted.routerDelete(...args),
            post: (...args) => hoisted.routerPost(...args),
            reload: vi.fn(),
            visit: vi.fn(),
        },
        // Reactive: both pages redraw off their form — the pressed swatch, the
        // open edit row, the loading button. A plain object never invalidates
        // any of it.
        useForm: (data) => {
            const form = reactive({
                ...data,
                errors: {},
                processing: false,
                post: vi.fn(),
                put: vi.fn(),
                reset: vi.fn(function reset() {
                    Object.assign(this, structuredClone(data))
                }),
            })
            forms.push(form)

            return form
        },
    }
})

vi.mock('@/composables/useHaptics', () => ({ triggerHaptic: (...args) => hoisted.haptics(...args) }))

/*
 * The two habit charts are pulled in with `defineAsyncComponent`, so they
 * resolve on their own schedule; the real ones then reach for a canvas jsdom
 * cannot draw on and log about it while the worker is already closing, which
 * fails the whole run with `EnvironmentTeardownError` and every test green.
 * What this file is about is which chart is shown and what it is handed — both
 * of which survive the substitution. How each one draws is its own file.
 *
 * `__esModule` is not decoration: Vue only unwraps `default` from a resolved
 * async chunk when it can recognise it as a module, and without it the whole
 * mock namespace is used as the component.
 */
vi.mock('@/Components/Stats/HabitConsistencyChart.vue', () => ({
    __esModule: true,
    default: { name: 'HabitConsistencyChart', props: ['data'], template: '<div class="consistency-chart" />' },
}))
vi.mock('@/Components/Stats/HabitHistoryChart.vue', () => ({
    __esModule: true,
    default: { name: 'HabitHistoryChart', props: ['data'], template: '<div class="history-chart" />' },
}))

import ExercisesIndex from '@/Pages/Exercises/Index.vue'
import HabitsIndex from '@/Pages/Habits/Index.vue'
import GlassInput from '@/Components/UI/GlassInput.vue'
import { passesSlot, layoutStub } from './pageStubs'

/**
 * La question passe par un dialogue de l'application, plus par `confirm()`.
 *
 * Sur mobile, plusieurs navigateurs suppriment la boîte native après quelques
 * appels : le geste s'exécutait alors SANS question. Une confirmation qui peut
 * disparaître n'en est pas une.
 */
const dialogue = (wrapper) => wrapper.findComponent({ name: 'ConfirmDialog' })

const confirmer = async (wrapper) => {
    await dialogue(wrapper).vm.$emit('confirmer')
}

beforeAll(() => {
    // Both pages are here, and they call `route()` differently: the library
    // names its parameter, the habits page passes a bare id.
    globalThis.route = (name, params) =>
        `/${name}/${params !== null && typeof params === 'object' ? Object.values(params).join('/') : (params ?? '')}`
})

beforeEach(() => {
    forms = []
    vi.clearAllMocks()
    window.history.replaceState({}, '', '/exercises')
})

afterEach(() => {
    vi.restoreAllMocks()
})

/**
 * A modal that honours `show`, which is the whole point of the prop: a stub
 * that always renders its slot would report the form as open before anything
 * was clicked, and every "it opens" assertion below would pass on its own.
 */
const modalStub = {
    props: ['show'],
    emits: ['close'],
    template: '<div v-if="show" class="modal"><slot /></div>',
}

/** A field, found through the label the user reads rather than by position. */
const fieldLabelled = (wrapper, label) =>
    wrapper.findAllComponents(GlassInput).find((field) => field.props('label') === label)

const byText = (wrapper, text) => wrapper.findAll('button').find((button) => button.text().trim() === text)

const byLabel = (wrapper, label) => wrapper.findAll('button').find((b) => b.attributes('aria-label') === label)

describe('the library’s create form', () => {
    /**
     * The form belongs to `NouvelExerciceModal`, mounted after the page has
     * asked for its own edit form: it is the last one handed out.
     */
    const createForm = () => forms.at(-1)

    const PECS = { id: 1, name: 'Développé couché', type: 'strength', category: 'Pectoraux' }

    const ExerciseCardStub = {
        name: 'ExerciseCard',
        props: ['exercise', 'isEditing', 'editForm', 'category', 'typeLabel'],
        emits: ['start-edit', 'cancel-edit', 'update', 'delete'],
        template: '<div class="exercise-card">{{ exercise.name }}</div>',
    }

    const mountPage = (exercises = [PECS]) =>
        mount(ExercisesIndex, {
            props: { exercises: structuredClone(exercises) },
            global: {
                directives: { press: {} },
                mocks: { $page: { props: { errors: {} } } },
                stubs: {
                    AuthenticatedLayout: layoutStub,
                    ExerciseCategoryChart: true,
                    ExerciseCard: ExerciseCardStub,
                    Modal: modalStub,
                },
            },
        })

    it('stays shut until something asks for it', () => {
        expect(mountPage().find('.modal').exists()).toBe(false)
    })

    it('opens from the button on either layout', async () => {
        const onPhone = mountPage()
        await onPhone.find('[dusk="create-exercise-btn"]').trigger('click')
        expect(onPhone.find('.modal').exists()).toBe(true)

        const onDesktop = mountPage()
        await onDesktop.find('[dusk="create-exercise-btn-desktop"]').trigger('click')
        expect(onDesktop.find('.modal').exists()).toBe(true)
    })

    it('opens from the invitation shown to a user with nothing yet', async () => {
        const wrapper = mountPage([])

        // The empty state is the only route to the form for someone whose
        // library is empty and who has not found the header button.
        await byText(wrapper, 'Créer le premier exercice').trigger('click')

        expect(wrapper.find('.modal').exists()).toBe(true)
    })

    it('writes each box into the field it is named for', async () => {
        const wrapper = mountPage()
        await wrapper.find('[dusk="create-exercise-btn"]').trigger('click')

        await wrapper.find('[dusk="exercise-name-input"]').setValue('Rowing barre')
        const [type, category] = wrapper.findAll('select')
        await type.setValue('timed')
        await category.setValue('Dos')

        const form = createForm()
        expect(form.name).toBe('Rowing barre')
        expect(form.type).toBe('timed')
        expect(form.category).toBe('Dos')
    })

    it('offers "no category" as a choice, not only as a blank', async () => {
        const wrapper = mountPage()
        await wrapper.find('[dusk="create-exercise-btn"]').trigger('click')

        const [, category] = wrapper.findAll('select')

        // Without it there is no way back to an uncategorised exercise once a
        // category has been picked. The option has to be SELECTABLE for that:
        // this assertion read only the text, and passed just as happily while
        // the option was rendered `disabled`.
        const aucune = category.findAll('option').find((option) => option.text() === '— Aucune —')

        expect(aucune).toBeDefined()
        expect(aucune.attributes('disabled')).toBeUndefined()
        expect(aucune.element.value).toBe('')
    })

    it('closes the form and says so once the exercise is created', async () => {
        const wrapper = mountPage()
        await wrapper.find('[dusk="create-exercise-btn"]').trigger('click')
        await wrapper.find('form').trigger('submit')

        const form = createForm()
        expect(form.post).toHaveBeenCalledTimes(1)
        expect(form.post.mock.calls[0][0]).toBe('/exercises.store/')

        // Still open and still filled, the next tap would create a duplicate.
        expect(wrapper.find('.modal').exists()).toBe(true)

        form.post.mock.calls[0][1].onSuccess()
        await wrapper.vm.$nextTick()

        expect(wrapper.find('.modal').exists()).toBe(false)
        expect(form.reset).toHaveBeenCalled()
        expect(hoisted.haptics).toHaveBeenCalledWith('success')
    })

    it('keeps the form open, and buzzes differently, when the server refuses', async () => {
        const wrapper = mountPage()
        await wrapper.find('[dusk="create-exercise-btn"]').trigger('click')
        await wrapper.find('form').trigger('submit')

        createForm().post.mock.calls[0][1].onError()
        await wrapper.vm.$nextTick()

        // Closing here would throw away what the user typed along with the
        // error messages explaining why it was refused.
        expect(wrapper.find('.modal').exists()).toBe(true)
        expect(hoisted.haptics).toHaveBeenCalledWith('error')
        expect(hoisted.haptics).not.toHaveBeenCalledWith('success')
    })
})

describe('editing an exercise in place', () => {
    const PECS = { id: 1, name: 'Développé couché', type: 'strength', category: 'Pectoraux' }
    const GAINAGE = { id: 4, name: 'Gainage', type: 'timed', category: null }

    const ExerciseCardStub = {
        name: 'ExerciseCard',
        props: ['exercise', 'isEditing', 'editForm', 'category'],
        emits: ['start-edit', 'cancel-edit', 'update', 'delete'],
        template: '<div class="exercise-card">{{ exercise.name }}</div>',
    }

    const mountPage = (exercises) =>
        mount(ExercisesIndex, {
            props: { exercises: structuredClone(exercises) },
            global: {
                directives: { press: {} },
                mocks: { $page: { props: { errors: {} } } },
                stubs: {
                    AuthenticatedLayout: layoutStub,
                    ExerciseCategoryChart: true,
                    ExerciseCard: ExerciseCardStub,
                    Modal: modalStub,
                },
            },
        })

    const cards = (wrapper) => wrapper.findAllComponents(ExerciseCardStub)

    /** The edit form is the page's own `useForm`; the create form belongs to the modal, mounted after it. */
    const editForm = () => forms[0]

    it('opens the row that was asked for, and only that one', async () => {
        const wrapper = mountPage([PECS, GAINAGE])

        cards(wrapper)[1].vm.$emit('start-edit', GAINAGE)
        await wrapper.vm.$nextTick()

        expect(cards(wrapper).map((c) => c.props('isEditing'))).toEqual([false, true])
        expect(editForm().name).toBe('Gainage')
        expect(editForm().type).toBe('timed')
    })

    it('reads an uncategorised exercise as an empty choice, not as "null"', async () => {
        const wrapper = mountPage([GAINAGE])

        cards(wrapper)[0].vm.$emit('start-edit', GAINAGE)
        await wrapper.vm.$nextTick()

        // The select has no `null` option, so the field would show as blank but
        // send the string "null" back on save.
        expect(editForm().category).toBe('')
    })

    it('closes the row and forgets the draft on cancel', async () => {
        const wrapper = mountPage([PECS, GAINAGE])

        cards(wrapper)[0].vm.$emit('start-edit', PECS)
        await wrapper.vm.$nextTick()
        cards(wrapper)[0].vm.$emit('cancel-edit')
        await wrapper.vm.$nextTick()

        expect(cards(wrapper).map((c) => c.props('isEditing'))).toEqual([false, false])
        expect(editForm().reset).toHaveBeenCalled()
    })

    it('saves to the row it was opened on, and closes only once accepted', async () => {
        const wrapper = mountPage([PECS, GAINAGE])

        cards(wrapper)[1].vm.$emit('start-edit', GAINAGE)
        await wrapper.vm.$nextTick()
        cards(wrapper)[1].vm.$emit('update', GAINAGE)
        await wrapper.vm.$nextTick()

        expect(editForm().put).toHaveBeenCalledTimes(1)
        expect(editForm().put.mock.calls[0][0]).toBe('/exercises.update/4')
        expect(cards(wrapper)[1].props('isEditing')).toBe(true)

        editForm().put.mock.calls[0][1].onSuccess()
        await wrapper.vm.$nextTick()

        expect(cards(wrapper)[1].props('isEditing')).toBe(false)
    })

    it('sends nothing for a row that has already gone', async () => {
        const wrapper = mountPage([PECS])

        /*
         * Deux appuis sur la même corbeille avant que la première réponse
         * n'arrive : le second ne trouve plus rien à retirer, et
         * `splice(-1, 1)` prendrait la DERNIÈRE ligne de la liste à la place.
         *
         * Le dialogue ne rend pas ce cas impossible — il le rend seulement plus
         * rare, puisqu'il faut confirmer deux fois. Le garde reste utile.
         */
        cards(wrapper)[0].vm.$emit('delete', PECS.id)
        await wrapper.vm.$nextTick()
        await dialogue(wrapper).vm.$emit('confirmer')
        await wrapper.vm.$nextTick()

        expect(hoisted.routerDelete).toHaveBeenCalledTimes(1)

        wrapper.vm.demanderSuppression(PECS.id)
        wrapper.vm.confirmerSuppression()

        expect(hoisted.routerDelete).toHaveBeenCalledTimes(1)
    })
})

describe('the habit form', () => {
    const habit = (overrides = {}) => ({
        id: 1,
        name: 'Étirements',
        description: null,
        color: 'bg-palette-framboise',
        icon: 'self_improvement',
        goal_times_per_week: 5,
        logs: [],
        ...overrides,
    })

    const weekDates = [
        { date: '2026-02-09', day_short: 'Lun', day_num: 9, is_today: false },
        { date: '2026-02-10', day_short: 'Mar', day_num: 10, is_today: true },
    ]

    const mountPage = async (habits = [habit()], stats = undefined) => {
        const wrapper = mount(HabitsIndex, {
            props: { habits: structuredClone(habits), weekDates, ...(stats ? { stats } : {}) },
            global: {
                directives: { press: {} },
                mocks: { route: globalThis.route },
                stubs: { AuthenticatedLayout: layoutStub, GlassCard: passesSlot, Modal: modalStub },
            },
        })

        // The two charts arrive through `defineAsyncComponent`; left to resolve
        // after the file has finished they take the whole run down with an
        // `EnvironmentTeardownError`.
        await flushPromises()

        return wrapper
    }

    it('stays shut until something asks for it', async () => {
        expect((await mountPage()).find('.modal').exists()).toBe(false)
    })

    it('opens from the header button, blank', async () => {
        const wrapper = await mountPage()

        await byLabel(wrapper, 'Ajouter une habitude').trigger('click')

        expect(wrapper.find('.modal').exists()).toBe(true)
        expect(wrapper.find('#habit-form-title').text()).toBe('Nouvelle Habitude')
    })

    it('opens from the invitation shown to a user with no habits', async () => {
        const wrapper = await mountPage([])

        expect(wrapper.text()).toContain('Aucune habitude')
        await byText(wrapper, 'Créer ma première habitude').trigger('click')

        expect(wrapper.find('#habit-form-title').text()).toBe('Nouvelle Habitude')
    })

    it('opens on an existing habit under a different title', async () => {
        const wrapper = await mountPage([habit({ name: 'Lecture' })])

        await byLabel(wrapper, "Modifier l'habitude").trigger('click')

        expect(wrapper.find('#habit-form-title').text()).toBe('Modifier')
        expect(forms[0].name).toBe('Lecture')
    })

    it('writes each box into the field it is labelled with', async () => {
        const wrapper = await mountPage()
        await byLabel(wrapper, 'Ajouter une habitude').trigger('click')

        await fieldLabelled(wrapper, 'Nom').find('input').setValue('Lecture')
        await fieldLabelled(wrapper, 'Description (optionnel)').find('input').setValue('20 pages le soir')
        await fieldLabelled(wrapper, 'Objectif (fois par semaine)').find('input').setValue('4')

        expect(forms[0].name).toBe('Lecture')
        expect(forms[0].description).toBe('20 pages le soir')
        expect(forms[0].goal_times_per_week).toBe('4')
    })

    it('records the colour that was picked, and marks only it', async () => {
        const wrapper = await mountPage()
        await byLabel(wrapper, 'Ajouter une habitude').trigger('click')

        // Named, not just coloured: sixteen unlabelled swatches are sixteen
        // identical "button"s to a screen reader.
        await byLabel(wrapper, 'Framboise').trigger('click')

        expect(forms[0].color).toBe('bg-palette-framboise')
        expect(byLabel(wrapper, 'Framboise').attributes('aria-pressed')).toBe('true')
        expect(byLabel(wrapper, 'Ardoise').attributes('aria-pressed')).toBe('false')
        expect(wrapper.findAll('[dusk^="habit-color-"][aria-pressed="true"]')).toHaveLength(1)
    })

    it('records the icon that was picked, and marks only it', async () => {
        const wrapper = await mountPage()
        await byLabel(wrapper, 'Ajouter une habitude').trigger('click')

        await byLabel(wrapper, 'Hydratation').trigger('click')

        expect(forms[0].icon).toBe('water_drop')
        expect(byLabel(wrapper, 'Hydratation').attributes('aria-pressed')).toBe('true')
        expect(byLabel(wrapper, 'Validation').attributes('aria-pressed')).toBe('false')
        expect(wrapper.findAll('[dusk^="habit-icon-"][aria-pressed="true"]')).toHaveLength(1)
    })

    it('offers two ways out that both leave the habit alone', async () => {
        const wrapper = await mountPage()

        await byLabel(wrapper, 'Ajouter une habitude').trigger('click')
        await byLabel(wrapper, 'Fermer le formulaire').trigger('click')
        expect(wrapper.find('.modal').exists()).toBe(false)

        await byLabel(wrapper, 'Ajouter une habitude').trigger('click')
        await byText(wrapper, 'Annuler').trigger('click')

        expect(wrapper.find('.modal').exists()).toBe(false)
        expect(forms[0].post).not.toHaveBeenCalled()
        expect(forms[0].put).not.toHaveBeenCalled()
    })

    it('closes the form once the new habit is accepted, not before', async () => {
        const wrapper = await mountPage()
        await byLabel(wrapper, 'Ajouter une habitude').trigger('click')
        await wrapper.find('form').trigger('submit')

        expect(wrapper.find('.modal').exists()).toBe(true)

        forms[0].post.mock.calls[0][1].onSuccess()
        await wrapper.vm.$nextTick()

        expect(wrapper.find('.modal').exists()).toBe(false)
        // Emptied too, or the next habit opens on the last one's name.
        expect(forms[0].reset).toHaveBeenCalled()
    })

    it('closes the form once an edit is accepted, keeping what was typed', async () => {
        const wrapper = await mountPage([habit({ id: 7, name: 'Lecture' })])

        await byLabel(wrapper, "Modifier l'habitude").trigger('click')
        await wrapper.find('form').trigger('submit')

        expect(forms[0].put.mock.calls[0][0]).toBe('/habits.update/7')

        forms[0].put.mock.calls[0][1].onSuccess()
        await wrapper.vm.$nextTick()

        expect(wrapper.find('.modal').exists()).toBe(false)
        // An edit keeps its values: the server has just echoed them back, and
        // clearing here would blank the form the user is still looking at.
        expect(forms[0].reset).not.toHaveBeenCalled()
    })
})

describe('the habit week', () => {
    const habit = (overrides = {}) => ({
        id: 1,
        name: 'Étirements',
        description: null,
        color: 'bg-palette-framboise',
        icon: 'self_improvement',
        goal_times_per_week: 5,
        logs: [],
        ...overrides,
    })

    const weekDates = [
        { date: '2026-02-09', day_short: 'Lun', day_num: 9, is_today: false },
        { date: '2026-02-10', day_short: 'Mar', day_num: 10, is_today: true },
    ]

    const mountPage = async (habits, stats) => {
        const wrapper = mount(HabitsIndex, {
            props: { habits: structuredClone(habits), weekDates, ...(stats ? { stats } : {}) },
            global: {
                directives: { press: {} },
                mocks: { route: globalThis.route },
                stubs: { AuthenticatedLayout: layoutStub, GlassCard: passesSlot, Modal: modalStub },
            },
        })

        await flushPromises()

        return wrapper
    }

    it('ticks the day whose box was tapped, on the habit it belongs to', async () => {
        const wrapper = await mountPage([habit({ id: 3 }), habit({ id: 8, name: 'Lecture' })])

        await wrapper.find('[dusk="habit-8-2026-02-09"]').trigger('click')

        const [url, payload] = hoisted.routerPost.mock.calls[0]

        // Both halves of the pairing: the habit and the day. Either one read
        // off the wrong loop index ticks somebody else's Monday.
        expect(url).toBe('/habits.toggle/8')
        expect(payload).toEqual({ date: '2026-02-09' })
    })

    it('names each box by its habit and its day', async () => {
        const wrapper = await mountPage([habit({ id: 3, name: 'Étirements' })])

        expect(wrapper.find('[dusk="habit-3-2026-02-10"]').attributes('aria-label')).toBe('Étirements, Mar 10')
    })

    it('marks a ticked day as pressed, and an untouched one as not', async () => {
        const wrapper = await mountPage([habit({ id: 3, logs: [{ date: '2026-02-10' }] })])

        expect(wrapper.find('[dusk="habit-3-2026-02-10"]').attributes('aria-pressed')).toBe('true')
        expect(wrapper.find('[dusk="habit-3-2026-02-09"]').attributes('aria-pressed')).toBe('false')
    })

    it('demande avant de supprimer l’habitude sur laquelle porte la corbeille', async () => {
        const wrapper = await mountPage([habit({ id: 3 }), habit({ id: 8, name: 'Lecture' })])

        const bins = wrapper.findAll('button').filter((b) => b.attributes('aria-label') === "Supprimer l'habitude")
        await bins[1].trigger('click')

        expect(hoisted.routerDelete).not.toHaveBeenCalled()
        // Le dialogue NOMME l'habitude : c'est ce que la boîte native ne
        // pouvait pas faire.
        expect(dialogue(wrapper).props('description')).toContain('Lecture')

        await confirmer(wrapper)

        expect(hoisted.routerDelete).toHaveBeenCalledTimes(1)
    })
})

describe('the thirty-day habit charts', () => {
    const habit = {
        id: 1,
        name: 'Étirements',
        color: 'bg-palette-framboise',
        icon: 'spa',
        goal_times_per_week: 5,
        logs: [],
    }
    const weekDates = [{ date: '2026-02-09', day_short: 'Lun', day_num: 9, is_today: false }]

    const mountPage = async (stats) => {
        const wrapper = mount(HabitsIndex, {
            props: { habits: [structuredClone(habit)], weekDates, stats },
            global: {
                directives: { press: {} },
                mocks: { route: globalThis.route },
                stubs: { AuthenticatedLayout: layoutStub, GlassCard: passesSlot, Modal: modalStub },
            },
        })

        await flushPromises()

        return wrapper
    }

    it('draws each chart only once its own series has something in it', async () => {
        const both = await mountPage({
            consistencyData: [{ date: '2026-02-01', count: 2 }],
            history: [{ date: '2026-02-01', count: 3 }],
        })

        expect(both.text()).toContain('Régularité')
        expect(both.text()).toContain('Constance')

        // Each heading is gated on its own series, so a page reading one series
        // for both charts would still pass a check that only ever sends both.
        const consistencyOnly = await mountPage({
            consistencyData: [{ date: '2026-02-01', count: 2 }],
            history: [{ date: '2026-02-01', count: 0 }],
        })

        expect(consistencyOnly.text()).toContain('Régularité')
        expect(consistencyOnly.text()).not.toContain('Constance')
    })

    it('hands each chart its own series', async () => {
        const wrapper = await mountPage({
            consistencyData: [{ date: '2026-02-01', count: 2 }],
            history: [{ date: '2026-02-01', count: 3 }],
        })

        // Two series with different counts, so the two charts fed from the same
        // one would show up.
        expect(wrapper.findComponent({ name: 'HabitConsistencyChart' }).props('data')).toEqual([
            { date: '2026-02-01', count: 2 },
        ])
        expect(wrapper.findComponent({ name: 'HabitHistoryChart' }).props('data')).toEqual([
            { date: '2026-02-01', count: 3 },
        ])
    })

    it('shows nothing rather than an empty frame on a month with no activity', async () => {
        const wrapper = await mountPage({
            consistencyData: [{ date: '2026-02-01', count: 0 }],
            history: [{ date: '2026-02-01', count: 0 }],
        })

        // All zeroes is a flat line at the bottom of an axis — a chart that
        // says nothing and takes half the screen to say it.
        expect(wrapper.text()).not.toContain('Régularité')
        expect(wrapper.text()).not.toContain('Constance')
    })

    it('survives a page opened before the deferred stats have landed', async () => {
        const wrapper = await mountPage(undefined)

        expect(wrapper.text()).not.toContain('Régularité')
        expect(wrapper.text()).toContain('Étirements')
    })
})
