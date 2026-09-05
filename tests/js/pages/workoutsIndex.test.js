import { describe, it, expect, vi, beforeAll, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'

const visit = vi.fn()
const post = vi.fn()
const destroy = vi.fn()
const haptic = vi.fn()

/*
 * The six charts are pulled in by defineAsyncComponent, so their import chain
 * keeps resolving after the test file is done — `workoutDuration.js` was still
 * loading when the environment tore down, and Vitest reported an unhandled
 * EnvironmentTeardownError that failed the whole run while every test passed.
 *
 * Mocking the modules means the dynamic import resolves from the registry
 * instead of walking the real chain. Nothing here asserts on a chart's
 * internals; what the page hands one is asserted through its props.
 */
vi.mock('@/Components/Stats/WorkoutFrequencyChart.vue', () => ({
    default: { name: 'WorkoutFrequencyChart', template: '<div />' },
}))
vi.mock('@/Components/Stats/WorkoutsPerMonthChart.vue', () => ({
    default: { name: 'WorkoutsPerMonthChart', template: '<div />' },
}))
vi.mock('@/Components/Stats/MonthlyVolumeChart.vue', () => ({
    default: { name: 'MonthlyVolumeChart', template: '<div />' },
}))
vi.mock('@/Components/Stats/WorkoutDurationChart.vue', () => ({
    default: { name: 'WorkoutDurationChart', template: '<div />' },
}))
vi.mock('@/Components/Stats/VolumePerWorkoutChart.vue', () => ({
    default: { name: 'VolumePerWorkoutChart', template: '<div />' },
}))
vi.mock('@/Components/Stats/WorkoutHistoryTimelineChart.vue', () => ({
    default: { name: 'WorkoutHistoryTimelineChart', template: '<div />' },
}))

vi.mock('@/composables/useHaptics', () => ({ triggerHaptic: (...args) => haptic(...args) }))

/**
 * The two `useForm({})` calls on this page — one to create a session, one to
 * delete — have to come back as distinct, *reactive* objects: `form.processing`
 * drives the loading state of the create buttons, and a plain object would
 * freeze it at its initial value and let every assertion about it pass for the
 * wrong reason.
 */
const forms = []

vi.mock('@inertiajs/vue3', async () => {
    const { reactive } = await vi.importActual('vue')

    return {
        // Named, because a shallow mount keys its stubs off the component name
        // and an anonymous one gets stubbed away slots and all.
        Head: { name: 'Head', template: '<div />' },
        Link: { name: 'Link', props: ['href'], template: '<a :href="href"><slot /></a>' },
        // Both slots, so the skeletons the page shows while the payload is in
        // flight and the content it shows afterwards are each rendered once.
        Deferred: { name: 'Deferred', props: ['data'], template: '<div><slot name="fallback" /><slot /></div>' },
        usePage: () => ({ props: { auth: { user: { id: 1 } } } }),
        router: { visit: (...args) => visit(...args), reload: vi.fn(), post: vi.fn() },
        useForm: (data) => {
            const form = reactive({
                ...data,
                processing: false,
                errors: {},
                post: (...args) => post(...args),
                delete: (...args) => destroy(...args),
                put: vi.fn(),
                reset: vi.fn(),
            })
            forms.push(form)

            return form
        },
    }
})

import WorkoutsIndex from '@/Pages/Workouts/Index.vue'
import CarteDeSeance from '@/Components/Workout/CarteDeSeance.vue'
import GraphiquesDesSeances from '@/Components/Workout/GraphiquesDesSeances.vue'

/** La question passe par un dialogue de l'application, plus par `confirm()`. */
const dialogue = (wrapper) => wrapper.findComponent({ name: 'ConfirmDialog' })

beforeAll(() => {
    globalThis.route = (name, params) => {
        if (name === 'workouts.store') return '/workouts'
        if (name === 'workouts.destroy') return `/workouts/${params.workout}`
        if (name === 'workouts.show') return `/workouts/${params.workout}`

        return `/${name}`
    }
})

const line = (id, name, setsCount) => ({ id, exercise: { id, name }, sets_count: setsCount })

/**
 * A first page of a three-page history: `total` and the number of rows on screen
 * deliberately disagree, because the page has to show the former.
 */
const paginator = {
    data: [
        {
            id: 1,
            name: 'Push Day',
            started_at: '2026-07-29T08:00:00.000000Z',
            workout_lines: [
                line(11, 'Développé', 4),
                line(12, 'Dips', 3),
                line(13, 'Écarté', 3),
                line(14, 'Pompes', 2),
                line(15, 'Pull-over', 2),
            ],
        },
        { id: 2, name: null, started_at: '2026-07-27T08:00:00.000000Z', workout_lines: [] },
        { id: 3, name: 'Leg Day', started_at: '2026-07-25T08:00:00.000000Z', workout_lines: [line(16, 'Squat', 5)] },
    ],
    total: 47,
    current_page: 1,
    last_page: 3,
    prev_page_url: null,
    next_page_url: '/workouts?page=2',
}

/** Only two of the five series carry points, so the empty ones can be shown to stay hidden. */
const deferredData = {
    charts: {
        day_of_week_frequency: [{ day: 'Lundi', count: 4 }],
        monthly_frequency: [{ month: '2026-07', count: 8 }],
        monthly_volume: [],
        duration_history: [],
        volume_history: [],
    },
    exercises: [
        { id: 21, name: 'Rowing', category: 'Dos' },
        { id: 22, name: 'Soulevé de terre', category: 'Jambes' },
    ],
}

const passesSlot = { template: '<div><slot /></div>' }

/**
 * Shallow, so only the page's own markup renders — but the wrappers standing
 * between the page root and its rows have to pass their slots through, and the
 * layout has to pass its named ones too or the header, and both of its "new
 * session" buttons, never exist.
 */
const stubs = {
    AuthenticatedLayout: {
        template: '<div><slot name="header" /><slot name="header-actions" /><slot /></div>',
    },
    GlassCard: passesSlot,
    // A real <button>, so `:disabled` genuinely disables it rather than
    // rendering as decoration on a stub nobody can press.
    GlassButton: { template: '<button><slot /></button>' },
    SwipeableRow: { template: '<div><slot name="action-right" /><slot /></div>' },
    GlassEmptyState: { template: '<div dusk="empty-state" @click="$emit(\'action\')" />' },
    // Rendered by the @inertiajs/vue3 mock above rather than stubbed away: the
    // row link and everything behind Deferred live inside their slots.
    Link: false,
    Deferred: false,
    // Rendus pour de vrai : la rangée porte le bouton de suppression et le lien
    // que les cas ci-dessous lisent, la grille porte les titres des graphiques.
    CarteDeSeance,
    GraphiquesDesSeances,
}

const mountPage = (overrides = {}) =>
    mount(WorkoutsIndex, {
        props: {
            workouts: structuredClone(paginator),
            totalExercises: 12,
            deferredData: structuredClone(deferredData),
            ...overrides,
        },
        shallow: true,
        // The template calls route() directly, so it has to resolve through the
        // render context and not only through the module scope.
        global: { stubs, mocks: { route: globalThis.route } },
    })

/** The sessions on screen, in order, read back from their delete buttons. */
const rowIds = (wrapper) =>
    wrapper.findAll('[dusk^="delete-workout-"]').map((button) => Number(button.attributes('dusk').split('-').pop()))

const deleteRow = async (wrapper, id) => {
    const button = wrapper.find(`[dusk="delete-workout-${id}"]`)

    expect(button.exists(), `no delete button for session ${id}`).toBe(true)
    await button.trigger('click')
    await dialogue(wrapper).vm.$emit('confirmer')
    await wrapper.vm.$nextTick()
}

beforeEach(() => {
    visit.mockReset()
    post.mockReset()
    destroy.mockReset()
    haptic.mockReset()
    forms.length = 0
    globalThis.confirm = vi.fn(() => true)
})

/**
 * The counters sit above the list and are the first thing read. Both of them
 * summarise more than the current page holds, so neither can be derived from
 * what happens to be rendered underneath.
 */
describe('Workouts/Index — the summary above the history', () => {
    it('counts every session ever recorded, not the twenty on this page', () => {
        const wrapper = mountPage()

        expect(wrapper.find('.text-gradient').text()).toBe('47')
    })

    it('falls back to the rows on screen when the paginator gives no total', () => {
        const wrapper = mountPage({ workouts: { ...structuredClone(paginator), total: undefined } })

        expect(wrapper.find('.text-gradient').text()).toBe('3')
    })

    it('shows a zero rather than a blank where the exercise count belongs', () => {
        const wrapper = mountPage({ totalExercises: undefined })

        expect(wrapper.find('.text-accent-state-deep').text()).toBe('0')
    })
})

/**
 * A row is the only thing identifying a past session, so what it says has to be
 * enough to recognise it by: a title even when the session was never named, a
 * date in the reader's language, and a hint of what was actually done.
 */
describe('Workouts/Index — what a history row says', () => {
    it('gives an unnamed session a title instead of an empty heading', () => {
        const wrapper = mountPage()
        const names = wrapper.findAll('h4').map((heading) => heading.text())

        expect(names).toEqual(['Push Day', 'Séance', 'Leg Day'])
    })

    it('writes the date in French, which is the only language this app speaks', () => {
        const wrapper = mountPage()

        expect(wrapper.text()).toContain('mer. 29 juil.')
    })

    /**
     * Five exercises will not fit on a phone-width row. Showing the first three
     * and counting the rest keeps the row readable without pretending the
     * session was shorter than it was.
     */
    it('previews three exercises and admits how many it left out', () => {
        const wrapper = mountPage()
        const text = wrapper.text()

        expect(text).toContain('Développé')
        expect(text).toContain('Dips')
        expect(text).toContain('Écarté')
        expect(text).not.toContain('Pompes')
        expect(text).not.toContain('Pull-over')
        expect(text).toContain('+2')
    })

    it('opens the session it names, not whichever one came first', () => {
        const wrapper = mountPage()
        const links = wrapper.findAll('a[href^="/workouts/"]').map((anchor) => anchor.attributes('href'))

        expect(links).toEqual(['/workouts/1', '/workouts/2', '/workouts/3'])
    })
})

/**
 * The history is paginated 20 to a page server-side. Without these controls
 * everything older than the twentieth session exists and cannot be reached.
 */
describe('Workouts/Index — reaching the older pages', () => {
    it('offers no pagination when the whole history already fits on one page', () => {
        const wrapper = mountPage({
            workouts: { ...structuredClone(paginator), last_page: 1, next_page_url: null },
        })

        expect(wrapper.find('[dusk="workouts-next"]').exists()).toBe(false)
    })

    /** Away from page 1, so a hardcoded "Page 1" cannot satisfy this. */
    it('offers pagination as soon as there is a second page, and says where you are', () => {
        const wrapper = mountPage({
            workouts: { ...structuredClone(paginator), current_page: 2, prev_page_url: '/workouts?page=1' },
        })

        expect(wrapper.find('[dusk="workouts-next"]').exists()).toBe(true)
        expect(wrapper.text()).toContain('Page 2 sur 3')
    })

    it('follows the paginator to the page it points at', async () => {
        const wrapper = mountPage()

        await wrapper.find('[dusk="workouts-next"]').trigger('click')

        expect(visit).toHaveBeenCalledWith('/workouts?page=2')
    })

    it('goes back to the newer sessions as well as forward', async () => {
        const wrapper = mountPage({
            workouts: { ...structuredClone(paginator), current_page: 2, prev_page_url: '/workouts?page=1' },
        })

        await wrapper.find('[dusk="workouts-prev"]').trigger('click')

        expect(visit).toHaveBeenCalledWith('/workouts?page=1')
    })

    it('greys out the direction that leads nowhere', () => {
        const wrapper = mountPage()

        expect(wrapper.find('[dusk="workouts-prev"]').attributes('disabled')).toBeDefined()
        expect(wrapper.find('[dusk="workouts-next"]').attributes('disabled')).toBeUndefined()
    })

    /**
     * The disabled button is an affordance, not a guarantee: Laravel sends
     * `null` for the edge pages, and a visit to `null` reloads the app at the
     * wrong URL. The guard is what actually stops it.
     */
    it('refuses to navigate when the paginator offers no such page', () => {
        const wrapper = mountPage()

        wrapper.vm.goToPage(null)

        expect(visit).not.toHaveBeenCalled()
    })
})

/**
 * Deleting is destructive and irreversible, and it is reached by swiping — which
 * happens by accident. Everything below is what stands between a stray swipe and
 * a lost session.
 */
describe('Workouts/Index — deleting a session', () => {
    it('asks first, and leaves everything alone when the answer is no', async () => {
        const wrapper = mountPage()

        await wrapper.find('[dusk="delete-workout-2"]').trigger('click')
        await dialogue(wrapper).vm.$emit('annuler')
        await wrapper.vm.$nextTick()

        expect(destroy).not.toHaveBeenCalled()
        expect(rowIds(wrapper)).toEqual([1, 2, 3])
    })

    it('names the session it is about to delete, even the unnamed one', async () => {
        const wrapper = mountPage()

        await wrapper.find('[dusk="delete-workout-1"]').trigger('click')
        expect(dialogue(wrapper).props('description')).toContain('Push Day')

        await dialogue(wrapper).vm.$emit('annuler')
        await wrapper.find('[dusk="delete-workout-2"]').trigger('click')

        // Une séance sans nom garde un repli lisible plutôt qu'un blanc.
        expect(dialogue(wrapper).props('description')).toContain('Séance')
    })

    it('ne supprime rien tant que la question n’est pas tranchée', async () => {
        const wrapper = mountPage()

        await wrapper.find('[dusk="delete-workout-3"]').trigger('click')

        expect(dialogue(wrapper).props('ouvert')).toBe(true)
        expect(destroy).not.toHaveBeenCalled()
    })

    it('sends the deletion for the session that was swiped', async () => {
        const wrapper = mountPage()

        await deleteRow(wrapper, 3)

        expect(destroy).toHaveBeenCalledWith('/workouts/3', expect.objectContaining({ preserveScroll: true }))
    })

    /** Waiting for the round trip before the row leaves makes the tap feel broken. */
    it('takes the row off screen without waiting for the server', async () => {
        const wrapper = mountPage()

        await deleteRow(wrapper, 2)

        expect(rowIds(wrapper)).toEqual([1, 3])
        expect(haptic).toHaveBeenCalledWith('warning')
    })

    /**
     * A refused deletion has to put the session back where it was: dropping it
     * at the end of the list would leave the history claiming a July session
     * happened after every other one.
     */
    it('puts a refused deletion back in its own place in the history', async () => {
        const wrapper = mountPage()

        await deleteRow(wrapper, 2)
        expect(rowIds(wrapper)).toEqual([1, 3])

        destroy.mock.calls[0][1].onError()
        await wrapper.vm.$nextTick()

        expect(rowIds(wrapper)).toEqual([1, 2, 3])
        expect(haptic).toHaveBeenLastCalledWith('error')
    })

    /**
     * The page owns a copy of its server data. Splicing the prop instead would
     * work on screen and silently lose the change the moment Inertia replaces
     * the prop — and would corrupt the paginator the pagination controls read.
     */
    it('leaves the server data it was handed untouched', async () => {
        const wrapper = mountPage()

        await deleteRow(wrapper, 2)

        expect(wrapper.props('workouts').data.map((workout) => workout.id)).toEqual([1, 2, 3])
    })

    /**
     * A queued deletion can fire against a list the server has already changed
     * under it. `findIndex` answering -1 and going unchecked means `splice(-1)`,
     * which removes the last row — a session the user never touched.
     */
    it('deletes nothing when the session is no longer in the list', async () => {
        const wrapper = mountPage()

        wrapper.vm.confirmDeletion({ id: 999, name: 'Fantôme' })
        await wrapper.vm.$nextTick()

        expect(rowIds(wrapper)).toEqual([1, 2, 3])
        expect(destroy).not.toHaveBeenCalled()
    })
})

/**
 * Paginating, or any Inertia round trip, re-sends `workouts` with different
 * rows. A list built once at setup would keep showing page 1 for ever.
 */
describe('Workouts/Index — the server sends a different page', () => {
    it('shows the page the server just sent', async () => {
        const wrapper = mountPage()

        await wrapper.setProps({
            workouts: {
                ...structuredClone(paginator),
                data: [{ id: 9, name: 'Vieille séance', started_at: '2026-01-04T08:00:00.000000Z', workout_lines: [] }],
                current_page: 3,
                prev_page_url: '/workouts?page=2',
                next_page_url: null,
            },
        })

        expect(rowIds(wrapper)).toEqual([9])
        expect(wrapper.text()).toContain('Page 3 sur 3')
    })
})

/**
 * Creating a session is the whole point of the screen, and it is offered from
 * two places: the header, and the empty state someone arriving for the first
 * time will actually be looking at.
 */
describe('Workouts/Index — starting a session', () => {
    it('creates a session from the header button', async () => {
        const wrapper = mountPage()

        await wrapper.findAll('[aria-label="Nouvelle séance"]')[0].trigger('click')

        expect(post).toHaveBeenCalledWith('/workouts')
    })

    it('creates a session from the empty state as well', async () => {
        const wrapper = mountPage({ workouts: { ...structuredClone(paginator), data: [], total: 0 } })

        await wrapper.find('[dusk="empty-state"]').trigger('click')

        expect(post).toHaveBeenCalledWith('/workouts')
    })
})

/**
 * "No sessions yet" and "sessions not loaded yet" look identical if the page
 * cannot tell them apart, and telling a returning user their history is empty
 * is worse than showing nothing.
 */
describe('Workouts/Index — an empty history', () => {
    it('invites a first session once the history is known to be empty', () => {
        const wrapper = mountPage({ workouts: { ...structuredClone(paginator), data: [], total: 0 } })

        expect(wrapper.find('[dusk="empty-state"]').exists()).toBe(true)
    })

    it('says nothing about emptiness while the history is still on its way', () => {
        const wrapper = mountPage({ workouts: undefined })

        expect(wrapper.find('[dusk="empty-state"]').exists()).toBe(false)
        expect(wrapper.find('[dusk="workouts-next"]').exists()).toBe(false)
    })
})

/**
 * The charts arrive after the page, in one deferred payload. A chart whose
 * series came back empty must not render: an axis with nothing on it reads as a
 * broken chart rather than as an absence of data.
 */
describe('Workouts/Index — the deferred charts and exercises', () => {
    it('shows only the charts that have something to plot', () => {
        // Whole headings, not substrings: "Durée" also appears in the timeline
        // card's subtitle, which has nothing to do with the duration chart.
        const headings = mountPage()
            .findAll('h3')
            .map((heading) => heading.text())

        expect(headings).toContain('Fréquence par Jour')
        expect(headings).toContain('Fréquence Mensuelle')
        expect(headings).not.toContain('Volume Mensuel')
        expect(headings).not.toContain('Durée')
        expect(headings).not.toContain('Volume par Séance')
    })

    it('shows all five once every series has points', () => {
        const filled = structuredClone(deferredData)
        for (const series of Object.keys(filled.charts)) {
            filled.charts[series] = [{ label: 'Juillet', value: 3 }]
        }

        const headings = mountPage({ deferredData: filled })
            .findAll('h3')
            .map((heading) => heading.text())

        expect(headings).toEqual(
            expect.arrayContaining([
                'Fréquence par Jour',
                'Fréquence Mensuelle',
                'Volume Mensuel',
                'Durée',
                'Volume par Séance',
            ]),
        )
    })

    it('lists the exercises available with the muscle group each belongs to', () => {
        const text = mountPage().text()

        expect(text).toContain('Rowing')
        expect(text).toContain('Dos')
        expect(text).toContain('Soulevé de terre')
        expect(text).toContain('Jambes')
    })

    /** The summary block, charts included, hangs off the history being non-empty. */
    it('offers no charts at all before the first session exists', () => {
        const wrapper = mountPage({ workouts: { ...structuredClone(paginator), data: [], total: 0 } })

        expect(wrapper.findAll('h3').map((heading) => heading.text())).not.toContain('Fréquence Mensuelle')
    })
})
