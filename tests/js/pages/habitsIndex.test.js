import { describe, it, expect, vi, beforeAll, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'

const post = vi.fn()
const routerDelete = vi.fn()
let form

vi.mock('@inertiajs/vue3', async () => {
    const { reactive } = await vi.importActual('vue')

    return {
        Head: { template: '<div />' },
        Link: { template: '<a><slot /></a>' },
        // The stats block arrives as a deferred prop. Rendering the fallback
        // rather than the default slot keeps the charts — and their canvases,
        // which jsdom does not draw — out of a test about habit bookkeeping.
        Deferred: { template: '<div><slot name="fallback" /></div>' },
        router: { post: (...args) => post(...args), delete: (...args) => routerDelete(...args) },
        useForm: (data) => {
            form = reactive({
                ...data,
                processing: false,
                errors: {},
                post: vi.fn(),
                put: vi.fn(),
                reset: vi.fn(function reset() {
                    Object.assign(this, { ...data })
                }),
            })

            return form
        },
    }
})

import HabitsIndex from '@/Pages/Habits/Index.vue'
import { passesSlot } from './pageStubs'

/**
 * La question passe par un dialogue de l'application, plus par `confirm()`.
 *
 * Sur mobile, plusieurs navigateurs suppriment la boîte native après quelques
 * appels : le geste s'exécutait alors SANS question. Une confirmation qui peut
 * disparaître n'en est pas une.
 */
const dialogue = (wrapper) => wrapper.findComponent({ name: 'ConfirmDialog' })

beforeAll(() => {
    globalThis.route = (name, params) => `/${name}/${JSON.stringify(params ?? '')}`
})

beforeEach(() => {
    vi.clearAllMocks()
})

const habit = (overrides = {}) => ({
    id: 1,
    name: 'Étirements',
    description: null,
    color: 'cyan',
    icon: 'self_improvement',
    goal_times_per_week: 5,
    logs: [],
    ...overrides,
})

const weekDates = [
    { date: '2026-02-09', day_short: 'Lun', day_num: 9, is_today: false },
    { date: '2026-02-10', day_short: 'Mar', day_num: 10, is_today: true },
]

const mountPage = (habits = [habit()]) =>
    mount(HabitsIndex, {
        props: { habits: structuredClone(habits), weekDates },
        global: {
            mocks: { route: globalThis.route },
            stubs: { AuthenticatedLayout: passesSlot, GlassCard: passesSlot, Modal: passesSlot },
        },
    })

describe('reading a habit’s week', () => {
    it('ticks only the days that were actually logged', () => {
        const wrapper = mountPage([habit({ logs: [{ date: '2026-02-10' }] })])
        const [tracked] = wrapper.props().habits

        expect(wrapper.vm.isCompleted(tracked, '2026-02-10')).toBe(true)
        expect(wrapper.vm.isCompleted(tracked, '2026-02-09')).toBe(false)
    })

    it('stops the progress bar at full when the goal is beaten', () => {
        const wrapper = mountPage()
        const beaten = habit({ goal_times_per_week: 2, logs: [{}, {}, {}, {}] })

        // Four of a two-a-week goal is 200 %; a bar drawn past its own track
        // spills out of the card.
        expect(wrapper.vm.getProgressPercent(beaten)).toBe(100)
    })

    it('reports the real fraction below the goal', () => {
        const wrapper = mountPage()

        expect(wrapper.vm.getProgressPercent(habit({ goal_times_per_week: 4, logs: [{}] }))).toBe(25)
    })
})

describe('ticking a day', () => {
    it('reloads only the habits, keeping scroll and state', () => {
        const wrapper = mountPage()

        wrapper.vm.toggleHabit(habit(), '2026-02-10')

        const [, payload, options] = post.mock.calls[0]

        expect(payload).toEqual({ date: '2026-02-10' })
        // A full reload here would throw away the open modal and jump the page
        // back to the top on every tick of a week's worth of habits.
        //
        // `stats` is listed alongside `habits` because the two thirty-day charts
        // live on it. Asking for `habits` alone did not merely delay them: it is
        // a deferred prop, so the response carried no `deferredProps` metadata,
        // nothing re-triggered the load, and both charts kept describing the
        // state from before the tick.
        expect(options).toMatchObject({
            preserveScroll: true,
            preserveState: true,
            only: ['habits', 'stats'],
        })
    })
})

/**
 * The form lives in `FormulaireDHabitude`, which owns the Inertia form; the
 * page only says which habit it opens on. `form` is the one the child asked
 * for, and the child fills it on the tick after the page opens it.
 */
describe('the habit form', () => {
    it('opens on an existing habit with its values already in place', async () => {
        const wrapper = mountPage()
        const existing = habit({ id: 7, name: 'Lecture', description: 'Le soir', goal_times_per_week: 3 })

        wrapper.vm.editHabit(existing)
        await wrapper.vm.$nextTick()

        expect(form.name).toBe('Lecture')
        expect(form.description).toBe('Le soir')
        expect(form.goal_times_per_week).toBe(3)
        // By id, not by identity: the ref hands back a reactive proxy of the
        // object it was given, which is never the same reference.
        expect(wrapper.vm.editingHabit.id).toBe(7)
    })

    it('reads a habit with no description as an empty field, not as "null"', async () => {
        const wrapper = mountPage()

        wrapper.vm.editHabit(habit({ description: null }))
        await wrapper.vm.$nextTick()

        // Without the fallback the textarea shows the word null, which the user
        // then has to delete before saving.
        expect(form.description).toBe('')
    })

    it('forgets the habit being edited when the add form is opened', () => {
        const wrapper = mountPage()

        wrapper.vm.editHabit(habit({ id: 7, name: 'Lecture' }))
        wrapper.vm.openAddForm()

        // Left set, the next save would overwrite the habit that was open
        // instead of creating the new one.
        expect(wrapper.vm.editingHabit).toBeNull()
    })

    it('updates rather than creates when a habit is open', async () => {
        const wrapper = mountPage()

        wrapper.vm.editHabit(habit({ id: 7 }))
        await wrapper.vm.$nextTick()
        await wrapper.find('form').trigger('submit')

        expect(form.put).toHaveBeenCalledTimes(1)
        expect(form.post).not.toHaveBeenCalled()
    })

    it('creates when nothing is open', async () => {
        const wrapper = mountPage()

        wrapper.vm.openAddForm()
        await wrapper.vm.$nextTick()
        await wrapper.find('form').trigger('submit')

        expect(form.post).toHaveBeenCalledTimes(1)
        expect(form.put).not.toHaveBeenCalled()
    })
})

describe('deleting a habit', () => {
    it('demande avant, et ne supprime que sur confirmation', async () => {
        const wrapper = mountPage()

        wrapper.vm.demanderSuppression(habit({ id: 7, name: 'Méditation' }))
        await wrapper.vm.$nextTick()

        // On vérifie le DIALOGUE, pas seulement l'absence d'appel : un composant
        // qui n'ouvrirait rien du tout passerait la seconde assertion sans que
        // l'utilisateur ait jamais vu la question.
        expect(dialogue(wrapper).props('ouvert')).toBe(true)
        expect(dialogue(wrapper).props('description')).toContain('Méditation')
        expect(routerDelete).not.toHaveBeenCalled()

        wrapper.vm.confirmerSuppression()

        expect(routerDelete).toHaveBeenCalledTimes(1)
    })

    it('ne supprime rien quand on annule', async () => {
        const wrapper = mountPage()

        wrapper.vm.demanderSuppression(habit({ id: 7 }))
        await wrapper.vm.$nextTick()

        wrapper.vm.annulerSuppression()
        wrapper.vm.confirmerSuppression()

        expect(routerDelete).not.toHaveBeenCalled()
    })
})
