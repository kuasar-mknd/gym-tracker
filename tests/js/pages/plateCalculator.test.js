import { describe, it, expect, vi, beforeAll } from 'vitest'
import { mount } from '@vue/test-utils'

const routerDelete = vi.fn()

vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<div />' },
    router: { delete: (...args) => routerDelete(...args) },
    useForm: (data) => ({ ...data, processing: false, errors: {}, post: vi.fn(), reset: vi.fn() }),
}))

import PlateCalculator from '@/Pages/Tools/PlateCalculator.vue'

/**
 * La question passe par un dialogue de l'application, plus par `confirm()`.
 *
 * Sur mobile, plusieurs navigateurs suppriment la boîte native après quelques
 * appels : le geste s'exécutait alors SANS question. Une confirmation qui peut
 * disparaître n'en est pas une.
 */
const dialogue = (wrapper) => wrapper.findComponent({ name: 'ConfirmDialog' })

const confirmer = async (wrapper) => {
    await dialogue(wrapper).vm.$emit('confirmer')
}

beforeAll(() => {
    globalThis.route = (name, params) => `/${name}/${JSON.stringify(params ?? '')}`
})

const passesSlot = { template: '<div><slot /></div>' }

const mountCalculator = (plates = []) =>
    mount(PlateCalculator, {
        props: { plates },
        global: {
            mocks: { route: globalThis.route },
            stubs: {
                AuthenticatedLayout: passesSlot,
                GlassCard: passesSlot,
                Modal: passesSlot,
                GlassButton: { template: '<button><slot /></button>' },
                GlassInput: { template: '<input />' },
            },
        },
    })

/** Sets the two number inputs, which is the only way in to the computation. */
const setWeights = async (wrapper, target, bar) => {
    await wrapper.find('#plate-target-weight').setValue(target)
    await wrapper.find('#plate-bar-weight').setValue(bar)
}

/** The plate discs drawn on the bar, both sides, in DOM order. */
const drawnPlates = (wrapper) =>
    wrapper.findAll('[style*="width: 24px"]').map((disc) => ({
        weight: disc.text(),
        height: parseFloat(disc.attributes('style').match(/height:\s*([\d.]+)px/)[1]),
    }))

const fullInventory = [
    { id: 1, weight: '20', quantity: 4 },
    { id: 2, weight: '10', quantity: 2 },
    { id: 3, weight: '2.5', quantity: 4 },
]

/**
 * The greedy selection itself lives in Utils/plates and is tested there. What
 * was never covered is the page around it: that both sides of the bar are drawn,
 * that the total shown is the weight actually loaded rather than the one asked
 * for, and that an unreachable weight says so.
 */
describe('PlateCalculator loading', () => {
    it('draws the same plates on both sides of the bar', async () => {
        const wrapper = mountCalculator(fullInventory)
        await setWeights(wrapper, 100, 20)

        // 80 kg of plates, 40 per side: two 20s.
        expect(drawnPlates(wrapper).map((p) => p.weight)).toEqual(['20', '20', '20', '20'])
        expect(wrapper.text()).toContain('20kg + 20kg')
    })

    it('reports the weight actually loaded, not the one that was asked for', async () => {
        // Only one usable pair of 20s: 100 kg is out of reach, 60 is what the
        // bar will weigh, and that is the figure the lifter needs.
        const wrapper = mountCalculator([{ id: 1, weight: '20', quantity: 3 }])
        await setWeights(wrapper, 100, 20)

        expect(wrapper.text()).toContain('60 kg')
        expect(wrapper.text()).not.toContain('100 kg')
    })

    it('handles half-plates without floating-point residue', async () => {
        const wrapper = mountCalculator(fullInventory)
        await setWeights(wrapper, 65, 20)

        // 22.5 per side: 20 + 2.5, and the total lands exactly on 65.
        expect(drawnPlates(wrapper).map((p) => p.weight)).toEqual(['20', '2.5', '20', '2.5'])
        expect(wrapper.text()).toContain('65 kg')
    })

    it('says the weight cannot be loaded when no pair fits', async () => {
        const wrapper = mountCalculator([{ id: 1, weight: '20', quantity: 1 }])
        await setWeights(wrapper, 60, 20)

        expect(drawnPlates(wrapper)).toHaveLength(0)
        expect(wrapper.text()).toContain('Impossible de charger ce poids avec les plaques disponibles.')
    })

    it('draws heavier plates larger than lighter ones', async () => {
        const wrapper = mountCalculator([
            { id: 1, weight: '25', quantity: 2 },
            { id: 2, weight: '20', quantity: 2 },
            { id: 3, weight: '10', quantity: 2 },
        ])
        await setWeights(wrapper, 130, 20)

        const [heaviest, middle, lightest] = drawnPlates(wrapper)
        expect(heaviest.weight).toBe('25')
        expect(heaviest.height).toBeGreaterThan(middle.height)
        expect(middle.height).toBeGreaterThan(lightest.height)
    })
})

/**
 * The colour code is the one printed on competition plates — a lifter reads the
 * rack by colour before reading the number.
 */
describe('PlateCalculator inventory', () => {
    it('colours each plate with its competition colour', () => {
        const wrapper = mountCalculator([
            { id: 1, weight: '25', quantity: 2 },
            { id: 2, weight: '20', quantity: 2 },
            { id: 3, weight: '15', quantity: 2 },
            { id: 4, weight: '10', quantity: 2 },
            { id: 5, weight: '5', quantity: 2 },
        ])

        const tiles = wrapper.findAll('.grid > .group > div')
        const colourOf = (weight) =>
            tiles
                .find((tile) => tile.text().startsWith(weight))
                .classes()
                .find((c) => c.startsWith('plate-fill-'))

        expect(colourOf('25')).toBe('plate-fill-25')
        expect(colourOf('20')).toBe('plate-fill-20')
        expect(colourOf('15')).toBe('plate-fill-15')
        expect(colourOf('10')).toBe('plate-fill-10')
        expect(colourOf('5')).toBe('plate-fill-5')
    })

    it('shows how many of each plate are owned', () => {
        const wrapper = mountCalculator([{ id: 1, weight: '20', quantity: 4 }])

        expect(wrapper.text()).toContain('x 4')
    })

    it('says the inventory is empty rather than showing a bare grid', () => {
        const wrapper = mountCalculator([])

        expect(wrapper.text()).toContain("Aucune plaque dans l'inventaire.")
    })

    /** Two plates, and the second one's cross: with one, any id would pass. */
    it('supprime le disque dont la croix a été cliquée, et seulement après confirmation', async () => {
        routerDelete.mockReset()

        const wrapper = mountCalculator([
            { id: 3, weight: '20', quantity: 4 },
            { id: 7, weight: '10', quantity: 2 },
        ])

        await wrapper.findAll('[aria-label="Supprimer la plaque"]')[1].trigger('click')

        expect(dialogue(wrapper).props('ouvert')).toBe(true)
        expect(routerDelete).not.toHaveBeenCalled()
        // La question nomme le poids : la boîte native ne le pouvait pas.
        expect(dialogue(wrapper).props('description')).toContain('10')

        await confirmer(wrapper)

        expect(routerDelete).toHaveBeenCalledTimes(1)
        expect(routerDelete.mock.calls[0][0]).toContain('plates.destroy')
        // L'identifiant, plus l'objet entier serialise : la route n'a besoin
        // que de la cle, et lui passer le disque complet faisait voyager son
        // poids et sa quantite dans l'URL.
        expect(routerDelete.mock.calls[0][0]).toContain('"plate":7')
    })
})
