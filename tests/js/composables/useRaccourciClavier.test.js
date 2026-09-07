import { describe, it, expect, vi, afterEach } from 'vitest'
import { mount } from '@vue/test-utils'
import { avecCommande, useRaccourciClavier } from '@/composables/useRaccourciClavier'

const montages = []

const monter = (correspond, action) => {
    const wrapper = mount({
        setup() {
            useRaccourciClavier(correspond, action)

            return () => null
        },
    })
    montages.push(wrapper)

    return wrapper
}

const frapper = (touche, options = {}) => {
    const evenement = new KeyboardEvent('keydown', { key: touche, cancelable: true, ...options })
    Object.defineProperty(evenement, 'target', { value: options.cible ?? document.body })
    window.dispatchEvent(evenement)

    return evenement
}

afterEach(() => {
    for (const wrapper of montages.splice(0)) {
        wrapper.unmount()
    }
})

describe('useRaccourciClavier', () => {
    it('déclenche l’action sur la combinaison attendue', () => {
        const action = vi.fn()
        monter((evenement) => evenement.key === '?', action)

        const evenement = frapper('?')

        expect(action).toHaveBeenCalledOnce()
        expect(evenement.defaultPrevented).toBe(true)
    })

    it('laisse passer tout le reste', () => {
        const action = vi.fn()
        monter((evenement) => evenement.key === '?', action)

        frapper('a')

        expect(action).not.toHaveBeenCalled()
    })

    /**
     * Un raccourci sans modificateur vole la frappe : `?` dans le nom d'un
     * exercice ouvrirait la page des raccourcis au lieu d'écrire le caractère.
     */
    it.each([['INPUT'], ['TEXTAREA'], ['SELECT']])('se tait pendant qu’on écrit dans un %s', (balise) => {
        const action = vi.fn()
        monter(() => true, action)

        frapper('?', { cible: document.createElement(balise) })

        expect(action).not.toHaveBeenCalled()
    })

    it('se tait aussi dans un bloc éditable', () => {
        const action = vi.fn()
        monter(() => true, action)

        const bloc = document.createElement('div')
        Object.defineProperty(bloc, 'isContentEditable', { value: true })

        frapper('?', { cible: bloc })

        expect(action).not.toHaveBeenCalled()
    })

    it('n’écoute plus une fois le composant démonté', () => {
        const action = vi.fn()
        const wrapper = monter(() => true, action)

        wrapper.unmount()
        frapper('?')

        expect(action).not.toHaveBeenCalled()
    })

    it('prend ⌘ comme Ctrl pour la touche de commande', () => {
        expect(avecCommande({ metaKey: true, ctrlKey: false })).toBe(true)
        expect(avecCommande({ metaKey: false, ctrlKey: true })).toBe(true)
        expect(avecCommande({ metaKey: false, ctrlKey: false })).toBe(false)
    })
})
