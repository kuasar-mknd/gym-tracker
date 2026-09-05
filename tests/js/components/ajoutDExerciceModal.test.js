import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'

const haptics = vi.hoisted(() => ({ triggerHaptic: vi.fn() }))
vi.mock('@/composables/useHaptics', () => haptics)

vi.mock('@inertiajs/vue3', () => ({
    useForm: (fields) => {
        const form = { ...fields, errors: {}, processing: false }
        form.clearErrors = () => {
            form.errors = {}
        }
        form.setError = (field, message) => {
            form.errors = { ...form.errors, [field]: message }
        }
        return form
    },
}))

import AjoutDExerciceModal from '@/Components/Workout/AjoutDExerciceModal.vue'

const bibliotheque = [
    { id: 1, name: 'Développé couché', category: 'Pectoraux' },
    { id: 2, name: 'Squat', category: 'Jambes' },
]

const monter = (props = {}) =>
    mount(AjoutDExerciceModal, {
        props: { show: true, exercises: bibliotheque, ...props },
        global: { stubs: { Modal: { template: '<div><slot /></div>', props: ['show'] } } },
        attachTo: document.body,
    })

beforeEach(() => {
    localStorage.clear()
    globalThis.route = (nom) => `/${nom}`
    document.body.innerHTML = '<meta name="csrf-token" content="jeton" />'
})

afterEach(() => {
    vi.restoreAllMocks()
    vi.clearAllMocks()
})

describe('choisir un exercice', () => {
    it('filtre la bibliothèque sur la recherche, et se souvient de la recherche', async () => {
        const wrapper = monter()

        await wrapper.get('#search-workout-exercise').setValue('squ')

        expect(wrapper.findAll('[dusk^="select-exercise-"]').map((b) => b.text())).toEqual(['SquatJambes'])
        expect(localStorage.getItem('gymtracker_add_exercise_search')).toBe('squ')
        wrapper.unmount()
    })

    it('rend l’exercice choisi au parent et oublie la recherche', async () => {
        const wrapper = monter()
        await wrapper.get('#search-workout-exercise').setValue('dév')

        await wrapper.get('[dusk="select-exercise-1"]').trigger('click')

        expect(wrapper.emitted('add')).toEqual([[1]])
        expect(wrapper.vm.searchQuery).toBe('')
        wrapper.unmount()
    })

    it('propose de créer ce que la recherche ne trouve pas, avec ce nom pré-rempli', async () => {
        const wrapper = monter()
        await wrapper.get('#search-workout-exercise').setValue('Rowing')

        await wrapper.get('[dusk="quick-create-exercise"]').trigger('click')

        expect(wrapper.vm.showCreateForm).toBe(true)
        expect(wrapper.vm.createExerciseForm.name).toBe('Rowing')
        expect(wrapper.find('[dusk="new-exercise-name"]').exists()).toBe(true)
        wrapper.unmount()
    })

    it('se ferme en rendant sa recherche et son formulaire', async () => {
        const wrapper = monter()
        await wrapper.get('#search-workout-exercise').setValue('x')
        wrapper.vm.quickCreate()

        wrapper.vm.fermer()
        await wrapper.vm.$nextTick()

        expect(wrapper.emitted('close')).toHaveLength(1)
        expect(wrapper.vm.searchQuery).toBe('')
        expect(wrapper.vm.showCreateForm).toBe(false)
        wrapper.unmount()
    })
})

describe('créer un exercice sur le champ', () => {
    it('envoie le formulaire, rend l’exercice créé puis le choisit', async () => {
        vi.spyOn(globalThis, 'fetch').mockResolvedValue({
            ok: true,
            json: async () => ({ exercise: { id: 9, name: 'Rowing', category: 'Dos' } }),
        })
        const wrapper = monter()
        wrapper.vm.createExerciseForm.name = 'Rowing'

        await wrapper.vm.createAndAddExercise()
        await flushPromises()

        expect(globalThis.fetch).toHaveBeenCalledWith(
            '/exercises.store',
            expect.objectContaining({ method: 'POST', headers: expect.objectContaining({ 'X-CSRF-TOKEN': 'jeton' }) }),
        )
        expect(wrapper.emitted('created')).toEqual([[{ id: 9, name: 'Rowing', category: 'Dos' }]])
        expect(wrapper.emitted('add')).toEqual([[9]])
        expect(wrapper.vm.showCreateForm).toBe(false)
        expect(wrapper.vm.createExerciseForm.processing).toBe(false)
        wrapper.unmount()
    })

    it('envoie le type et la catégorie choisis, pas les valeurs par défaut', async () => {
        const spy = vi.spyOn(globalThis, 'fetch').mockResolvedValue({
            ok: true,
            json: async () => ({ exercise: { id: 9, name: 'Rowing Haltère', category: 'Dos' } }),
        })
        const wrapper = monter()
        wrapper.vm.createExerciseForm.name = 'Rowing Haltère'
        wrapper.vm.createExerciseForm.type = 'cardio'
        wrapper.vm.createExerciseForm.category = 'Dos'

        await wrapper.vm.createAndAddExercise()

        expect(JSON.parse(spy.mock.calls[0][1].body)).toEqual({
            name: 'Rowing Haltère',
            type: 'cardio',
            category: 'Dos',
        })
        wrapper.unmount()
    })

    it('ne se fige pas sur un refus qui ne nomme aucun champ', async () => {
        vi.spyOn(globalThis, 'fetch').mockResolvedValue({
            ok: false,
            status: 422,
            json: async () => ({ message: 'Requête invalide.' }),
        })
        const wrapper = monter()

        await wrapper.vm.createAndAddExercise()

        expect(wrapper.vm.createExerciseForm.errors).toEqual({})
        expect(wrapper.vm.createExerciseForm.processing).toBe(false)
        expect(wrapper.emitted('created')).toBeUndefined()
        wrapper.unmount()
    })

    it('affiche les erreurs de validation du serveur au lieu de se taire', async () => {
        vi.spyOn(globalThis, 'fetch').mockResolvedValue({
            ok: false,
            status: 422,
            json: async () => ({ errors: { name: ['Ce nom existe déjà.', 'autre'] } }),
        })
        const wrapper = monter()

        await wrapper.vm.createAndAddExercise()

        expect(wrapper.vm.createExerciseForm.errors.name).toBe('Ce nom existe déjà.')
        expect(wrapper.emitted('created')).toBeUndefined()
        expect(haptics.triggerHaptic).toHaveBeenCalledWith('error')
        wrapper.unmount()
    })

    it('dit qu’un autre échec du serveur est passager, et qu’une panne réseau est une panne réseau', async () => {
        const spy = vi.spyOn(globalThis, 'fetch').mockResolvedValue({ ok: false, status: 500, json: async () => ({}) })
        vi.spyOn(console, 'error').mockImplementation(() => {})
        const wrapper = monter()

        await wrapper.vm.createAndAddExercise()
        expect(wrapper.vm.createExerciseForm.errors.name).toBe('La création a échoué. Réessaie dans un instant.')

        spy.mockRejectedValue(new Error('hors ligne'))
        await wrapper.vm.createAndAddExercise()
        expect(wrapper.vm.createExerciseForm.errors.name).toBe('Connexion impossible. Vérifie ta connexion réseau.')
        expect(wrapper.vm.createExerciseForm.processing).toBe(false)
        wrapper.unmount()
    })
})
