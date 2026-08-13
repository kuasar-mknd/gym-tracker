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

import TemplateCreate from '@/Pages/Workouts/Templates/Create.vue'
import TemplateEdit from '@/Pages/Workouts/Templates/Edit.vue'
import { passesSlot } from './pageStubs'

beforeAll(() => {
    globalThis.route = (name, params) => `/${name}/${JSON.stringify(params ?? '')}`
})

beforeEach(() => {
    forms.length = 0
    vi.restoreAllMocks()
})

const library = [
    { id: 1, name: 'Développé Couché' },
    { id: 2, name: 'Squat' },
]

const stubs = { AuthenticatedLayout: passesSlot, GlassCard: passesSlot, Modal: passesSlot }

const mountCreate = (exercises = library) =>
    mount(TemplateCreate, {
        props: { exercises: structuredClone(exercises) },
        global: { mocks: { route: globalThis.route }, stubs },
    })

const mountEdit = (template, exercises = library) =>
    mount(TemplateEdit, {
        props: { template: structuredClone(template), exercises: structuredClone(exercises) },
        global: { mocks: { route: globalThis.route }, stubs },
    })

/** The form each page drives; the quick-create form is the second one made. */
const templateForm = () => forms[0]

describe('opening an existing template for editing', () => {
    it('brings every set back, not just the exercises', () => {
        const wrapper = mountEdit({
            name: 'Push A',
            description: 'Haut du corps',
            workout_template_lines: [
                {
                    exercise_id: 1,
                    exercise: { name: 'Développé Couché' },
                    workout_template_sets: [
                        { reps: 12, weight: 40, is_warmup: true },
                        { reps: 5, weight: 100, is_warmup: false },
                    ],
                },
            ],
        })

        // The server's shape and the form's shape are different. A key lost in
        // this mapping does not fail — it silently opens the template with the
        // sets missing, and saving then deletes them.
        expect(wrapper.vm.form.exercises).toEqual([
            {
                id: 1,
                name: 'Développé Couché',
                sets: [
                    { reps: 12, weight: 40, is_warmup: true },
                    { reps: 5, weight: 100, is_warmup: false },
                ],
            },
        ])
    })

    it('opens a template that has no lines yet without breaking', () => {
        const wrapper = mountEdit({ name: 'Vide', description: null, workout_template_lines: [] })

        expect(wrapper.vm.form.exercises).toEqual([])
        expect(wrapper.vm.form.description).toBe('')
    })
})

describe.each([
    ['creation', () => mountCreate()],
    ['edition', () => mountEdit({ name: 'T', description: '', workout_template_lines: [] })],
])('the exercise picker on %s', (_name, mountPage) => {
    it('searches without regard to case', async () => {
        const wrapper = mountPage()

        wrapper.vm.searchQuery = 'squ'
        await wrapper.vm.$nextTick()

        expect(wrapper.vm.filteredExercises.map((e) => e.name)).toEqual(['Squat'])
    })

    it('only calls it a dead end once something has been typed', async () => {
        const wrapper = mountPage()

        // An empty library with an empty query is a first visit, not a failed
        // search — offering "create this one" there names nothing.
        //
        // Falsy rather than false on purpose: the computed is `query && …`, so
        // with nothing typed it hands back the empty string. `v-if` reads the
        // two the same, and pinning it to `false` would fail on a rewrite that
        // changed nothing a user could see.
        expect(wrapper.vm.hasNoResults).toBeFalsy()

        wrapper.vm.searchQuery = 'introuvable'
        await wrapper.vm.$nextTick()

        expect(wrapper.vm.hasNoResults).toBeTruthy()
    })

    it('adds a working set with the exercise, not an empty row', () => {
        const wrapper = mountPage()
        const before = wrapper.vm.form.exercises.length

        wrapper.vm.addExercise({ id: 2, name: 'Squat' })

        expect(wrapper.vm.form.exercises).toHaveLength(before + 1)
        expect(wrapper.vm.form.exercises.at(-1).sets).toEqual([{ reps: 10, weight: null, is_warmup: false }])
    })
})

describe('quick-creating an exercise from the template screen', () => {
    const failWith = (status, body = {}) =>
        vi.spyOn(globalThis, 'fetch').mockResolvedValue({
            ok: status >= 200 && status < 300,
            status,
            json: async () => body,
        })

    it('names the status when the server refuses for anything but validation', async () => {
        failWith(419)

        const wrapper = mountCreate()
        await wrapper.vm.createAndAddExercise()
        await flushPromises()

        // 419 on an expired token, 403, 500 — this branch used to only un-spin
        // the button, so the user pressed the button and the modal sat there.
        expect(wrapper.vm.createError).toContain('419')
        expect(templateForm().exercises).toHaveLength(0)
    })

    it('puts a validation refusal on the field it belongs to', async () => {
        failWith(422, { errors: { name: ['Ce nom existe déjà.'] } })

        const wrapper = mountCreate()
        await wrapper.vm.createAndAddExercise()
        await flushPromises()

        expect(wrapper.vm.createError).toBeNull()
        expect(forms[1].errors.name).toBe('Ce nom existe déjà.')
    })

    it('refuses to add an exercise the server answered without an id', async () => {
        failWith(200, { exercise: { name: 'Sans identifiant' } })

        const wrapper = mountCreate()
        await wrapper.vm.createAndAddExercise()
        await flushPromises()

        // Adding it anyway would put a row in the template that no save can
        // ever reference.
        expect(wrapper.vm.createError).toContain("n'a pas pu être lue")
        expect(templateForm().exercises).toHaveLength(0)
    })

    it('adds it to both the library and the template when it works', async () => {
        failWith(201, { exercise: { id: 9, name: 'Aabtiré' } })

        const wrapper = mountCreate()
        await wrapper.vm.createAndAddExercise()
        await flushPromises()

        expect(templateForm().exercises.at(-1).id).toBe(9)
        // Sorted, so the new one is findable where its name puts it rather than
        // stuck at the bottom of the picker.
        expect(wrapper.vm.localExercises[0].name).toBe('Aabtiré')
    })
})
