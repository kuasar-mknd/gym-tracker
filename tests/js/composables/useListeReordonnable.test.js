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

    it('prévient au démarrage du glissement', async () => {
        const wrapper = monter()

        wrapper.vm.outils.rafraichir()
        await vi.waitFor(() => expect(configurations).toHaveLength(1))

        configurations[0].onDragstart({})

        expect(wrapper.vm.rappels.auDebut).toHaveBeenCalled()
    })

    /**
     * Une carte pleine dépasse souvent l'écran : sans défilement automatique
     * aux bords, on ne peut pas la sortir de la zone visible, donc pas la
     * déplacer loin. Et l'appui long est ce qui laisse le défilement de la page
     * au doigt qui ne fait que passer.
     */
    it('s’engage sans attendre, et habille la carte portée', async () => {
        const wrapper = monter()

        wrapper.vm.outils.rafraichir()
        await vi.waitFor(() => expect(configurations).toHaveLength(1))

        const config = configurations[0]

        expect(config.dragHandle).toBe('[data-poignee]')
        // Une poignée dédiée porte `touch-action: none` : le contact ne peut
        // pas être autre chose, donc rien à distinguer et rien à attendre.
        expect(config.longPress).toBe(false)

        expect(config.draggingClass).toBe('rangee-en-vol')
        expect(config.synthDraggingClass).toBe('rangee-en-vol')
        expect(config.dropZoneClass).toBe('rangee-creux')
    })

    /**
     * La bibliothèque pose `draggable` sur la rangée pour servir la souris de
     * bureau. Au doigt, iOS s'en saisissait après une demi-seconde pour
     * fabriquer SON aperçu — une vignette rétrécie à pastille verte — qui volait
     * le geste. Le type du pointeur tranche, et lui seul : une media query
     * laisserait passer un iPad au trackpad, qui se déclare pointeur fin.
     */
    it('coupe le glisser natif quand le geste vient du doigt', async () => {
        const wrapper = monter()

        wrapper.vm.outils.rafraichir()
        await vi.waitFor(() => expect(configurations).toHaveLength(1))

        const poignee = wrapper.find('[data-poignee]').element

        const pointer = (type) => {
            const evenement = new Event('pointerdown', { bubbles: true })

            evenement.pointerType = type
            poignee.dispatchEvent(evenement)
        }

        const dragstart = () => {
            const evenement = new Event('dragstart', { bubbles: true, cancelable: true })

            poignee.dispatchEvent(evenement)

            return evenement.defaultPrevented
        }

        pointer('touch')
        expect(dragstart()).toBe(true)

        pointer('pen')
        expect(dragstart()).toBe(true)

        // La souris de bureau, elle, n'a QUE le chemin natif.
        pointer('mouse')
        expect(dragstart()).toBe(false)
    })

    /**
     * Une rangée entière n'a PAS de `touch-action: none` : elle doit encore
     * pouvoir glisser latéralement pour se supprimer. La bibliothèque saisit au
     * premier mouvement sans regarder la direction, donc le temps est le seul
     * arbitre — maintenir déplace, glisser tout de suite supprime.
     */
    it('attend un appui maintenu quand la liste le demande', async () => {
        const wrapper = monter({ appuiLong: true })

        wrapper.vm.outils.rafraichir()
        await vi.waitFor(() => expect(configurations).toHaveLength(1))

        expect(configurations[0].longPress).toBe(true)
        expect(configurations[0].longPressDuration).toBe(220)
        expect(configurations[0].longPressClass).toBe('rangee-armee')
    })
})
