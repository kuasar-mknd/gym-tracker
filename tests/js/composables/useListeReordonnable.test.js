import { describe, it, expect, vi, beforeEach } from 'vitest'
import { defineComponent, h, ref, nextTick } from 'vue'
import { mount } from '@vue/test-utils'

/**
 * jsdom n'a pas de pointeur : le glissement lui-même ne peut pas être joué ici.
 * Ce qui PEUT l'être, et qui a coûté deux allers-retours, c'est le moment du
 * repli — il doit partir du CONTACT, jamais du démarrage du glissement, parce
 * que la bibliothèque fabrique alors son nœud de substitution à partir de ce
 * qu'elle voit.
 */
const configurations = vi.hoisted(() => [])

vi.mock('@formkit/drag-and-drop/vue', () => ({
    dragAndDrop: (config) => configurations.push(config),
}))

import { useListeReordonnable } from '@/composables/useListeReordonnable'

const monter = (options = {}) => {
    const rappels = { auDebut: vi.fn(), aLaFin: vi.fn(), estActif: () => true, ...options }

    const composant = defineComponent({
        setup() {
            const conteneur = ref(null)
            const valeurs = ref(['a', 'b', 'c'])

            const outils = useListeReordonnable(conteneur, {
                valeurs,
                handle: '[data-poignee]',
                ...rappels,
            })

            return { conteneur, valeurs, outils, rappels }
        },
        render() {
            return h('div', { ref: 'conteneur' }, [
                h('div', { 'data-poignee': '' }, 'poignée'),
                h('div', {}, 'ailleurs'),
            ])
        },
    })

    return mount(composant, { attachTo: document.body })
}

const appuyer = (wrapper, selecteur) => {
    const cible = wrapper.find(selecteur).element
    cible.dispatchEvent(new Event('pointerdown', { bubbles: true }))
}

beforeEach(() => {
    configurations.length = 0
})

describe('une liste réordonnable', () => {
    it('ne s’attache pas quand la liste n’est pas déplaçable', async () => {
        const wrapper = monter({ estActif: () => false })

        wrapper.vm.outils.rafraichir()
        await nextTick()

        expect(configurations).toHaveLength(0)
    })

    it('n’attache la bibliothèque qu’une fois', async () => {
        const wrapper = monter()

        wrapper.vm.outils.rafraichir()
        await vi.waitFor(() => expect(configurations).toHaveLength(1))

        wrapper.vm.outils.rafraichir()
        await nextTick()

        expect(configurations).toHaveLength(1)
    })

    /**
     * Le cœur du correctif. Replier au démarrage du glissement laissait à
     * l'écran une carte pleine — séries comprises — au-dessus d'une liste qui
     * venait de s'effondrer : filmé sur simulateur, deux titres superposés et
     * la page raccourcie de 800 px.
     */
    it('replie dès l’appui sur la poignée, pas au démarrage du glissement', async () => {
        const wrapper = monter()

        wrapper.vm.outils.rafraichir()
        await vi.waitFor(() => expect(configurations).toHaveLength(1))

        expect(wrapper.vm.outils.cartesRepliees.value).toBe(false)

        appuyer(wrapper, '[data-poignee]')

        expect(wrapper.vm.outils.cartesRepliees.value).toBe(true)
        expect(wrapper.vm.rappels.auDebut).toHaveBeenCalled()
    })

    it('ne replie pas quand l’appui tombe à côté de la poignée', async () => {
        const wrapper = monter()

        wrapper.vm.outils.rafraichir()
        await vi.waitFor(() => expect(configurations).toHaveLength(1))

        appuyer(wrapper, 'div:not([data-poignee])')

        expect(wrapper.vm.outils.cartesRepliees.value).toBe(false)
    })

    /** Un appui relâché sans glisser doit rendre la carte à sa taille. */
    it('déplie quand on relâche sans avoir glissé', async () => {
        const wrapper = monter()

        wrapper.vm.outils.rafraichir()
        await vi.waitFor(() => expect(configurations).toHaveLength(1))

        appuyer(wrapper, '[data-poignee]')
        expect(wrapper.vm.outils.cartesRepliees.value).toBe(true)

        window.dispatchEvent(new Event('pointerup'))

        expect(wrapper.vm.outils.cartesRepliees.value).toBe(false)
    })

    it('reste replié tant que le glissement dure, puis déplie à la fin', async () => {
        const wrapper = monter()

        wrapper.vm.outils.rafraichir()
        await vi.waitFor(() => expect(configurations).toHaveLength(1))

        const { onDragstart, onDragend } = configurations[0]

        appuyer(wrapper, '[data-poignee]')
        onDragstart({})

        // Le doigt quitte l'écran pendant le glissement : la carte doit rester
        // repliée jusqu'à ce que la bibliothèque dise que c'est fini.
        window.dispatchEvent(new Event('pointerup'))
        expect(wrapper.vm.outils.cartesRepliees.value).toBe(true)

        onDragend({})
        expect(wrapper.vm.outils.cartesRepliees.value).toBe(false)
    })

    /**
     * La bibliothèque a DÉJÀ réordonné le tableau quand elle appelle `onSort` :
     * muter une seconde fois appliquerait le déplacement deux fois.
     */
    it('annonce le déplacement sans y toucher', async () => {
        const wrapper = monter()

        wrapper.vm.outils.rafraichir()
        await vi.waitFor(() => expect(configurations).toHaveLength(1))

        configurations[0].onSort({ previousPosition: 0, position: 2 })

        expect(wrapper.vm.rappels.aLaFin).toHaveBeenCalledWith(0, 2)
        expect(wrapper.vm.valeurs).toEqual(['a', 'b', 'c'])
    })

    it('demande un appui long, et habille la rangée portée', async () => {
        const wrapper = monter()

        wrapper.vm.outils.rafraichir()
        await vi.waitFor(() => expect(configurations).toHaveLength(1))

        const config = configurations[0]

        expect(config.dragHandle).toBe('[data-poignee]')

        // L'appui long laisse à Vue le temps de rendre le repli avant que le
        // geste ne commence.
        expect(config.longPress).toBe(true)
        expect(config.longPressDuration).toBeGreaterThanOrEqual(150)

        expect(config.draggingClass).toBe('rangee-en-vol')
        expect(config.synthDraggingClass).toBe('rangee-en-vol')
        expect(config.dropZoneClass).toBe('rangee-creux')
    })
})
