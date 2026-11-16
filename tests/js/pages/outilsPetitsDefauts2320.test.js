import { describe, it, expect, vi, beforeAll } from 'vitest'
import { mount } from '@vue/test-utils'

const pageDInertia = vi.hoisted(() => ({ props: {} }))
let formulaire

vi.mock('@inertiajs/vue3', async () => {
    const { reactive } = await vi.importActual('vue')

    return {
        Head: { template: '<div />' },
        router: { delete: vi.fn() },
        usePage: () => pageDInertia,
        useForm: (data) => {
            formulaire = reactive({ ...data, processing: false, errors: {}, post: vi.fn(), reset: vi.fn() })

            return formulaire
        },
    }
})

vi.mock('@/composables/useHaptics', () => ({ triggerHaptic: vi.fn() }))
vi.mock('@/Components/Stats/WaterHistoryChart.vue', () => ({
    __esModule: true,
    default: { name: 'WaterHistoryChart', props: ['data'], template: '<div />' },
}))

import PlateCalculator from '@/Pages/Tools/PlateCalculator.vue'
import WaterTracker from '@/Pages/Tools/WaterTracker.vue'

beforeAll(() => {
    globalThis.route = (name, params) => `/${name}/${JSON.stringify(params ?? '')}`
})

const passesSlot = { template: '<div><slot /></div>' }
const stubs = {
    AuthenticatedLayout: passesSlot,
    GlassCard: passesSlot,
    Modal: passesSlot,
    GlassButton: { template: '<button><slot /></button>' },
    GlassInput: {
        props: ['error', 'label', 'modelValue'],
        emits: ['update:modelValue'],
        template:
            '<div class="champ" :data-error="error">{{ label }}<input @input="$emit(\'update:modelValue\', $event.target.value)" /></div>',
    },
    ConfirmDialog: { props: ['description'], template: '<p class="dialogue">{{ description }}</p>' },
}

describe('WaterTracker — refus de validation', () => {
    it('affiche sous le champ personnalisé le refus du serveur (#2320)', async () => {
        const wrapper = mount(WaterTracker, {
            props: { logs: [], todayTotal: 0, history: [] },
            global: { mocks: { route: globalThis.route }, stubs },
        })

        const bouton = wrapper.get('[aria-label="Ajouter la quantité saisie"]')
        await wrapper
            .findAll('.champ')
            .find((c) => c.text() === 'Quantité personnalisée')
            .get('input')
            .setValue('5')
        await bouton.trigger('click')
        formulaire.post.mock.calls[0][1].onStart()
        formulaire.errors = { amount: 'Une quantité va de 1 à 5000 ml.' }
        await wrapper.vm.$nextTick()

        const champ = wrapper.findAll('.champ').find((c) => c.text() === 'Quantité personnalisée')

        expect(champ.attributes('data-error')).toBe('Une quantité va de 1 à 5000 ml.')
    })
})

describe('PlateCalculator — un disque de 1,25 kg', () => {
    const monter = () =>
        mount(PlateCalculator, {
            props: { plates: [{ id: 3, weight: '1.25', quantity: 2 }] },
            global: { mocks: { route: globalThis.route }, stubs },
        })

    it('s’écrit en nombre français dans l’inventaire (#2320)', () => {
        const wrapper = monter()

        expect(wrapper.text()).toContain('1,25')
        expect(wrapper.text()).not.toContain('1.25')
    })

    it('est nommé en français correct dans la confirmation', async () => {
        const wrapper = monter()

        await wrapper.get('[aria-label="Supprimer la plaque"]').trigger('click')

        expect(wrapper.get('.dialogue').text()).toBe('Le disque de 1,25 kg sera retiré.')
    })
})
