import { describe, it, expect, vi, beforeAll, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'

/**
 * Three defects found by auditing the interface rather than by writing tests,
 * each pinned here so the fix cannot quietly come undone.
 */
const get = vi.fn()

vi.mock('axios', () => ({ default: { get: (...args) => get(...args) } }))

import ExerciseProgressCard from '@/Components/Stats/ExerciseProgressCard.vue'
import SwipeableRow from '@/Components/UI/SwipeableRow.vue'
import GlassSelect from '@/Components/UI/GlassSelect.vue'

beforeAll(() => {
    globalThis.route = (name, params) => `/${name}/${JSON.stringify(params ?? '')}`
})

beforeEach(() => {
    vi.clearAllMocks()
})

/** A promise whose settling this test controls. */
const deferred = () => {
    let resolve
    let reject
    const promise = new Promise((res, rej) => {
        resolve = res
        reject = rej
    })

    return { promise, resolve, reject }
}

describe('the 1RM progress card', () => {
    const EXERCISES = [
        { id: 1, name: 'Développé couché' },
        { id: 2, name: 'Squat' },
    ]

    const mountCard = () =>
        mount(ExerciseProgressCard, {
            props: { exercises: EXERCISES },
            global: {
                mocks: { route: globalThis.route },
                stubs: {
                    GlassCard: { template: '<div><slot /></div>' },
                    GlassSelect: { props: ['modelValue'], template: '<select />' },
                },
            },
        })

    it('ignores an answer about an exercise the user has already left', async () => {
        const first = deferred()
        const second = deferred()
        get.mockReturnValueOnce(first.promise).mockReturnValueOnce(second.promise)

        const wrapper = mountCard()

        wrapper.vm.selectedExercise = 1
        await flushPromises()
        wrapper.vm.selectedExercise = 2
        await flushPromises()

        // The newer request answers first, the older one after it. Applied in
        // arrival order, the chart would carry exercise 1's curve under
        // exercise 2's name — wrong numbers, presented confidently.
        second.resolve({ data: { progress: [{ date: '01/02', value: 100 }] } })
        await flushPromises()
        first.resolve({ data: { progress: [{ date: '01/01', value: 42 }] } })
        await flushPromises()

        expect(wrapper.vm.exerciseProgressData).toEqual([{ date: '01/02', value: 100 }])

        wrapper.unmount()
    })

    it('does not report a stale failure against the exercise now shown', async () => {
        const first = deferred()
        const second = deferred()
        get.mockReturnValueOnce(first.promise).mockReturnValueOnce(second.promise)

        const wrapper = mountCard()

        wrapper.vm.selectedExercise = 1
        await flushPromises()
        wrapper.vm.selectedExercise = 2
        await flushPromises()

        second.resolve({ data: { progress: [{ date: '01/02', value: 100 }] } })
        await flushPromises()
        first.reject(new Error('réseau'))
        await flushPromises()

        // The overtaken request failed, but its exercise is no longer on
        // screen. Showing "impossible de charger" over a chart that loaded
        // fine would be a lie about the one being displayed.
        expect(wrapper.vm.loadFailed).toBe(false)
        expect(wrapper.vm.loadingExercise).toBe(false)

        wrapper.unmount()
    })
})

describe('a row that swipes', () => {
    const mountRow = () =>
        mount(SwipeableRow, {
            props: { hasRightAction: true },
            global: { directives: { press: {} } },
            slots: { default: '<p>Rowing barre</p>', 'action-right': '<button>Supprimer</button>' },
        })

    /** The foreground layer, which is the element that moves. */
    const foreground = (wrapper) => wrapper.get('.z-10')

    const swipe = async (wrapper, from, to) => {
        await foreground(wrapper).trigger('touchstart', { touches: [{ clientX: from, clientY: 0 }] })
        await foreground(wrapper).trigger('touchmove', { touches: [{ clientX: to, clientY: 0 }] })
    }

    it('goes back to rest when the system takes the gesture away', async () => {
        const wrapper = mountRow()

        await swipe(wrapper, 200, 160)
        expect(foreground(wrapper).attributes('style')).toContain('translateX')

        // touchcancel fires when the browser or the OS claims the touch — a
        // call arriving, the notification shade, the back gesture from the
        // edge. Unhandled, the row stayed frozen mid-swipe with its actions
        // half-revealed, and no transition to carry it back.
        await foreground(wrapper).trigger('touchcancel')

        const style = foreground(wrapper).attributes('style')

        expect(style).toContain('translateX(0px)')
        expect(style).not.toContain('transition: none')

        wrapper.unmount()
    })

    it('still settles normally when the finger is simply lifted', async () => {
        const wrapper = mountRow()

        await swipe(wrapper, 200, 199)
        await foreground(wrapper).trigger('touchend')

        expect(foreground(wrapper).attributes('style')).toContain('translateX(0px)')

        wrapper.unmount()
    })
})

describe('a select that hides its label', () => {
    const mountSelect = (props) =>
        mount(GlassSelect, {
            props: { options: [{ value: 'a', label: 'A' }], ...props },
            global: { directives: { press: {} } },
        })

    it('keeps the label for screen readers rather than dropping it', () => {
        const wrapper = mountSelect({ label: 'Type d’exercice', hideLabel: true })
        const label = wrapper.get('label')

        // `hide-label` means visually hidden, not absent. Three selects in this
        // app passed it without a label at all and ended up with no accessible
        // name of any kind.
        expect(label.text()).toBe('Type d’exercice')
        expect(label.classes()).toContain('sr-only')
        expect(label.attributes('for')).toBe(wrapper.get('select').attributes('id'))
    })

    it('points at its error only when there is one', async () => {
        const healthy = mountSelect({ label: 'Catégorie' })

        // Emitted unconditionally, it sent a screen reader to a hidden element
        // holding nothing — on every healthy field on the page.
        expect(healthy.get('select').attributes('aria-describedby')).toBeUndefined()

        const failing = mountSelect({ label: 'Catégorie', error: 'Champ requis' })
        const described = failing.get('select').attributes('aria-describedby')

        expect(described).toBeDefined()
        expect(failing.get(`#${described}`).text()).toContain('Champ requis')

        healthy.unmount()
        failing.unmount()
    })
})
