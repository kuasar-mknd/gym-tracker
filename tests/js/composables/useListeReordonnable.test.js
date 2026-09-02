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
            return h('div', { ref: (el) => (this.conteneur.value = el) }, [
                // Le conteneur réel en porte : la carte « Séance vide » avant,
                // et le bloc de boutons après.
                h('div', { key: 'entete' }, 'entête'),
                ...['a', 'b', 'c'].map((nom) => h('div', { key: nom, 'data-element': '' }, nom)),
                h('div', { key: 'pied' }, 'pied'),
            ])
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
        const [, a, b, c] = [...conteneur.children]

        const deplacables = () => [...conteneur.querySelectorAll('[data-element]')]

        // Ce que SortableJS vient de faire au DOM : « a » descendu en 3e place.
        conteneur.append(a)
        expect(deplacables()).toEqual([b, c, a])

        /*
         * `oldIndex` compte les DÉPLAÇABLES ; le conteneur porte en plus un
         * en-tête et un pied. Indexer l'un par l'autre range la ligne un rang à
         * côté — le défaut que ce témoin ferme.
         */
        instances[0].options.onEnd({ oldIndex: 0, newIndex: 2, item: a, from: conteneur })

        // Le DOM est rendu à Vue, INTACT : c'est le tableau qui décide, et le
        // rappel qui le mute.
        expect(deplacables()).toEqual([a, b, c])
        expect(wrapper.vm.rappels.aLaFin).toHaveBeenCalledWith(0, 2)
    })

    it('remet aussi le nœud quand il remonte', async () => {
        const wrapper = monter()

        wrapper.vm.outils.rafraichir()
        await vi.waitFor(() => expect(instances).toHaveLength(1))

        const conteneur = wrapper.vm.conteneur.value
        const [, a, b, c] = [...conteneur.children]

        const deplacables = () => [...conteneur.querySelectorAll('[data-element]')]

        a.before(c)
        expect(deplacables()).toEqual([c, a, b])

        instances[0].options.onEnd({ oldIndex: 2, newIndex: 0, item: c, from: conteneur })

        expect(deplacables()).toEqual([a, b, c])
    })

    it('ne dit rien quand rien n’a bougé', async () => {
        const wrapper = monter()

        wrapper.vm.outils.rafraichir()
        await vi.waitFor(() => expect(instances).toHaveLength(1))

        instances[0].options.onEnd({ oldIndex: 1, newIndex: 1, item: null, from: null })

        expect(wrapper.vm.rappels.aLaFin).not.toHaveBeenCalled()
    })

    /**
     * Le drapeau de repli est SUPPRIMÉ : replier pendant le geste laissait à
     * l'écran un clone plein — SortableJS le photographie au démarrage —
     * au-dessus d'une liste qui venait de s'effondrer.
     */
    it('n’expose plus de drapeau de déplacement', async () => {
        const wrapper = monter()

        expect(wrapper.vm.outils.deplacementEnCours).toBeUndefined()
        expect(Object.keys(wrapper.vm.outils).sort()).toEqual(['detacher', 'rafraichir'])

        wrapper.vm.outils.rafraichir()
        await vi.waitFor(() => expect(instances).toHaveLength(1))

        instances[0].options.onStart()

        expect(wrapper.vm.rappels.auDebut).toHaveBeenCalled()
    })

    /**
     * Le clone est un `cloneNode(true)` que SortableJS habille de styles EN
     * LIGNE. Sans `fallbackClass`, il reste par-dessus un original intact —
     * deux fois la même rangée, titre sur titre. Et sans `ghostClass`, corriger
     * le clone ne retire pas le doublon.
     */
    it('habille le clone ET le creux qu’il laisse', async () => {
        const wrapper = monter()

        wrapper.vm.outils.rafraichir()
        await vi.waitFor(() => expect(instances).toHaveLength(1))

        const { options } = instances[0]

        expect(options.fallbackClass).toBe('rangee-en-vol')
        expect(options.dragClass).toBe('rangee-en-vol')
        expect(options.ghostClass).toBe('rangee-creux')
        expect(options.forceFallback).toBe(true)

        // Le délai protégeait un défilement que `touch-none` interdit déjà.
        expect(options.delay ?? 0).toBe(0)
        expect(options.chosenClass).toBeUndefined()

        // Sur iOS le clone est en `position: absolute` ; `fallbackOnBody`
        // changerait son repère de coordonnées.
        expect(options.fallbackOnBody).toBeUndefined()
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
