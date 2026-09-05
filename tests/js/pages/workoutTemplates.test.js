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
import TemplateForm from '@/Components/Templates/TemplateForm.vue'
import AjoutDExerciceModal from '@/Components/Workout/AjoutDExerciceModal.vue'
import { passesSlot } from './pageStubs'

// Le formulaire vit dans TemplateForm, sous la page : ses internes se lisent là.
const interne = (wrapper) => {
    const formulaire = wrapper.findComponent(TemplateForm)

    return formulaire.exists() ? formulaire.vm : wrapper.vm
}

beforeAll(() => {
    globalThis.route = (name, params) => `/${name}/${JSON.stringify(params ?? '')}`
})

beforeEach(() => {
    forms.length = 0
    localStorage.clear()
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
        expect(interne(wrapper).form.exercises).toEqual([
            {
                uid: expect.any(Number),
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

        expect(interne(wrapper).form.exercises).toEqual([])
        expect(interne(wrapper).form.description).toBe('')
    })
})

describe.each([
    ['creation', () => mountCreate()],
    ['edition', () => mountEdit({ name: 'T', description: '', workout_template_lines: [] })],
])('the exercise picker on %s', (_name, mountPage) => {
    it('adds a working set with the exercise, not an empty row', () => {
        const wrapper = mountPage()
        const before = interne(wrapper).form.exercises.length

        interne(wrapper).addExercise(2)

        expect(interne(wrapper).form.exercises).toHaveLength(before + 1)
        expect(interne(wrapper).form.exercises.at(-1).sets).toEqual([{ reps: 10, weight: null, is_warmup: false }])
    })
})

describe('an exercise created from the picker', () => {
    it('joins the library where its name puts it, then the template', async () => {
        const wrapper = mountCreate()
        const modale = wrapper.findComponent(AjoutDExerciceModal)

        modale.vm.$emit('created', { id: 9, name: 'Aabtiré' })
        modale.vm.$emit('add', 9)
        await flushPromises()

        expect(interne(wrapper).localExercises[0].name).toBe('Aabtiré')
        expect(templateForm().exercises.at(-1)).toMatchObject({ id: 9, name: 'Aabtiré' })
        expect(interne(wrapper).showAddExercise).toBe(false)
    })
})

const troisExercices = {
    name: 'Push A',
    description: '',
    workout_template_lines: [
        { exercise_id: 1, exercise: { name: 'Développé Couché' }, workout_template_sets: [] },
        { exercise_id: 2, exercise: { name: 'Squat' }, workout_template_sets: [] },
        { exercise_id: 3, exercise: { name: 'Rowing' }, workout_template_sets: [] },
    ],
}

describe('reordering the exercises of a template', () => {
    const noms = (wrapper) => interne(wrapper).form.exercises.map((e) => e.name)

    it('moves an exercise up', () => {
        const wrapper = mountEdit(troisExercices)

        interne(wrapper).moveExercise(2, -1)

        expect(noms(wrapper)).toEqual(['Développé Couché', 'Rowing', 'Squat'])
    })

    it('moves an exercise down', () => {
        const wrapper = mountEdit(troisExercices)

        interne(wrapper).moveExercise(0, 1)

        expect(noms(wrapper)).toEqual(['Squat', 'Développé Couché', 'Rowing'])
    })

    /**
     * Les bornes sont gardees dans le gestionnaire ET par `:disabled`. Ici
     * c'est le gestionnaire : un `splice` a -1 insere en fin de tableau,
     * silencieusement.
     */
    it('refuses to move past either end', () => {
        const wrapper = mountEdit(troisExercices)

        interne(wrapper).moveExercise(0, -1)
        interne(wrapper).moveExercise(2, 1)

        expect(noms(wrapper)).toEqual(['Développé Couché', 'Squat', 'Rowing'])
    })

    /**
     * La clef doit suivre l'EXERCICE, pas son rang. Avec `:key` par index, Vue
     * reutilise le noeud du rang : le focus reste sur la carte d'a cote, et un
     * champ en cours de saisie change d'exercice sous les doigts.
     */
    it('carries each key with its exercise, not its rank', () => {
        const wrapper = mountEdit(troisExercices)

        const avant = interne(wrapper).form.exercises.map((e) => e.uid)
        interne(wrapper).moveExercise(0, 1)
        const apres = interne(wrapper).form.exercises.map((e) => e.uid)

        expect(new Set(avant).size).toBe(3)
        expect(apres).toEqual([avant[1], avant[0], avant[2]])
    })

    it('disables the arrow that would leave the list', async () => {
        const wrapper = mountEdit(troisExercices)
        await interne(wrapper).$nextTick()

        const monter = wrapper.findAll('button[aria-label^="Monter"]')
        const descendre = wrapper.findAll('button[aria-label^="Descendre"]')

        expect(monter.map((b) => b.attributes('disabled') !== undefined)).toEqual([true, false, false])
        expect(descendre.map((b) => b.attributes('disabled') !== undefined)).toEqual([false, false, true])
    })
})
