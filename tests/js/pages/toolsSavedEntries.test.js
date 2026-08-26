import { describe, it, expect, vi, beforeAll, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'

const post = vi.fn()
const routerDelete = vi.fn()
let form

vi.mock('@inertiajs/vue3', async () => {
    const { reactive } = await vi.importActual('vue')

    return {
        Head: { template: '<div />' },
        router: { delete: (...args) => routerDelete(...args) },
        // Reactive, because every one of these pages redraws off the form: the
        // pressed state of a toggle, the results block, the save button. A
        // plain object never invalidates those, and a click that changed
        // nothing would still read as correct.
        useForm: (data) => {
            form = reactive({
                ...data,
                processing: false,
                errors: {},
                post,
                reset: vi.fn(function reset() {
                    Object.assign(this, structuredClone(data))
                }),
            })

            return form
        },
    }
})

const haptic = vi.fn()
vi.mock('@/composables/useHaptics', () => ({ triggerHaptic: (...a) => haptic(...a) }))

import WilksCalculator from '@/Pages/Tools/WilksCalculator.vue'
import MacroCalculator from '@/Pages/Tools/MacroCalculator.vue'
import WaterTracker from '@/Pages/Tools/WaterTracker.vue'
import { passesSlot } from './pageStubs'

/**
 * La question est posée par un dialogue de l'application, plus par `confirm()`.
 *
 * Ce n'était pas qu'une affaire d'apparence : sur mobile, plusieurs navigateurs
 * suppriment la boîte native après quelques appels, et le geste s'exécute alors
 * SANS question. Une confirmation qui peut disparaître n'en est pas une.
 */
const dialogue = (wrapper) => wrapper.findComponent({ name: 'ConfirmDialog' })

const confirmer = async (wrapper) => {
    await dialogue(wrapper).vm.$emit('confirmer')
}

beforeAll(() => {
    globalThis.route = (name, params) => `/${name}/${JSON.stringify(params ?? '')}`
})

beforeEach(() => {
    vi.clearAllMocks()
})

/*
 * Awaited, not merely mounted. All three pages pull their charts in through
 * `defineAsyncComponent`, and a history long enough to draw one starts an
 * import chain that keeps resolving after the test has returned. Whatever it
 * logs then lands while the worker is already closing, and Vitest fails the
 * whole run with `EnvironmentTeardownError` while every test is green.
 */
const mountPage = async (page, props) => {
    const wrapper = mount(page, {
        props: structuredClone(props),
        global: {
            mocks: { route: globalThis.route },
            directives: { press: {} },
            stubs: { AuthenticatedLayout: passesSlot, GlassCard: passesSlot },
        },
    })

    await flushPromises()

    return wrapper
}

/** The delete buttons of a saved-entry list, in the order the rows are drawn. */
const deleteButtons = (wrapper, label) =>
    wrapper.findAll('button').filter((button) => button.attributes('aria-label')?.startsWith(label))

const pressed = (wrapper, text) =>
    wrapper
        .findAll('button')
        .filter((button) => button.text().includes(text))
        .map((button) => button.attributes('aria-pressed'))

/*
 * The three tool pages each keep a list of what the user saved, and each list
 * had never been rendered by a test: every one of them was mounted with an
 * empty history, so the row markup, the per-row delete button and the guard in
 * front of it were dead ground. A row that reads its neighbour's figures, or a
 * bin icon wired to the first entry whichever row it sits on, would have
 * shipped.
 */
describe('the Wilks history', () => {
    /*
     * 12:00 UTC on purpose. The row prints `toLocaleDateString()`, so the day
     * and the format follow whatever zone and locale the run happens to have;
     * only the year survives every offset from -12 to +14, and that is all this
     * assertion leans on.
     */
    const entries = [
        {
            id: 4,
            score: '412.678',
            lifted_weight: '600.0',
            body_weight: '90.5',
            unit: 'kg',
            created_at: '2026-06-15T12:00:00Z',
        },
        {
            id: 9,
            score: '301.204',
            lifted_weight: '405.5',
            body_weight: '75.0',
            unit: 'lbs',
            created_at: '2024-06-15T12:00:00Z',
        },
    ]

    it('keeps each row on its own entry', async () => {
        const wrapper = await mountPage(WilksCalculator, { history: entries })

        // Row by row, not `toContain` over the page: every figure below appears
        // somewhere either way, and two rows that swapped their loads would
        // read as correct.
        const rows = wrapper.findAll('.space-y-3 > div')

        expect(rows).toHaveLength(2)
        expect(rows[0].text()).toContain('600 kg / 90.5 kg')
        expect(rows[0].text()).toContain('2026')
        expect(rows[1].text()).toContain('405.5 lbs / 75 lbs')
        expect(rows[1].text()).toContain('2024')
    })

    it('rounds the badge score to a whole number', async () => {
        const wrapper = await mountPage(WilksCalculator, { history: entries })

        // The badge is 48px across; 412.678 does not fit and would wrap.
        expect(wrapper.findAll('.space-y-3 > div')[0].text()).toContain('413')
    })

    it('says the history is empty instead of showing an empty box, and only then', async () => {
        const empty = await mountPage(WilksCalculator, { history: [] })
        const stocked = await mountPage(WilksCalculator, { history: entries })

        expect(empty.text()).toContain('Aucun historique.')
        expect(stocked.text()).not.toContain('Aucun historique.')
    })

    it('deletes the row that was clicked, not the first one', async () => {
        const wrapper = await mountPage(WilksCalculator, { history: entries })

        await deleteButtons(wrapper, "Supprimer l'entrée")[1].trigger('click')
        await confirmer(wrapper)

        expect(routerDelete).toHaveBeenCalledTimes(1)
        expect(routerDelete.mock.calls[0][0]).toContain('"wilksScore":9')
    })

    it('ne supprime rien tant que la question n’est pas tranchée', async () => {
        const wrapper = await mountPage(WilksCalculator, { history: entries })

        await deleteButtons(wrapper, "Supprimer l'entrée")[1].trigger('click')

        expect(dialogue(wrapper).props('ouvert')).toBe(true)
        expect(routerDelete).not.toHaveBeenCalled()
    })

    it('leaves the row alone when the confirmation is refused', async () => {
        const wrapper = await mountPage(WilksCalculator, { history: entries })
        const confirmSpy = vi.spyOn(window, 'confirm').mockReturnValue(false)

        await deleteButtons(wrapper, "Supprimer l'entrée")[0].trigger('click')

        expect(routerDelete).not.toHaveBeenCalled()

        confirmSpy.mockRestore()
    })
})

describe('the Wilks entry form', () => {
    it('records the unit that was picked and shows which one is on', async () => {
        const wrapper = await mountPage(WilksCalculator, { history: [] })

        expect(pressed(wrapper, 'KG')).toEqual(['true'])

        await wrapper
            .findAll('button')
            .find((button) => button.text() === 'LBS')
            .trigger('click')

        expect(form.unit).toBe('lbs')
        // Both halves: the unit reaches the payload, and the pair of buttons
        // agrees on which one is selected. A toggle that sets the value but
        // never moves its highlight leaves the user reading the wrong scale.
        expect(pressed(wrapper, 'LBS')).toEqual(['true'])
        expect(pressed(wrapper, 'KG')).toEqual(['false'])
    })

    it('records the sex that was picked and shows which one is on', async () => {
        const wrapper = await mountPage(WilksCalculator, { history: [] })

        expect(pressed(wrapper, 'Homme')).toEqual(['true'])

        await wrapper
            .findAll('button')
            .find((button) => button.text() === 'Femme')
            .trigger('click')

        expect(form.gender).toBe('female')
        expect(pressed(wrapper, 'Femme')).toEqual(['true'])
        expect(pressed(wrapper, 'Homme')).toEqual(['false'])
    })

    it('writes each weight field into the field it is labelled with', async () => {
        const wrapper = await mountPage(WilksCalculator, { history: [] })

        // Two different numbers: with one value in both boxes a v-model bound
        // to the wrong key reads as correct.
        await wrapper.find('#wilks-body-weight').setValue('82.5')
        await wrapper.find('#wilks-lifted-weight').setValue('510')

        // Numbers, not the strings the DOM hands over: `isValid` compares them
        // with `> 0`, which a string would still satisfy, but the formula and
        // the server column both want a number.
        expect(form.body_weight).toBe(82.5)
        expect(form.lifted_weight).toBe(510)
    })
})

describe('the macro history', () => {
    const entries = [
        {
            id: 3,
            target_calories: 2100,
            protein: 180,
            carbs: 210,
            fat: 60,
            goal: 'cut',
            created_at: '2026-06-15T12:00:00Z',
        },
        {
            id: 8,
            target_calories: 2600,
            protein: 160,
            carbs: 300,
            fat: 80,
            goal: 'maintain',
            created_at: '2024-06-15T12:00:00Z',
        },
        {
            id: 11,
            target_calories: 3100,
            protein: 190,
            carbs: 380,
            fat: 95,
            goal: 'bulk',
            created_at: '2023-06-15T12:00:00Z',
        },
    ]

    it('keeps each row on its own calculation', async () => {
        const wrapper = await mountPage(MacroCalculator, { history: entries })
        const rows = wrapper.findAll('.space-y-3 > div')

        expect(rows).toHaveLength(3)
        expect(rows[0].text()).toContain('2100')
        expect(rows[0].text()).toContain('180P / 210C / 60L')
        expect(rows[1].text()).toContain('2600')
        expect(rows[1].text()).toContain('160P / 300C / 80L')
        expect(rows[2].text()).toContain('3100')
        expect(rows[2].text()).toContain('190P / 380C / 95L')
    })

    it('names each goal in French rather than echoing the stored key', async () => {
        const wrapper = await mountPage(MacroCalculator, { history: entries })
        const rows = wrapper.findAll('.space-y-3 > div')

        expect(rows[0].text()).toContain('Sèche')
        expect(rows[1].text()).toContain('Maintien')
        expect(rows[2].text()).toContain('Prise')
    })

    it('deletes the row that was clicked, not the first one', async () => {
        const wrapper = await mountPage(MacroCalculator, { history: entries })

        await deleteButtons(wrapper, "Supprimer l'entrée")[2].trigger('click')
        await confirmer(wrapper)

        expect(routerDelete.mock.calls[0][0]).toContain('"macroCalculation":11')
    })
})

describe('the macro form', () => {
    const filled = async () => {
        const wrapper = await mountPage(MacroCalculator, { history: [] })

        // Three different numbers, so a v-model on the wrong key shows up.
        const inputs = wrapper.findAll('input[type="number"]')
        await inputs[0].setValue('31')
        await inputs[1].setValue('183')
        await inputs[2].setValue('77')

        return wrapper
    }

    it('writes age, height and weight into the fields they are labelled with', async () => {
        await filled()

        expect([form.age, form.height, form.weight]).toEqual(['31', '183', '77'])
    })

    it('records the sex and the goal that were picked', async () => {
        const wrapper = await filled()

        await wrapper
            .findAll('button')
            .find((button) => button.text() === 'Femme')
            .trigger('click')
        await wrapper
            .findAll('button')
            .find((button) => button.text() === 'Prise')
            .trigger('click')

        expect(form.gender).toBe('female')
        expect(form.goal).toBe('bulk')
        expect(pressed(wrapper, 'Prise')).toEqual(['true'])
        expect(pressed(wrapper, 'Sèche')).toEqual(['false'])
        expect(pressed(wrapper, 'Maintien')).toEqual(['false'])
    })

    it('records the activity level chosen from the list', async () => {
        const wrapper = await filled()

        await wrapper.find('select').setValue('very')

        expect(form.activity_level).toBe('very')
    })

    it('says so with a buzz when the server refuses the calculation', async () => {
        const wrapper = await filled()

        wrapper.vm.saveCalculation()
        post.mock.calls[0][1].onError()

        expect(haptic).toHaveBeenCalledWith('error')
    })
})

describe('the water log', () => {
    const logs = [
        { id: 2, amount: 250, consumed_at: '2026-06-15T09:30:00Z' },
        { id: 5, amount: 1000, consumed_at: '2026-06-15T15:45:00Z' },
    ]

    it('names each entry by its own amount, on the row and on its bin', async () => {
        const wrapper = await mountPage(WaterTracker, { logs, todayTotal: 1250, history: [] })
        const rows = wrapper.findAll('.space-y-3 > div')

        expect(rows).toHaveLength(2)
        expect(rows[0].text()).toContain('250 ml')
        expect(rows[1].text()).toContain('1000 ml')

        // The bin is an icon; without the amount in its name a screen reader
        // hears "supprimer" twice with nothing to tell the two apart.
        expect(deleteButtons(wrapper, 'Supprimer').map((button) => button.attributes('aria-label'))).toEqual([
            "Supprimer l'entrée de 250 ml",
            "Supprimer l'entrée de 1000 ml",
        ])
    })

    it('deletes the entry that was clicked, not the first one', async () => {
        const wrapper = await mountPage(WaterTracker, { logs, todayTotal: 1250, history: [] })
        const confirmSpy = vi.spyOn(window, 'confirm').mockReturnValue(true)

        await deleteButtons(wrapper, 'Supprimer')[1].trigger('click')

        expect(routerDelete.mock.calls[0][0]).toContain('"waterLog":5')
        expect(haptic).toHaveBeenCalledWith('warning')

        confirmSpy.mockRestore()
    })

    it('keeps the entry when the confirmation is refused, and stays silent', async () => {
        const wrapper = await mountPage(WaterTracker, { logs, todayTotal: 1250, history: [] })
        const confirmSpy = vi.spyOn(window, 'confirm').mockReturnValue(false)

        await deleteButtons(wrapper, 'Supprimer')[0].trigger('click')

        expect(routerDelete).not.toHaveBeenCalled()
        // The buzz belongs to the deletion, not to the question.
        expect(haptic).not.toHaveBeenCalled()

        confirmSpy.mockRestore()
    })

    it('logs the amount each shortcut says it will', async () => {
        const wrapper = await mountPage(WaterTracker, { logs: [], todayTotal: 0, history: [] })
        const shortcut = (label) => wrapper.findAll('button').find((b) => b.attributes('aria-label') === label)

        // Each shortcut is checked against the amount in its own name, one at a
        // time. Three buttons that all sent 250 would look right on a count.
        await shortcut('Ajouter 250ml').trigger('click')
        expect(form.amount).toBe(250)

        await shortcut('Ajouter 500ml').trigger('click')
        expect(form.amount).toBe(500)

        // Named in litres, stored in millilitres — the one place the two units
        // meet, and the only shortcut where the name cannot be read off the
        // value.
        await shortcut('Ajouter 1L').trigger('click')
        expect(form.amount).toBe(1000)

        expect(post).toHaveBeenCalledTimes(3)
    })

    it('logs the custom amount that was typed, as a number', async () => {
        const wrapper = await mountPage(WaterTracker, { logs: [], todayTotal: 0, history: [] })

        await wrapper.find('input[type="number"]').setValue('330')

        const custom = wrapper.findAll('button').find((button) => button.text() === 'add')
        expect(custom.attributes('disabled')).toBeUndefined()

        await custom.trigger('click')

        // parseInt, not the raw string: the server column is an integer and a
        // quoted "330" is what a text input hands over.
        expect(form.amount).toBe(330)
        expect(post).toHaveBeenCalledTimes(1)
    })

    it('empties the custom box once the log is accepted', async () => {
        const wrapper = await mountPage(WaterTracker, { logs: [], todayTotal: 0, history: [] })

        await wrapper.find('input[type="number"]').setValue('330')
        await wrapper
            .findAll('button')
            .find((button) => button.text() === 'add')
            .trigger('click')

        post.mock.calls[0][1].onSuccess()
        await wrapper.vm.$nextTick()

        // Left as it was, the next tap would silently log the same 330 ml
        // again — and the button, still enabled, invites it.
        expect(wrapper.find('input[type="number"]').element.value).toBe('')
        expect(form.amount).toBe('')
    })
})
