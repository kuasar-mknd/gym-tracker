import { describe, it, expect, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { ref } from 'vue'
import { useRaccourcisDeLaSeance } from '@/composables/useRaccourcisDeLaSeance'

const monter = (seance, terminee, addSet) =>
    mount({
        setup() {
            useRaccourcisDeLaSeance({ localWorkout: ref(seance), isFinished: ref(terminee), addSet })

            return () => null
        },
    })

const commande = () => {
    const evenement = new KeyboardEvent('keydown', { key: 'Enter', metaKey: true, cancelable: true })
    Object.defineProperty(evenement, 'target', { value: document.body })
    window.dispatchEvent(evenement)
}

const seance = { workout_lines: [{ id: 7 }, { id: 12 }] }

describe('useRaccourcisDeLaSeance', () => {
    it('ajoute une série au dernier exercice, celui qu’on est en train de faire', () => {
        const addSet = vi.fn()
        const wrapper = monter(seance, false, addSet)

        commande()

        expect(addSet).toHaveBeenCalledWith(12)
        wrapper.unmount()
    })

    it('ne touche pas à une séance terminée', () => {
        const addSet = vi.fn()
        const wrapper = monter(seance, true, addSet)

        commande()

        expect(addSet).not.toHaveBeenCalled()
        wrapper.unmount()
    })

    it('ne fait rien sur une séance sans exercice', () => {
        const addSet = vi.fn()
        const wrapper = monter({ workout_lines: [] }, false, addSet)

        commande()

        expect(addSet).not.toHaveBeenCalled()
        wrapper.unmount()
    })
})
