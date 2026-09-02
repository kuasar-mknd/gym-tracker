import { describe, it, expect, vi, beforeEach } from 'vitest'
import { defineComponent, h, nextTick } from 'vue'
import { mount } from '@vue/test-utils'

/**
 * SortableJS déplace le nœud du DOM lui-même. Vue, lui, rend depuis le tableau.
 * Si le nœud reste où la bibliothèque l'a mis ET que le tableau bouge aussi, le
 * déplacement est appliqué deux fois — l'exercice saute deux rangs.
 *
 * C'est le seul endroit où ce piège peut être éprouvé : jsdom n'a pas de
 * pointeur, donc on remplace la bibliothèque et on déclenche ses rappels à la
 * main.
 */
const instances = []

vi.mock('sortablejs', () => ({
    default: class {
        constructor(element, options) {
            this.element = element
            this.options = options
            this.destroyed = false
            instances.push(this)
        }

        destroy() {
            this.destroyed = true
        }
    },
}))

import { useListeReordonnable } from '@/composables/useListeReordonnable'

const monter = (options = {}) => {
    const rappels = { auDebut: vi.fn(), aLaFin: vi.fn(), estActif: () => true, ...options }

    const composant = defineComponent({
        setup() {
            const conteneur = { value: null }
            const outils = useListeReordonnable(() => conteneur.value, {
                handle: '[data-poignee]',
                draggable: '[data-element]',
                ...rappels,
            })

            return { conteneur, outils, rappels }
        },
        render() {
            return h(
                'div',
                { ref: (el) => (this.conteneur.value = el) },
                ['a', 'b', 'c'].map((nom) => h('div', { key: nom, 'data-element': '' }, nom)),
            )
        },
    })

    return mount(composant)
}

beforeEach(() => {
    instances.length = 0
})

describe('une liste réordonnable', () => {
    it('ne s’attache pas quand la liste n’est pas déplaçable', async () => {
        const wrapper = monter({ estActif: () => false })

        wrapper.vm.outils.rafraichir()
        await nextTick()

        expect(instances).toHaveLength(0)
    })

    it('n’attache la bibliothèque qu’une fois', async () => {
        const wrapper = monter()

        wrapper.vm.outils.rafraichir()
        await vi.waitFor(() => expect(instances).toHaveLength(1))

        wrapper.vm.outils.rafraichir()
        await nextTick()

        expect(instances).toHaveLength(1)
    })

    it('rend le nœud à sa place avant d’annoncer le déplacement', async () => {
        const wrapper = monter()

        wrapper.vm.outils.rafraichir()
        await vi.waitFor(() => expect(instances).toHaveLength(1))

        const conteneur = wrapper.vm.conteneur.value
        const [a, b, c] = [...conteneur.children]

        // Ce que SortableJS vient de faire au DOM : « a » descendu en 3e place.
        conteneur.append(a)
        expect([...conteneur.children]).toEqual([b, c, a])

        instances[0].options.onEnd({ oldIndex: 0, newIndex: 2, item: a, from: conteneur })

        // Le DOM est rendu à Vue, INTACT : c'est le tableau qui décide, et le
        // rappel qui le mute.
        expect([...conteneur.children]).toEqual([a, b, c])
        expect(wrapper.vm.rappels.aLaFin).toHaveBeenCalledWith(0, 2)
    })

    it('remet aussi le nœud quand il remonte', async () => {
        const wrapper = monter()

        wrapper.vm.outils.rafraichir()
        await vi.waitFor(() => expect(instances).toHaveLength(1))

        const conteneur = wrapper.vm.conteneur.value
        const [a, b, c] = [...conteneur.children]

        conteneur.prepend(c)
        expect([...conteneur.children]).toEqual([c, a, b])

        instances[0].options.onEnd({ oldIndex: 2, newIndex: 0, item: c, from: conteneur })

        expect([...conteneur.children]).toEqual([a, b, c])
    })

    it('ne dit rien quand rien n’a bougé', async () => {
        const wrapper = monter()

        wrapper.vm.outils.rafraichir()
        await vi.waitFor(() => expect(instances).toHaveLength(1))

        instances[0].options.onEnd({ oldIndex: 1, newIndex: 1, item: null, from: null })

        expect(wrapper.vm.rappels.aLaFin).not.toHaveBeenCalled()
    })

    it('signale le déplacement en cours, puis sa fin', async () => {
        const wrapper = monter()

        wrapper.vm.outils.rafraichir()
        await vi.waitFor(() => expect(instances).toHaveLength(1))

        expect(wrapper.vm.outils.deplacementEnCours.value).toBe(false)

        instances[0].options.onStart()
        expect(wrapper.vm.outils.deplacementEnCours.value).toBe(true)
        expect(wrapper.vm.rappels.auDebut).toHaveBeenCalled()

        const conteneur = wrapper.vm.conteneur.value
        instances[0].options.onEnd({ oldIndex: 1, newIndex: 1, item: null, from: conteneur })
        expect(wrapper.vm.outils.deplacementEnCours.value).toBe(false)
    })

    it('se détache quand la liste cesse d’être déplaçable', async () => {
        let actif = true
        const wrapper = monter({ estActif: () => actif })

        wrapper.vm.outils.rafraichir()
        await vi.waitFor(() => expect(instances).toHaveLength(1))

        actif = false
        wrapper.vm.outils.rafraichir()

        expect(instances[0].destroyed).toBe(true)
    })
})
