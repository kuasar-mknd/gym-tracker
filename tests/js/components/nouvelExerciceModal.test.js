import { describe, it, expect, vi, beforeAll, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'

const haptics = vi.hoisted(() => ({ triggerHaptic: vi.fn() }))
vi.mock('@/composables/useHaptics', () => haptics)

let form

vi.mock('@inertiajs/vue3', async () => {
    const { reactive } = await vi.importActual('vue')

    return {
        useForm: (data) => {
            form = reactive({
                ...data,
                processing: false,
                errors: {},
                post: vi.fn(),
                reset: vi.fn(function reset() {
                    Object.assign(this, { ...data })
                }),
            })

            return form
        },
    }
})

import NouvelExerciceModal from '@/Components/Exercises/NouvelExerciceModal.vue'

beforeAll(() => {
    globalThis.route = (nom) => `/${nom}`
})

beforeEach(() => {
    vi.clearAllMocks()
})

const monter = (props = {}) =>
    mount(NouvelExerciceModal, {
        props: { show: true, ...props },
        global: {
            stubs: {
                Modal: { name: 'Modal', props: ['show'], template: '<div v-if="show" class="modal"><slot /></div>' },
            },
        },
    })

describe('NouvelExerciceModal', () => {
    it('reste fermée tant qu’on ne le lui demande pas', () => {
        expect(monter({ show: false }).find('.modal').exists()).toBe(false)
        expect(monter().find('.modal').exists()).toBe(true)
    })

    it('part sur un exercice de force sans catégorie, et écrit chaque case dans son champ', async () => {
        const wrapper = monter()

        expect(form.type).toBe('strength')
        expect(form.category).toBe('')

        await wrapper.get('[dusk="exercise-name-input"]').setValue('Rowing barre')
        const [type, categorie] = wrapper.findAll('select')
        await type.setValue('timed')
        await categorie.setValue('Dos')

        expect(form.name).toBe('Rowing barre')
        expect(form.type).toBe('timed')
        expect(form.category).toBe('Dos')
    })

    it('crée l’exercice, puis se vide, demande à se fermer et vibre en succès', async () => {
        const wrapper = monter()

        await wrapper.get('form').trigger('submit')

        expect(form.post).toHaveBeenCalledWith('/exercises.store', expect.any(Object))
        expect(wrapper.emitted('close')).toBeUndefined()

        form.post.mock.calls[0][1].onSuccess()

        expect(form.reset).toHaveBeenCalled()
        expect(wrapper.emitted('close')).toHaveLength(1)
        expect(haptics.triggerHaptic).toHaveBeenCalledWith('success')
    })

    it('reste ouverte et vibre autrement quand le serveur refuse', async () => {
        const wrapper = monter()

        await wrapper.get('form').trigger('submit')
        form.post.mock.calls[0][1].onError()

        expect(wrapper.emitted('close')).toBeUndefined()
        expect(form.reset).not.toHaveBeenCalled()
        expect(haptics.triggerHaptic).toHaveBeenCalledWith('error')
        expect(haptics.triggerHaptic).not.toHaveBeenCalledWith('success')
    })

    it('relaie la fermeture demandée par la modale', async () => {
        const wrapper = monter()

        await wrapper.findComponent({ name: 'Modal' }).vm.$emit('close')

        expect(wrapper.emitted('close')).toHaveLength(1)
    })
})
