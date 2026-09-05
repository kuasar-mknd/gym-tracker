import { describe, it, expect, vi, beforeAll } from 'vitest'
import { mount } from '@vue/test-utils'

let form

/*
 * Réactif à dessein : le titre, l'état pressé des pastilles et le bouton
 * d'envoi lisent le formulaire ; un objet nu figerait le premier rendu.
 */
vi.mock('@inertiajs/vue3', async () => {
    const { reactive } = await vi.importActual('vue')

    return {
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

import FormulaireDHabitude from '@/Components/Habits/FormulaireDHabitude.vue'

const habitude = {
    id: 7,
    name: 'Lecture',
    description: null,
    color: 'bg-palette-vert',
    icon: 'book',
    goal_times_per_week: 3,
}

beforeAll(() => {
    globalThis.route = (nom, id) => `/${nom}/${id ?? ''}`
})

const monter = (props = {}) =>
    mount(FormulaireDHabitude, {
        props: { show: true, ...props },
        global: {
            stubs: { Modal: { props: ['show'], template: '<div v-if="show"><slot /></div>' } },
        },
    })

const bouton = (wrapper, libelle) =>
    wrapper.findAll('button').find((b) => b.text() === libelle || b.attributes('aria-label') === libelle)

describe('FormulaireDHabitude', () => {
    it('se remplit de l’habitude tendue à l’ouverture, une description absente devenant un champ vide', async () => {
        const wrapper = monter({ show: false, habitude })

        await wrapper.setProps({ show: true })

        expect(wrapper.get('h3').text()).toBe('Modifier')
        expect(form.name).toBe('Lecture')
        expect(form.description).toBe('')
        expect(form.color).toBe('bg-palette-vert')
        expect(form.icon).toBe('book')
        expect(form.goal_times_per_week).toBe(3)
    })

    it('repart à vide quand on l’ouvre sans habitude après en avoir modifié une', async () => {
        const wrapper = monter({ show: false, habitude })
        await wrapper.setProps({ show: true })
        await wrapper.setProps({ show: false })

        await wrapper.setProps({ habitude: null })
        await wrapper.setProps({ show: true })

        expect(wrapper.get('h3').text()).toBe('Nouvelle Habitude')
        expect(form.reset).toHaveBeenCalled()
        expect(form.name).toBe('')
        expect(form.color).toBe('bg-palette-ardoise')
    })

    it('choisit couleur et icône au bouton, chacun nommé pour un lecteur d’écran', async () => {
        const wrapper = monter()

        await wrapper.get('[dusk="habit-color-bg-palette-vert"]').trigger('click')
        await wrapper.get('[dusk="habit-icon-book"]').trigger('click')

        expect(form.color).toBe('bg-palette-vert')
        expect(form.icon).toBe('book')
        expect(wrapper.get('[dusk="habit-color-bg-palette-vert"]').attributes('aria-label')).toBe('Vert')
        expect(wrapper.get('[dusk="habit-color-bg-palette-vert"]').attributes('aria-pressed')).toBe('true')
        expect(wrapper.get('[dusk="habit-color-bg-palette-rouge"]').attributes('aria-pressed')).toBe('false')
        expect(wrapper.get('[dusk="habit-icon-book"]').attributes('aria-label')).toBe('Lecture')
    })

    it('met à jour l’habitude tendue, et demande à se fermer quand le serveur a dit oui', async () => {
        const wrapper = monter({ habitude })

        await wrapper.get('form').trigger('submit')

        expect(form.put).toHaveBeenCalledWith('/habits.update/7', expect.any(Object))
        expect(form.post).not.toHaveBeenCalled()
        expect(wrapper.emitted('close')).toBeUndefined()

        form.put.mock.calls[0][1].onSuccess()

        expect(wrapper.emitted('close')).toHaveLength(1)
    })

    it('crée sinon, puis se ferme et se vide', async () => {
        const wrapper = monter()
        form.name = 'Marche'

        await wrapper.get('form').trigger('submit')

        expect(form.post).toHaveBeenCalledWith('/habits.store/', expect.any(Object))
        expect(form.put).not.toHaveBeenCalled()

        form.post.mock.calls[0][1].onSuccess()

        expect(wrapper.emitted('close')).toHaveLength(1)
        expect(form.reset).toHaveBeenCalled()
    })

    it('se ferme par la croix comme par « Annuler », sans rien envoyer', async () => {
        const wrapper = monter({ habitude })

        await bouton(wrapper, 'Fermer le formulaire').trigger('click')
        await bouton(wrapper, 'Annuler').trigger('click')

        expect(wrapper.emitted('close')).toHaveLength(2)
        expect(form.put).not.toHaveBeenCalled()
        expect(form.post).not.toHaveBeenCalled()
    })
})
