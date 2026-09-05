/**
 * The half of the template screens `workoutTemplates.test.js` leaves alone.
 *
 * That file — landing separately, so this one stands on its own — covers
 * opening a template, searching the picker, adding an exercise, and
 * quick-creating one from the creation page. What is left is everything that
 * happens *after* an exercise is in the form (its sets, their order, what gets
 * typed into them), saving, and the whole listing page, which no test had ever
 * mounted: launching a template, the guard against launching it twice, and
 * deleting one.
 */
import { describe, it, expect, vi, beforeAll, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'

const forms = []

vi.mock('@inertiajs/vue3', async () => {
    const { reactive } = await vi.importActual('vue')

    return {
        Head: { template: '<div />' },
        Link: { template: '<a><slot /></a>' },
        router: { get: vi.fn(), post: vi.fn(), delete: vi.fn() },
        useForm: (data) => {
            const form = reactive({
                ...data,
                processing: false,
                errors: {},
                post: vi.fn(),
                put: vi.fn(),
                reset: vi.fn(),
                clearErrors: vi.fn(),
                setError(key, message) {
                    this.errors[key] = message
                },
            })
            forms.push(form)

            return form
        },
    }
})

import { router } from '@inertiajs/vue3'
import TemplateCreate from '@/Pages/Workouts/Templates/Create.vue'
import TemplateEdit from '@/Pages/Workouts/Templates/Edit.vue'
import TemplateForm from '@/Components/Templates/TemplateForm.vue'
import AjoutDExerciceModal from '@/Components/Workout/AjoutDExerciceModal.vue'
import TemplateIndex from '@/Pages/Workouts/Templates/Index.vue'
import { passesSlot, layoutStub } from './pageStubs'

// Le formulaire vit dans TemplateForm, sous la page : ses internes se lisent là.
const interne = (wrapper) => {
    const formulaire = wrapper.findComponent(TemplateForm)

    return formulaire.exists() ? formulaire.vm : wrapper.vm
}

/** La question passe par un dialogue de l'application, plus par `confirm()`. */
const dialogue = (wrapper) => wrapper.findComponent({ name: 'ConfirmDialog' })

const confirmer = async (wrapper) => {
    await dialogue(wrapper).vm.$emit('confirmer')
}

beforeAll(() => {
    globalThis.route = (name, params) => `/${name}/${JSON.stringify(params ?? '')}`
})

beforeEach(() => {
    forms.length = 0
    vi.restoreAllMocks()
    localStorage.clear()
    router.post.mockClear()
    router.delete.mockClear()
})

const library = [
    { id: 1, name: 'Développé Couché', category: 'Pectoraux' },
    { id: 2, name: 'Squat', category: 'Jambes' },
]

/*
 * GlassButton is left real on purpose: the listing page relies on it to turn
 * `:disabled` into a disabled attribute, and a stub would happily swallow that
 * prop and let the double-launch guard look enforced when it is not. `v-press`
 * is registered empty because it is tested on its own in vPress.test.js.
 */
const stubs = { AuthenticatedLayout: layoutStub, GlassCard: passesSlot, Modal: passesSlot }

/*
 * Read at mount time, never at import time: `globalThis.route` is only set in
 * `beforeAll`, and the listing page calls `route()` straight from its template.
 */
const globalOptions = () => ({ mocks: { route: globalThis.route }, stubs, directives: { press: {} } })

const mountCreate = () =>
    mount(TemplateCreate, { props: { exercises: structuredClone(library) }, global: globalOptions() })

const mountEdit = (template = { id: 42, name: 'Push A', description: '', workout_template_lines: [] }) =>
    mount(TemplateEdit, {
        props: { template: structuredClone(template), exercises: structuredClone(library) },
        global: globalOptions(),
    })

const mountIndex = (templates) =>
    mount(TemplateIndex, { props: { templates: structuredClone(templates) }, global: globalOptions() })

/** The template's own form; the quick-create one is the second `useForm` made. */
const templateForm = () => forms[0]

/** Puts exercises into the form the way the picker would have. */
const seed = async (wrapper, exercises) => {
    interne(wrapper).form.exercises = structuredClone(exercises)
    await interne(wrapper).$nextTick()
}

const twoExercises = [
    { id: 1, name: 'Développé Couché', sets: [{ reps: 10, weight: 40, is_warmup: false }] },
    { id: 2, name: 'Squat', sets: [{ reps: 5, weight: 100, is_warmup: false }] },
]

const byLabel = (wrapper, label) => wrapper.findAll(`[aria-label="${label}"]`)

const openPicker = async (wrapper) => {
    const opener = wrapper.findAll('button').find((b) => b.text().includes('+ Ajouter un exercice'))
    await opener.trigger('click')

    return opener
}

// ---------------------------------------------------------------------------
// The listing page
// ---------------------------------------------------------------------------

describe('the template listing', () => {
    const pushA = {
        id: 7,
        name: 'Push A',
        description: 'Haut du corps',
        workout_template_lines_count: 2,
        workout_template_lines: [
            { id: 11, exercise: { name: 'Développé Couché' }, workout_template_sets_count: 4 },
            { id: 12, exercise: { name: 'Dips' }, workout_template_sets_count: 3 },
        ],
    }

    it('offers a way in rather than an empty grid when nothing has been created', () => {
        const wrapper = mountIndex([])

        expect(wrapper.text()).toContain('Aucun modèle')
        expect(wrapper.text()).toContain('Créer mon premier modèle')
        expect(wrapper.find('[dusk="execute-template-7"]').exists()).toBe(false)
    })

    /** One row of the exercise list, name and count read together. */
    const exerciseRows = (wrapper) =>
        wrapper
            .findAll('span.text-text-main.font-medium')
            .map((name) => name.element.parentElement.textContent.replace(/\s+/g, ' ').trim())

    it('lists each template with its exercises and their set counts', () => {
        const wrapper = mountIndex([pushA])

        expect(wrapper.text()).not.toContain('Aucun modèle')
        expect(wrapper.text()).toContain('Push A')
        expect(wrapper.text()).toContain('Haut du corps')

        // Row by row, in the order the template holds them. Two counts read off
        // the whole page are satisfied by a card that hands each exercise the
        // other one's number, which is the mistake a shared row template makes.
        expect(exerciseRows(wrapper)).toEqual(['Développé Couché• 4 séries', 'Dips• 3 séries'])
    })

    it('says zero rather than nothing when the server sent no set count', () => {
        const wrapper = mountIndex([{ ...pushA, workout_template_lines: [{ id: 11, exercise: { name: 'Dips' } }] }])

        // The count is a `withCount`, so it is absent — not zero — on any
        // payload that forgot it. Printing an empty gap reads as a bug.
        expect(wrapper.text()).toContain('• 0 séries')
    })

    it('stays silent about extra exercises when the card already shows them all', () => {
        // Exactly three lines fit; `> 3` and `>= 3` disagree only here.
        const wrapper = mountIndex([{ ...pushA, workout_template_lines_count: 3 }])

        expect(wrapper.text()).not.toContain('exercices')
    })

    it('counts the overflow from the fourth exercise on', () => {
        const wrapper = mountIndex([{ ...pushA, workout_template_lines_count: 4 }])

        expect(wrapper.text()).toContain('+ 1 exercices')
    })

    it('launches the template that was tapped', async () => {
        const wrapper = mountIndex([pushA])

        await wrapper.find('[dusk="execute-template-7"]').trigger('click')

        expect(router.post).toHaveBeenCalledTimes(1)
        expect(router.post.mock.calls[0][0]).toBe('/templates.execute/{"template":7}')
    })

    it('ignores a second launch fired before the buttons have had time to grey out', () => {
        const other = { ...pushA, id: 8, name: 'Pull A' }
        const wrapper = mountIndex([pushA, other])

        // Deliberately not through the DOM and deliberately without awaiting a
        // render: `:disabled` only lands on the next tick, so between two taps
        // of an impatient thumb the in-code guard is the only thing standing
        // between the user and two identical workouts to delete by hand.
        interne(wrapper).executeTemplate(7)
        interne(wrapper).executeTemplate(8)

        expect(router.post).toHaveBeenCalledTimes(1)
        expect(router.post.mock.calls[0][0]).toBe('/templates.execute/{"template":7}')
    })

    it('marks the one being launched and locks the others until it lands', async () => {
        const other = { ...pushA, id: 8, name: 'Pull A' }
        const wrapper = mountIndex([pushA, other])

        expect(wrapper.find('[dusk="execute-template-7"]').text()).toBe('Lancer cette séance')

        await wrapper.find('[dusk="execute-template-7"]').trigger('click')

        expect(wrapper.find('[dusk="execute-template-7"]').text()).toBe('Lancement…')
        expect(wrapper.find('[dusk="execute-template-8"]').attributes('disabled')).toBeDefined()
    })

    it('takes launches again once the request has finished', async () => {
        const wrapper = mountIndex([pushA])

        await wrapper.find('[dusk="execute-template-7"]').trigger('click')
        router.post.mock.calls[0][2].onFinish()
        await interne(wrapper).$nextTick()

        expect(wrapper.find('[dusk="execute-template-7"]').text()).toBe('Lancer cette séance')

        await wrapper.find('[dusk="execute-template-7"]').trigger('click')

        expect(router.post).toHaveBeenCalledTimes(2)
    })

    it('supprime le modèle une fois la confirmation donnée', async () => {
        const wrapper = mountIndex([pushA])

        await wrapper.find('[dusk="delete-template-7"]').trigger('click')

        expect(dialogue(wrapper).props('ouvert')).toBe(true)
        expect(router.delete).not.toHaveBeenCalled()

        await confirmer(wrapper)

        expect(router.delete).toHaveBeenCalledTimes(1)
        expect(router.delete.mock.calls[0][0]).toBe('/templates.destroy/{"template":7}')
    })

    it('keeps the template when the confirmation is declined', async () => {
        vi.spyOn(window, 'confirm').mockReturnValue(false)

        const wrapper = mountIndex([pushA])
        await wrapper.find('[dusk="delete-template-7"]').trigger('click')

        expect(router.delete).not.toHaveBeenCalled()
    })
})

// ---------------------------------------------------------------------------
// The sets of an exercise, on both editing screens
// ---------------------------------------------------------------------------

describe.each([
    ['creation', () => mountCreate()],
    ['edition', () => mountEdit()],
])('the sets of an exercise on %s', (_name, mountPage) => {
    it('numbers the rows from one, the way the user counts them', async () => {
        const wrapper = mountPage()
        await seed(wrapper, [
            {
                id: 1,
                name: 'Squat',
                sets: [
                    { reps: 10, weight: 60, is_warmup: false },
                    { reps: 8, weight: 80, is_warmup: false },
                ],
            },
        ])

        const badges = wrapper.findAll('.h-8.w-8').map((n) => n.text())

        expect(badges).toEqual(['1', '2'])
    })

    it('adds the new set to the exercise that asked for it', async () => {
        const wrapper = mountPage()
        await seed(wrapper, twoExercises)

        const adders = wrapper.findAll('button').filter((b) => b.text() === '+ Ajouter une série')
        await adders[1].trigger('click')

        expect(interne(wrapper).form.exercises[0].sets).toHaveLength(1)
        expect(interne(wrapper).form.exercises[1].sets).toHaveLength(2)
    })

    it('starts a new set at ten reps with no weight and no warm-up flag', async () => {
        const wrapper = mountPage()
        await seed(wrapper, [{ id: 1, name: 'Squat', sets: [] }])

        interne(wrapper).addSet(0)

        expect(interne(wrapper).form.exercises[0].sets).toEqual([{ reps: 10, weight: null, is_warmup: false }])
    })

    it('removes the set that was tapped and leaves the ones around it', async () => {
        const wrapper = mountPage()
        await seed(wrapper, [
            {
                id: 1,
                name: 'Squat',
                sets: [
                    { reps: 10, weight: 60, is_warmup: false },
                    { reps: 20, weight: 70, is_warmup: false },
                    { reps: 30, weight: 80, is_warmup: false },
                ],
            },
        ])

        await byLabel(wrapper, 'Supprimer la série')[1].trigger('click')

        expect(interne(wrapper).form.exercises[0].sets.map((s) => s.reps)).toEqual([10, 30])
    })

    it('removes the exercise that was tapped and leaves the ones around it', async () => {
        const wrapper = mountPage()
        await seed(wrapper, [
            { id: 1, name: 'Développé Couché', sets: [] },
            { id: 2, name: 'Squat', sets: [] },
            { id: 3, name: 'Soulevé de Terre', sets: [] },
        ])

        // Le libelle nomme l'exercice, donc le temoin designe la carte visee
        // plutot que son rang.
        await byLabel(wrapper, 'Supprimer Squat')[0].trigger('click')

        expect(interne(wrapper).form.exercises.map((e) => e.name)).toEqual(['Développé Couché', 'Soulevé de Terre'])
    })

    it('toggles the warm-up flag of one set both ways', async () => {
        const wrapper = mountPage()
        await seed(wrapper, twoExercises)

        const toggle = byLabel(wrapper, "Série d'échauffement")[0]
        expect(toggle.attributes('aria-pressed')).toBe('false')

        await toggle.trigger('click')

        expect(interne(wrapper).form.exercises[0].sets[0].is_warmup).toBe(true)
        expect(toggle.attributes('aria-pressed')).toBe('true')
        // The other exercise must not have been dragged along.
        expect(interne(wrapper).form.exercises[1].sets[0].is_warmup).toBe(false)

        await toggle.trigger('click')

        expect(interne(wrapper).form.exercises[0].sets[0].is_warmup).toBe(false)
    })

    it('writes typed reps and weight back into the set being edited', async () => {
        const wrapper = mountPage()
        await seed(wrapper, twoExercises)

        await wrapper.findAll('input[placeholder="réps"]')[1].setValue('12')
        await wrapper.findAll('input[placeholder="kg"]')[1].setValue('102.5')

        // Numbers, not strings: `type="number"` makes Vue cast, and the server
        // validates these as numeric.
        expect(interne(wrapper).form.exercises[1].sets[0].reps).toBe(12)
        expect(interne(wrapper).form.exercises[1].sets[0].weight).toBe(102.5)
        expect(interne(wrapper).form.exercises[0].sets[0].reps).toBe(10)
    })
})

// ---------------------------------------------------------------------------
// The description counter, on both editing screens
// ---------------------------------------------------------------------------

describe.each([
    ['creation', () => mountCreate()],
    ['edition', () => mountEdit()],
])('the description counter on %s', (_name, mountPage) => {
    const counterOf = (wrapper) => wrapper.find(`#${wrapper.find('textarea').attributes('aria-describedby')}`)

    it('counts an empty description as zero rather than printing nothing', () => {
        const wrapper = mountPage()

        expect(counterOf(wrapper).text()).toBe('0 / 1000')
    })

    it('counts what is actually typed into the textarea', async () => {
        const wrapper = mountPage()

        await wrapper.get('textarea').setValue('Haut du corps')

        expect(interne(wrapper).form.description).toBe('Haut du corps')
        expect(counterOf(wrapper).text()).toBe('13 / 1000')
    })

    it('stays calm at exactly the thousand characters the field accepts', async () => {
        const wrapper = mountPage()
        await wrapper.get('textarea').setValue('a'.repeat(1000))

        const counter = counterOf(wrapper)

        // maxlength is 1000, so 1000 is legal — turning red there would flag a
        // description the server is perfectly happy with.
        expect(counter.text()).toBe('1000 / 1000')
        expect(counter.classes()).not.toContain('text-accent-danger-deep')
    })

    it('turns red on the first character over the limit', async () => {
        const wrapper = mountPage()
        await wrapper.get('textarea').setValue('a'.repeat(1001))

        expect(counterOf(wrapper).text()).toBe('1001 / 1000')
        expect(counterOf(wrapper).classes()).toContain('text-accent-danger-deep')
    })
})

// ---------------------------------------------------------------------------
// The picker modal, on both editing screens
// ---------------------------------------------------------------------------

describe.each([
    ['creation', () => mountCreate()],
    ['edition', () => mountEdit()],
])('the picker modal on %s', (_name, mountPage) => {
    it('opens on the exercise list, not on the creation form', async () => {
        const wrapper = mountPage()
        await openPicker(wrapper)

        expect(interne(wrapper).showAddExercise).toBe(true)
        expect(wrapper.get('#add-exercise-title').text()).toBe('Ajouter un exercice')
        expect(wrapper.find('[dusk="new-exercise-name"]').exists()).toBe(false)
    })

    it('narrows the rendered list to what was typed in the search box', async () => {
        const wrapper = mountPage()
        await openPicker(wrapper)

        await wrapper.get('input[placeholder="Rechercher..."]').setValue('squ')

        const offered = wrapper
            .findAll('button')
            .filter((b) => b.text().includes('Jambes') || b.text().includes('Pectoraux'))

        expect(offered).toHaveLength(1)
        expect(offered[0].text()).toContain('Squat')
    })

    it('adds the exercise whose row was tapped and gets out of the way', async () => {
        const wrapper = mountPage()
        await openPicker(wrapper)

        const squat = wrapper.findAll('button').find((b) => b.text().includes('Jambes'))
        await squat.trigger('click')

        expect(interne(wrapper).form.exercises.map((e) => e.name)).toEqual(['Squat'])
        expect(interne(wrapper).showAddExercise).toBe(false)
    })

    it('shows what the server said about a description it rejected', async () => {
        const wrapper = mountPage()
        templateForm().errors.description = 'La description ne peut pas dépasser 1000 caractères.'
        await interne(wrapper).$nextTick()

        expect(wrapper.text()).toContain('La description ne peut pas dépasser 1000 caractères.')
    })
})

// ---------------------------------------------------------------------------
// Opening a template the server described sparsely
// ---------------------------------------------------------------------------

describe('opening a template the server described sparsely', () => {
    it('gives a line with no sets an empty list rather than crashing on it', () => {
        const wrapper = mountEdit({
            id: 42,
            name: 'Push A',
            description: null,
            workout_template_lines: [{ exercise_id: 1, exercise: { name: 'Squat' } }],
        })

        // `workout_template_sets` is a relation, so it is missing — not empty —
        // whenever the controller forgot to eager-load it.
        expect(interne(wrapper).form.exercises).toEqual([{ uid: expect.any(Number), id: 1, name: 'Squat', sets: [] }])
    })

    it('opens a template that arrived without any lines key at all', () => {
        const wrapper = mountEdit({ id: 42, name: 'Push A', description: null })

        expect(interne(wrapper).form.exercises).toEqual([])
    })

    it.each([
        ['creation', TemplateCreate, { exercises: null }],
        [
            'edition',
            TemplateEdit,
            { template: { id: 42, name: 'Push A', workout_template_lines: [] }, exercises: null },
        ],
    ])('survives an exercise library that came back null on %s', (_name, Page, props) => {
        // The prop default only fires on `undefined`; an explicit null is what
        // the `|| []` is there for.
        const wrapper = mount(Page, { props, global: globalOptions() })

        expect(interne(wrapper).localExercises).toEqual([])
    })
})

describe('an exercise created from the picker while editing', () => {
    it('joins the library where its name puts it, then the template', async () => {
        const wrapper = mountEdit()
        const modale = wrapper.findComponent(AjoutDExerciceModal)

        modale.vm.$emit('created', { id: 9, name: 'Aabtiré' })
        modale.vm.$emit('add', 9)
        await flushPromises()

        expect(interne(wrapper).localExercises[0].name).toBe('Aabtiré')
        expect(templateForm().exercises.at(-1)).toMatchObject({ id: 9, name: 'Aabtiré' })
    })
})

// ---------------------------------------------------------------------------
// Saving
// ---------------------------------------------------------------------------

describe('saving a template', () => {
    const nameField = (wrapper) => wrapper.get('input[placeholder="ex: Full Body Lundi"]')

    it('creates a new one with a POST to the collection', async () => {
        const wrapper = mountCreate()

        expect(nameField(wrapper).element.value).toBe('')
        await nameField(wrapper).setValue('Full Body Lundi')
        await wrapper.get('form').trigger('submit')

        expect(templateForm().name).toBe('Full Body Lundi')
        expect(templateForm().post).toHaveBeenCalledWith('/templates.store/""')
        expect(templateForm().put).not.toHaveBeenCalled()
    })

    it('updates the one being edited with a PUT that names it', async () => {
        const wrapper = mountEdit({ id: 42, name: 'Push A', description: '', workout_template_lines: [] })

        // Opening an edit form on a blank name would silently wipe it on save.
        expect(nameField(wrapper).element.value).toBe('Push A')
        await nameField(wrapper).setValue('Push B')
        await wrapper.get('form').trigger('submit')

        expect(templateForm().name).toBe('Push B')
        // A POST here would create a second template instead of replacing one.
        expect(templateForm().put).toHaveBeenCalledWith('/templates.update/{"template":42}')
        expect(templateForm().post).not.toHaveBeenCalled()
    })
})
