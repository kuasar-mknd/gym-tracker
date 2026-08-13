import { describe, it, expect, vi, beforeAll, beforeEach, afterEach } from 'vitest'
import { mount } from '@vue/test-utils'
import { h, reactive } from 'vue'

/**
 * Four widgets that several screens lean on, each covered here through what it
 * promises the person using it rather than through its internals: whether the
 * menu is open, which fields a goal of a given kind asks for, what an exercise
 * row emits when it is acted on, and whether an account can be deleted without
 * the password being typed first.
 */
const hoisted = vi.hoisted(() => ({
    /** Every form handed out by the mocked `useForm`, newest last. */
    forms: [],
    formDelete: vi.fn(),
}))

/*
 * `useForm` is replaced by a *reactive* stand-in on purpose. A plain object
 * would let every assertion below pass while the component never re-rendered:
 * `form.processing`, `form.errors` and `form.password` are all read from the
 * template, and a non-reactive mock freezes the first render forever.
 */
vi.mock('@inertiajs/vue3', async () => {
    const { reactive: makeReactive } = await vi.importActual('vue')

    return {
        Head: { template: '<div />' },
        Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
        useForm: (fields) => {
            const form = makeReactive({
                ...fields,
                errors: {},
                processing: false,
                delete: (...args) => hoisted.formDelete(...args),
                clearErrors: () => {
                    form.errors = {}
                },
                reset: () => {
                    Object.assign(form, fields)
                },
            })

            hoisted.forms.push(form)

            return form
        },
    }
})

import Dropdown from '@/Components/UI/Dropdown.vue'
import GoalForm from '@/Components/Goals/GoalForm.vue'
import ExerciseCard from '@/Components/Workout/ExerciseCard.vue'
import DeleteUserForm from '@/Pages/Profile/Partials/DeleteUserForm.vue'
import SwipeableRow from '@/Components/UI/SwipeableRow.vue'

beforeAll(() => {
    globalThis.route = (name, params) => ['', name, ...Object.values(params ?? {})].join('/')
})

/**
 * `v-press` is a real directive of the design system; the tests only need it to
 * exist. `route` is Ziggy's global, which a `<script setup>` template resolves
 * through the app instance rather than through `globalThis`.
 */
const global = { directives: { press: {} }, mocks: { route: (...args) => globalThis.route(...args) } }

describe('Dropdown', () => {
    /** The trigger is a real button, as every caller renders one, so focus and Enter behave. */
    const mountDropdown = (props = {}) =>
        mount(Dropdown, {
            props,
            attachTo: document.body,
            global,
            slots: {
                trigger: (slotProps) =>
                    h(
                        'button',
                        { type: 'button', 'data-testid': 'trigger', 'aria-expanded': String(slotProps.open) },
                        'Menu',
                    ),
                // A real menu entry is a link; the default is stopped only so
                // jsdom does not complain about navigating away.
                content: () =>
                    h(
                        'a',
                        { href: '/profile', 'data-testid': 'item', onClick: (event) => event.preventDefault() },
                        'Profil',
                    ),
            },
        })

    /** The floating panel; `.z-50` is the only element carrying the menu. */
    const panel = (wrapper) => wrapper.get('.z-50')

    /** The full-screen catcher sitting between the page and the panel. */
    const backdrop = (wrapper) => wrapper.get('.z-40')

    const pressEscape = () => document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }))

    it('keeps the menu shut until the trigger is used', () => {
        const wrapper = mountDropdown()

        expect(panel(wrapper).isVisible()).toBe(false)
        expect(backdrop(wrapper).isVisible()).toBe(false)

        wrapper.unmount()
    })

    it('opens on the trigger, and shuts on the trigger again', async () => {
        const wrapper = mountDropdown()

        await wrapper.get('[data-testid="trigger"]').trigger('click')
        expect(panel(wrapper).isVisible()).toBe(true)

        await wrapper.get('[data-testid="trigger"]').trigger('click')
        expect(panel(wrapper).isVisible()).toBe(false)

        wrapper.unmount()
    })

    it('shuts when the page behind it is clicked', async () => {
        const wrapper = mountDropdown()

        await wrapper.get('[data-testid="trigger"]').trigger('click')
        await backdrop(wrapper).trigger('click')

        expect(panel(wrapper).isVisible()).toBe(false)

        wrapper.unmount()
    })

    it('shuts after a menu entry is chosen', async () => {
        // Without this the menu stays open over the page it has just navigated to.
        const wrapper = mountDropdown()

        await wrapper.get('[data-testid="trigger"]').trigger('click')
        await wrapper.get('[data-testid="item"]').trigger('click')

        expect(panel(wrapper).isVisible()).toBe(false)

        wrapper.unmount()
    })

    it('shuts on Escape', async () => {
        const wrapper = mountDropdown()

        await wrapper.get('[data-testid="trigger"]').trigger('click')
        pressEscape()
        await wrapper.vm.$nextTick()

        expect(panel(wrapper).isVisible()).toBe(false)

        wrapper.unmount()
    })

    it('never opens the menu on Escape', async () => {
        // Escape is a dismissal, not a toggle: a keystroke that opened a menu
        // nobody asked for would land on every page carrying the account menu.
        const wrapper = mountDropdown()

        pressEscape()
        await wrapper.vm.$nextTick()

        expect(panel(wrapper).isVisible()).toBe(false)

        wrapper.unmount()
    })

    it('tells the trigger whether the menu is open', async () => {
        // The slot prop is what lets a caller announce `aria-expanded`; a screen
        // reader has no other way of knowing the menu opened.
        const wrapper = mountDropdown()

        expect(wrapper.get('[data-testid="trigger"]').attributes('aria-expanded')).toBe('false')

        await wrapper.get('[data-testid="trigger"]').trigger('click')

        expect(wrapper.get('[data-testid="trigger"]').attributes('aria-expanded')).toBe('true')

        wrapper.unmount()
    })

    /*
     * Asserted through the listener registry rather than by pressing Escape after
     * unmount: the panel is detached by then, so a leaked listener is invisible
     * from the DOM. Handler identity is the point — removing a different function
     * leaves the old one attached, and the layout mounts one of these per page.
     */
    it('stops listening for Escape once it is gone', () => {
        const addSpy = vi.spyOn(document, 'addEventListener')
        const wrapper = mountDropdown()

        const registration = addSpy.mock.calls.find(([type]) => type === 'keydown')
        expect(registration).toBeDefined()

        const removeSpy = vi.spyOn(document, 'removeEventListener')
        wrapper.unmount()

        expect(removeSpy).toHaveBeenCalledWith('keydown', registration[1])

        addSpy.mockRestore()
        removeSpy.mockRestore()
    })

    it('hangs the menu from the side it was told to', () => {
        const classesFor = (align) => {
            const wrapper = mountDropdown(align === undefined ? {} : { align })
            const classes = panel(wrapper).classes()
            wrapper.unmount()

            return classes
        }

        expect(classesFor('right')).toContain('end-0')
        expect(classesFor('left')).toContain('start-0')

        // Right is the default, and it is what the account menu relies on.
        expect(classesFor(undefined)).toContain('end-0')

        // Anything else drops the anchoring rather than guessing a side.
        const centred = classesFor('center')
        expect(centred).toContain('origin-top')
        expect(centred).not.toContain('end-0')
        expect(centred).not.toContain('start-0')
    })

    it('gives the menu the width it was asked for', () => {
        const wrapper = mountDropdown({ width: '48' })

        expect(panel(wrapper).classes()).toContain('w-48')

        wrapper.unmount()
    })

    it('paints the panel with the content classes it was given', () => {
        const wrapper = mountDropdown({ contentClasses: 'py-2 bg-slate-900' })

        expect(panel(wrapper).get('.rounded-2xl.border').classes()).toEqual(
            expect.arrayContaining(['py-2', 'bg-slate-900']),
        )

        wrapper.unmount()
    })
})

describe('GoalForm', () => {
    const EXERCISES = [
        { id: 3, name: 'Développé couché' },
        { id: 5, name: 'Squat' },
    ]

    const MEASUREMENTS = [
        { value: 'chest', label: 'Poitrine' },
        { value: 'body_fat', label: 'Masse grasse' },
    ]

    /**
     * Stands in for the Inertia form the parent owns. Reactive, because the
     * component watches three of its fields and v-models into the rest.
     */
    const goalForm = (fields = {}) =>
        reactive({
            type: 'weight',
            exercise_id: '',
            measurement_type: '',
            target_value: '',
            start_value: '',
            title: '',
            deadline: '',
            processing: false,
            errors: {},
            ...fields,
        })

    const mountGoalForm = (form, props = {}) =>
        mount(GoalForm, {
            props: { form, exercises: EXERCISES, measurementTypes: MEASUREMENTS, ...props },
            global,
        })

    /** The visible label of every field, in the order they are read. */
    const fieldLabels = (wrapper) =>
        wrapper.findAll('label').map((label) =>
            label
                .text()
                .replace(/\s*\*$/, '')
                .trim(),
        )

    /** The control a label points at, found the way a user finds it: by its label. */
    const fieldNamed = (wrapper, text) => {
        const label = wrapper.findAll('label').find((candidate) => candidate.text().startsWith(text))

        return wrapper.get(`#${label.attributes('for')}`)
    }

    const isMarkedRequired = (wrapper, text) =>
        wrapper
            .findAll('label')
            .find((candidate) => candidate.text().startsWith(text))
            .text()
            .includes('*')

    /** What a screen reader would read out for a field, following aria-describedby. */
    const errorShownFor = (wrapper, text) => {
        const described = fieldNamed(wrapper, text).attributes('aria-describedby')

        return wrapper.get(`#${described}`).get('p').text()
    }

    it('asks for an exercise on a strength goal and on a volume goal', () => {
        for (const type of ['weight', 'volume']) {
            const wrapper = mountGoalForm(goalForm({ type }))

            expect(fieldLabels(wrapper)).toContain('Exercice')
            expect(fieldLabels(wrapper)).not.toContain('Mensuration')

            wrapper.unmount()
        }
    })

    it('asks for a measurement, and no exercise, on a measurement goal', () => {
        const wrapper = mountGoalForm(goalForm({ type: 'measurement' }))

        expect(fieldLabels(wrapper)).toContain('Mensuration')
        expect(fieldLabels(wrapper)).not.toContain('Exercice')

        wrapper.unmount()
    })

    it('asks for neither on a frequency goal', () => {
        const wrapper = mountGoalForm(goalForm({ type: 'frequency' }))

        expect(fieldLabels(wrapper)).not.toContain('Exercice')
        expect(fieldLabels(wrapper)).not.toContain('Mensuration')

        wrapper.unmount()
    })

    it('swaps the fields over when the kind of goal changes', async () => {
        const form = goalForm({ type: 'weight' })
        const wrapper = mountGoalForm(form)

        form.type = 'measurement'
        await wrapper.vm.$nextTick()

        expect(fieldLabels(wrapper)).toContain('Mensuration')
        expect(fieldLabels(wrapper)).not.toContain('Exercice')

        wrapper.unmount()
    })

    it('offers every kind of goal the server accepts', () => {
        const wrapper = mountGoalForm(goalForm())

        // The disabled first entry is the placeholder, not a choice.
        const options = [...fieldNamed(wrapper, "Type d'objectif").element.options]
            .filter((option) => !option.disabled)
            .map((option) => [option.value, option.text])

        expect(options).toEqual([
            ['weight', 'Force (Poids max)'],
            ['frequency', 'Fréquence (Séances)'],
            ['volume', 'Volume (Max par séance)'],
            ['measurement', 'Mensuration'],
        ])

        wrapper.unmount()
    })

    it('offers a deadline whatever the kind of goal', () => {
        for (const type of ['weight', 'frequency', 'volume', 'measurement']) {
            const wrapper = mountGoalForm(goalForm({ type }))

            expect(fieldLabels(wrapper)).toContain('Échéance (Optionnel)')

            wrapper.unmount()
        }
    })

    it('demands a target and a title, and lets the starting value be left out', () => {
        const wrapper = mountGoalForm(goalForm())

        expect(isMarkedRequired(wrapper, 'Valeur Cible')).toBe(true)
        expect(isMarkedRequired(wrapper, 'Titre du défi')).toBe(true)
        expect(isMarkedRequired(wrapper, 'Valeur de Départ')).toBe(false)
        expect(isMarkedRequired(wrapper, 'Échéance')).toBe(false)

        wrapper.unmount()
    })

    it("puts each of the server's complaints on the field it is about", async () => {
        const form = goalForm({
            type: 'measurement',
            errors: {
                type: 'Type inconnu',
                measurement_type: 'Mesure inconnue',
                target_value: 'Cible obligatoire',
                title: 'Titre obligatoire',
                deadline: 'Date passée',
            },
        })
        const wrapper = mountGoalForm(form)

        expect(errorShownFor(wrapper, "Type d'objectif")).toBe('Type inconnu')
        expect(errorShownFor(wrapper, 'Mensuration')).toBe('Mesure inconnue')
        expect(errorShownFor(wrapper, 'Valeur Cible')).toBe('Cible obligatoire')
        expect(errorShownFor(wrapper, 'Titre du défi')).toBe('Titre obligatoire')
        expect(errorShownFor(wrapper, 'Échéance (Optionnel)')).toBe('Date passée')

        wrapper.unmount()
    })

    it('submits without leaving the page', () => {
        const wrapper = mountGoalForm(goalForm())
        const event = new Event('submit', { bubbles: true, cancelable: true })

        wrapper.get('form').element.dispatchEvent(event)

        expect(wrapper.emitted('submit')).toHaveLength(1)
        expect(event.defaultPrevented).toBe(true)

        wrapper.unmount()
    })

    it('backs out without submitting', async () => {
        const wrapper = mountGoalForm(goalForm())

        const cancel = wrapper.findAll('button').find((button) => button.text() === 'Annuler')
        await cancel.trigger('click')

        expect(wrapper.emitted('cancel')).toHaveLength(1)
        expect(wrapper.emitted('submit')).toBeUndefined()

        wrapper.unmount()
    })

    it('names the submit button, defaulting to Enregistrer', () => {
        const byDefault = mountGoalForm(goalForm())
        expect(byDefault.get('[dusk="goal-submit"]').text()).toBe('Enregistrer')
        byDefault.unmount()

        const named = mountGoalForm(goalForm(), { submitLabel: 'Mettre à jour' })
        expect(named.get('[dusk="goal-submit"]').text()).toBe('Mettre à jour')
        named.unmount()
    })

    it('bars a second submission while the first is in flight', async () => {
        const form = goalForm()
        const wrapper = mountGoalForm(form)

        expect(wrapper.get('[dusk="goal-submit"]').attributes('disabled')).toBeUndefined()

        form.processing = true
        await wrapper.vm.$nextTick()

        expect(wrapper.get('[dusk="goal-submit"]').attributes('disabled')).toBeDefined()
        expect(wrapper.get('[dusk="goal-submit"]').attributes('aria-busy')).toBe('true')

        wrapper.unmount()
    })

    it('names a strength goal after its target and its exercise', async () => {
        const form = goalForm({ target_value: 100 })
        const wrapper = mountGoalForm(form, { autoTitle: true })

        form.exercise_id = 3
        await wrapper.vm.$nextTick()

        expect(form.title).toBe('Soulever 100 kg au Développé couché')

        wrapper.unmount()
    })

    it('recognises the exercise even though the select hands back a string', async () => {
        // `<select>` values are always strings; the ids they are compared with
        // come off the database as numbers. A strict comparison names nothing.
        const form = goalForm({ target_value: 100 })
        const wrapper = mountGoalForm(form, { autoTitle: true })

        form.exercise_id = '3'
        await wrapper.vm.$nextTick()

        expect(form.title).toBe('Soulever 100 kg au Développé couché')

        wrapper.unmount()
    })

    it('measures a body-fat goal in percent and every other one in centimetres', async () => {
        const inCm = goalForm({ type: 'measurement', target_value: 105 })
        const cmWrapper = mountGoalForm(inCm, { autoTitle: true })
        inCm.measurement_type = 'chest'
        await cmWrapper.vm.$nextTick()
        expect(inCm.title).toBe('Atteindre 105 cm de Poitrine')
        cmWrapper.unmount()

        const inPercent = goalForm({ type: 'measurement', target_value: 12 })
        const percentWrapper = mountGoalForm(inPercent, { autoTitle: true })
        inPercent.measurement_type = 'body_fat'
        await percentWrapper.vm.$nextTick()
        expect(inPercent.title).toBe('Atteindre 12 % de Masse grasse')
        percentWrapper.unmount()
    })

    it('names a frequency goal after the number of sessions', async () => {
        const form = goalForm({ target_value: 50 })
        const wrapper = mountGoalForm(form, { autoTitle: true })

        form.type = 'frequency'
        await wrapper.vm.$nextTick()

        expect(form.title).toBe('Atteindre 50 séances au total')

        wrapper.unmount()
    })

    it('leaves a question mark where the target has not been typed yet', async () => {
        // The title is written as soon as the kind of goal is chosen, which is
        // usually before the number is; `0` is a target nobody sets, so an empty
        // one must not read as "Soulever 0 kg".
        const byFrequency = goalForm()
        const frequencyWrapper = mountGoalForm(byFrequency, { autoTitle: true })
        byFrequency.type = 'frequency'
        await frequencyWrapper.vm.$nextTick()
        expect(byFrequency.title).toBe('Atteindre ? séances au total')
        frequencyWrapper.unmount()

        const byWeight = goalForm()
        const weightWrapper = mountGoalForm(byWeight, { autoTitle: true })
        byWeight.exercise_id = 5
        await weightWrapper.vm.$nextTick()
        expect(byWeight.title).toBe('Soulever ? kg au Squat')
        weightWrapper.unmount()

        const byMeasurement = goalForm({ type: 'measurement' })
        const measurementWrapper = mountGoalForm(byMeasurement, { autoTitle: true })
        byMeasurement.measurement_type = 'chest'
        await measurementWrapper.vm.$nextTick()
        expect(byMeasurement.title).toBe('Atteindre ? cm de Poitrine')
        measurementWrapper.unmount()
    })

    it('stops renaming the goal once a title has been typed', async () => {
        // Picking a different exercise used to throw away a title the user had
        // just written.
        const form = goalForm({ target_value: 100 })
        const wrapper = mountGoalForm(form, { autoTitle: true })

        await fieldNamed(wrapper, 'Titre du défi').setValue('Mon défi à moi')

        form.exercise_id = 5
        await wrapper.vm.$nextTick()

        expect(form.title).toBe('Mon défi à moi')

        wrapper.unmount()
    })

    it('never renames the goal on the edit form', async () => {
        // Editing an existing goal must not overwrite the title it already has.
        const form = goalForm({ target_value: 100, title: 'Objectif de la rentrée' })
        const wrapper = mountGoalForm(form)

        form.exercise_id = 3
        await wrapper.vm.$nextTick()

        expect(form.title).toBe('Objectif de la rentrée')

        wrapper.unmount()
    })

    it('leaves the title alone for an exercise it cannot find', async () => {
        const form = goalForm({ target_value: 100, title: 'Intact' })
        const wrapper = mountGoalForm(form, { autoTitle: true })

        form.exercise_id = 999
        await wrapper.vm.$nextTick()

        expect(form.title).toBe('Intact')

        wrapper.unmount()
    })

    it('leaves the title alone for a measurement it cannot find', async () => {
        const form = goalForm({ type: 'measurement', target_value: 40, title: 'Intact' })
        const wrapper = mountGoalForm(form, { autoTitle: true })

        form.measurement_type = 'envergure'
        await wrapper.vm.$nextTick()

        expect(form.title).toBe('Intact')

        wrapper.unmount()
    })

    it('names no volume goal on its own, leaving it to the user', async () => {
        // There is no sentence for a per-session volume; inventing one from the
        // strength wording would read "Soulever 8000 kg au Squat".
        const form = goalForm({ type: 'volume', target_value: 8000 })
        const wrapper = mountGoalForm(form, { autoTitle: true })

        form.exercise_id = 5
        await wrapper.vm.$nextTick()

        expect(form.title).toBe('')

        wrapper.unmount()
    })

    it('writes what is typed back into the form the parent owns', async () => {
        const form = goalForm()
        const wrapper = mountGoalForm(form)

        await fieldNamed(wrapper, "Type d'objectif").setValue('weight')
        await fieldNamed(wrapper, 'Exercice').setValue('5')
        await fieldNamed(wrapper, 'Valeur Cible').setValue('120')
        await fieldNamed(wrapper, 'Valeur de Départ').setValue('60')
        await fieldNamed(wrapper, 'Échéance').setValue('2026-12-25')

        expect(form.type).toBe('weight')
        expect(form.exercise_id).toBe('5')
        expect(form.target_value).toBe('120')
        expect(form.start_value).toBe('60')
        expect(form.deadline).toBe('2026-12-25')

        wrapper.unmount()
    })

    it('writes the measurement that was picked back into the form', async () => {
        const form = goalForm({ type: 'measurement' })
        const wrapper = mountGoalForm(form)

        await fieldNamed(wrapper, 'Mensuration').setValue('body_fat')

        expect(form.measurement_type).toBe('body_fat')

        wrapper.unmount()
    })
})

describe('ExerciseCard', () => {
    const EXERCISE = { id: 12, name: 'Soulevé de terre', type: 'strength', category: 'Dos' }

    /*
     * Deliberately no `fitness_center` among these: that string is the card's
     * fallback, and a map containing it cannot tell a lookup from a fallback.
     */
    const TYPE_ICONS = { strength: 'exercise', cardio: 'directions_run', timed: 'timer' }

    const BORDER_COLORS = { Dos: 'border-l-blue-500' }

    const typeLabel = (type) => ({ strength: 'Force', cardio: 'Cardio', timed: 'Temps' })[type] ?? type

    const editForm = (fields = {}) =>
        reactive({
            name: 'Soulevé de terre',
            type: 'strength',
            category: 'Dos',
            errors: {},
            processing: false,
            ...fields,
        })

    const mountCard = ({ exercise = EXERCISE, isEditing = false, form = editForm(), ...rest } = {}) =>
        mount(ExerciseCard, {
            props: {
                exercise,
                isEditing,
                editForm: form,
                category: exercise.category ?? 'Autres',
                types: [
                    { value: 'strength', label: 'Force' },
                    { value: 'cardio', label: 'Cardio' },
                ],
                categories: ['Dos', 'Pectoraux'],
                categoryBorderColors: BORDER_COLORS,
                typeIcons: TYPE_ICONS,
                typeLabel,
                ...rest,
            },
            global,
        })

    /** The round tile holding the type icon. */
    const iconTile = (wrapper) => wrapper.get('.size-14')

    it('shows the exercise, the label of its type and the icon that goes with it', () => {
        const wrapper = mountCard()

        expect(wrapper.text()).toContain('Soulevé de terre')
        expect(wrapper.text()).toContain('Force')
        expect(iconTile(wrapper).get('span').text()).toBe('exercise')

        wrapper.unmount()
    })

    it('falls back to a generic icon for a type it has none for', () => {
        const wrapper = mountCard({ exercise: { ...EXERCISE, type: 'mobility' } })

        expect(iconTile(wrapper).get('span').text()).toBe('fitness_center')

        wrapper.unmount()
    })

    it('gives strength, cardio and everything else their own colour', () => {
        const tileFor = (type) => {
            const wrapper = mountCard({ exercise: { ...EXERCISE, type } })
            const classes = iconTile(wrapper).classes().join(' ')
            wrapper.unmount()

            return classes
        }

        expect(tileFor('strength')).toContain('text-electric-orange')
        expect(tileFor('cardio')).toContain('bg-neon-green/30')
        expect(tileFor('timed')).toContain('text-cyan-pure')

        // The three branches must not collapse onto one another.
        expect(tileFor('strength')).not.toBe(tileFor('cardio'))
        expect(tileFor('cardio')).not.toBe(tileFor('timed'))
    })

    it('borders the card with the colour of its category, and greys an unknown one', () => {
        const known = mountCard()
        expect(known.get('[data-testid="exercise-card"]').classes()).toContain('border-l-blue-500')
        known.unmount()

        const unknown = mountCard({ exercise: { ...EXERCISE, category: 'Cardio doux' } })
        expect(unknown.get('[data-testid="exercise-card"]').classes()).toContain('border-l-slate-300')
        unknown.unmount()
    })

    it('links the row to the page of this exercise', () => {
        const wrapper = mountCard()

        expect(wrapper.get('[dusk="open-exercise-12"]').attributes('href')).toBe('/exercises.show/12')

        wrapper.unmount()
    })

    it('hands the whole exercise to the edit handler, from either button', async () => {
        for (const selector of ['[data-testid="edit-exercise-button"]', '[dusk="edit-exercise-btn-12"]']) {
            const wrapper = mountCard()

            await wrapper.get(selector).trigger('click')

            expect(wrapper.emitted('start-edit')).toHaveLength(1)
            expect(wrapper.emitted('start-edit')[0][0]).toEqual(EXERCISE)

            wrapper.unmount()
        }
    })

    it('hands only the id to the delete handler', async () => {
        const wrapper = mountCard()

        await wrapper.get('[data-testid="delete-exercise-button"]').trigger('click')

        expect(wrapper.emitted('delete')).toEqual([[12]])

        wrapper.unmount()
    })

    it('offers the same two actions behind a swipe', async () => {
        const wrapper = mountCard()

        await wrapper.get('[data-testid="edit-exercise-button-mobile"]').trigger('click')
        await wrapper.get('[data-testid="delete-exercise-button-mobile"]').trigger('click')

        expect(wrapper.emitted('start-edit')[0][0]).toEqual(EXERCISE)
        expect(wrapper.emitted('delete')).toEqual([[12]])

        wrapper.unmount()
    })

    it('names both buttons after the exercise they act on', () => {
        const wrapper = mountCard()

        expect(wrapper.get('[data-testid="edit-exercise-button"]').attributes('aria-label')).toBe(
            'Modifier Soulevé de terre',
        )
        expect(wrapper.get('[data-testid="delete-exercise-button"]').attributes('aria-label')).toBe(
            'Supprimer Soulevé de terre',
        )

        wrapper.unmount()
    })

    it('replaces the row with a form while it is being edited', () => {
        const wrapper = mountCard({ isEditing: true })

        expect(wrapper.find('[dusk="open-exercise-12"]').exists()).toBe(false)
        expect(wrapper.get('[dusk="edit-exercise-name"]').element.value).toBe('Soulevé de terre')
        expect(wrapper.get('[dusk="edit-exercise-type"]').element.value).toBe('strength')
        expect(wrapper.get('[dusk="edit-exercise-category"]').element.value).toBe('Dos')

        wrapper.unmount()
    })

    it('writes the edits back into the form the page owns', async () => {
        const form = editForm()
        const wrapper = mountCard({ isEditing: true, form })

        await wrapper.get('[dusk="edit-exercise-name"]').setValue('Soulevé roumain')
        await wrapper.get('[dusk="edit-exercise-type"]').setValue('cardio')
        await wrapper.get('[dusk="edit-exercise-category"]').setValue('')

        expect(form.name).toBe('Soulevé roumain')
        expect(form.type).toBe('cardio')
        expect(form.category).toBe('')

        wrapper.unmount()
    })

    it('lets an exercise be left without a category', () => {
        const wrapper = mountCard({ isEditing: true })

        const options = [...wrapper.get('[dusk="edit-exercise-category"]').element.options].map((option) => [
            option.value,
            option.text,
        ])

        expect(options).toEqual([
            ['', '— Aucune —'],
            ['Dos', 'Dos'],
            ['Pectoraux', 'Pectoraux'],
        ])

        wrapper.unmount()
    })

    it('saves on submit, handing back the exercise being edited', async () => {
        const wrapper = mountCard({ isEditing: true })
        const event = new Event('submit', { bubbles: true, cancelable: true })

        wrapper.get('form').element.dispatchEvent(event)

        expect(wrapper.emitted('update')).toEqual([[EXERCISE]])
        expect(event.defaultPrevented).toBe(true)

        wrapper.unmount()
    })

    it('backs out of the edit without saving', async () => {
        const wrapper = mountCard({ isEditing: true })

        await wrapper.get('[dusk="cancel-edit-btn"]').trigger('click')

        expect(wrapper.emitted('cancel-edit')).toHaveLength(1)
        expect(wrapper.emitted('update')).toBeUndefined()

        wrapper.unmount()
    })

    it('shows the reason the server refused the new name', () => {
        const wrapper = mountCard({ isEditing: true, form: editForm({ errors: { name: 'Nom déjà pris' } }) })

        expect(wrapper.text()).toContain('Nom déjà pris')

        wrapper.unmount()
    })

    it('locks the swipe while the row is being edited', () => {
        // Swiping a row that has turned into a form would drag the inputs away
        // from under the finger typing into them.
        const editing = mountCard({ isEditing: true })
        expect(editing.getComponent(SwipeableRow).props('disabled')).toBe(true)
        editing.unmount()

        const idle = mountCard()
        expect(idle.getComponent(SwipeableRow).props('disabled')).toBe(false)
        idle.unmount()
    })
})

describe('DeleteUserForm', () => {
    /**
     * The real Modal is a native <dialog> with its own test file; here it is a
     * passthrough that only honours `show`, so what is asserted is this
     * component's own gate rather than the dialog's machinery.
     */
    const ModalStub = {
        name: 'Modal',
        props: { show: { type: Boolean, default: false } },
        emits: ['close'],
        template: '<div v-if="show" class="modal"><slot /></div>',
    }

    const mountForm = () =>
        mount(DeleteUserForm, {
            attachTo: document.body,
            global: { ...global, stubs: { Modal: ModalStub } },
        })

    /** The Inertia form the component created on mount. */
    const formOf = () => hoisted.forms[hoisted.forms.length - 1]

    const openConfirmation = async (wrapper) => {
        await wrapper.get('[data-testid="delete-account-button"]').trigger('click')
    }

    const passwordField = (wrapper) => wrapper.get('[data-testid="delete-password-input"]')

    beforeEach(() => {
        hoisted.forms.length = 0
        hoisted.formDelete.mockReset()
    })

    afterEach(() => {
        vi.restoreAllMocks()
    })

    it('keeps the confirmation out of sight until it is asked for', () => {
        const wrapper = mountForm()

        expect(wrapper.find('.modal').exists()).toBe(false)
        expect(wrapper.find('[data-testid="confirm-delete-button"]').exists()).toBe(false)

        wrapper.unmount()
    })

    it('asks for the password instead of deleting straight away', async () => {
        const wrapper = mountForm()

        await openConfirmation(wrapper)

        expect(wrapper.find('.modal').exists()).toBe(true)
        expect(passwordField(wrapper).exists()).toBe(true)
        expect(hoisted.formDelete).not.toHaveBeenCalled()

        wrapper.unmount()
    })

    it('hides the password as it is typed', async () => {
        const wrapper = mountForm()
        await openConfirmation(wrapper)

        expect(passwordField(wrapper).attributes('type')).toBe('password')

        wrapper.unmount()
    })

    it('sends nothing while the password sits unconfirmed', async () => {
        const wrapper = mountForm()
        await openConfirmation(wrapper)

        await passwordField(wrapper).setValue('correct horse')

        expect(formOf().password).toBe('correct horse')
        expect(hoisted.formDelete).not.toHaveBeenCalled()

        wrapper.unmount()
    })

    it('deletes the account, with the password, once the confirmation is confirmed', async () => {
        const wrapper = mountForm()
        await openConfirmation(wrapper)
        await passwordField(wrapper).setValue('correct horse')

        await wrapper.get('[data-testid="confirm-delete-button"]').trigger('click')

        expect(hoisted.formDelete).toHaveBeenCalledTimes(1)

        const [url, options] = hoisted.formDelete.mock.calls[0]
        expect(url).toBe('/profile.destroy')
        expect(options.preserveScroll).toBe(true)
        expect(formOf().password).toBe('correct horse')

        wrapper.unmount()
    })

    it('accepts Enter in the password field as the confirmation', async () => {
        const wrapper = mountForm()
        await openConfirmation(wrapper)
        await passwordField(wrapper).setValue('correct horse')

        await passwordField(wrapper).trigger('keyup.enter')

        expect(hoisted.formDelete).toHaveBeenCalledTimes(1)

        wrapper.unmount()
    })

    it('shuts the confirmation when the server accepts', async () => {
        const wrapper = mountForm()
        await openConfirmation(wrapper)
        await wrapper.get('[data-testid="confirm-delete-button"]').trigger('click')

        hoisted.formDelete.mock.calls[0][1].onSuccess()
        await wrapper.vm.$nextTick()

        expect(wrapper.find('.modal').exists()).toBe(false)

        wrapper.unmount()
    })

    it('puts the cursor back in the password field when the server refuses', async () => {
        const wrapper = mountForm()
        await openConfirmation(wrapper)
        await wrapper.get('[data-testid="confirm-delete-button"]').trigger('click')

        // Focus goes elsewhere first, so the assertion cannot pass by accident.
        wrapper.get('[data-testid="cancel-delete-button"]').element.focus()

        hoisted.formDelete.mock.calls[0][1].onError()
        await wrapper.vm.$nextTick()

        expect(document.activeElement).toBe(passwordField(wrapper).element)
        expect(wrapper.find('.modal').exists()).toBe(true)

        wrapper.unmount()
    })

    it('empties the password once the request is over, whatever it answered', async () => {
        const wrapper = mountForm()
        await openConfirmation(wrapper)
        await passwordField(wrapper).setValue('correct horse')
        await wrapper.get('[data-testid="confirm-delete-button"]').trigger('click')

        hoisted.formDelete.mock.calls[0][1].onFinish()
        await wrapper.vm.$nextTick()

        expect(formOf().password).toBe('')

        wrapper.unmount()
    })

    it('deletes nothing when the user backs out, and forgets what was typed', async () => {
        const wrapper = mountForm()
        await openConfirmation(wrapper)
        await passwordField(wrapper).setValue('correct horse')
        formOf().errors = { password: 'Mot de passe incorrect' }

        await wrapper.get('[data-testid="cancel-delete-button"]').trigger('click')

        expect(hoisted.formDelete).not.toHaveBeenCalled()
        expect(wrapper.find('.modal').exists()).toBe(false)
        expect(formOf().password).toBe('')
        expect(formOf().errors).toEqual({})

        wrapper.unmount()
    })

    it('forgets what was typed when the dialog closes itself', async () => {
        // Escape on the native <dialog> reaches the component only as `close`.
        const wrapper = mountForm()
        await openConfirmation(wrapper)
        await passwordField(wrapper).setValue('correct horse')

        wrapper.getComponent(ModalStub).vm.$emit('close')
        await wrapper.vm.$nextTick()

        expect(wrapper.find('.modal').exists()).toBe(false)
        expect(formOf().password).toBe('')
        expect(hoisted.formDelete).not.toHaveBeenCalled()

        wrapper.unmount()
    })

    it('shows the reason the server refused', async () => {
        const wrapper = mountForm()
        await openConfirmation(wrapper)

        formOf().errors = { password: 'Mot de passe incorrect' }
        await wrapper.vm.$nextTick()

        const described = passwordField(wrapper).attributes('aria-describedby')
        expect(wrapper.get(`#${described}`).text()).toContain('Mot de passe incorrect')

        wrapper.unmount()
    })

    it('bars a second confirmation while the first is in flight', async () => {
        const wrapper = mountForm()
        await openConfirmation(wrapper)

        expect(wrapper.get('[data-testid="confirm-delete-button"]').attributes('disabled')).toBeUndefined()

        formOf().processing = true
        await wrapper.vm.$nextTick()

        expect(wrapper.get('[data-testid="confirm-delete-button"]').attributes('disabled')).toBeDefined()
        expect(wrapper.get('[data-testid="confirm-delete-button"]').attributes('aria-busy')).toBe('true')

        wrapper.unmount()
    })
})
