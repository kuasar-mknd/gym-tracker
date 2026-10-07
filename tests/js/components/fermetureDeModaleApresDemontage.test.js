import { describe, it, expect, vi, afterEach } from 'vitest'
import { mount } from '@vue/test-utils'

import Modal from '@/Components/UI/Modal.vue'

/**
 * La simulation de `<dialog>` de vitest.setup.js tient quand sa minuterie
 * tombe après le démontage de l'environnement.
 *
 * `Modal.vue` ferme son dialogue 200 ms après `show: false`. Une page testée
 * sans être démontée laisse cette minuterie courir ; si elle tombe pendant le
 * démontage, le global a déjà repris l'`Event` de Node, et un `close` construit
 * avec lui était refusé par le dialogue de jsdom (« parameter 1 is not of type
 * 'Event' ») : une erreur hors de tout test, qui fait échouer la suite. Le
 * démontage est simulé ici en remplaçant l'`Event` global par une classe d'un
 * autre domaine, que jsdom refuse de la même façon.
 */
const EventDeLEnvironnement = globalThis.Event

afterEach(() => {
    globalThis.Event = EventDeLEnvironnement
    vi.useRealTimers()
})

describe('Fermeture d’une modale après le démontage de l’environnement', () => {
    it('ferme le dialogue sans lever, même quand l’Event global n’est plus celui de jsdom', async () => {
        vi.useFakeTimers()

        const wrapper = mount(Modal, {
            props: { show: true },
            slots: { default: '<p>Contenu</p>' },
            attachTo: document.body,
        })
        const dialogue = document.querySelector('dialog')
        const fermetures = vi.fn()
        dialogue.addEventListener('close', fermetures)

        await wrapper.setProps({ show: false })

        globalThis.Event = class EventDUnAutreDomaine {
            constructor(type) {
                this.type = type
            }
        }

        expect(() => vi.advanceTimersByTime(200)).not.toThrow()
        expect(dialogue.open).toBe(false)
        expect(fermetures).toHaveBeenCalledOnce()

        globalThis.Event = EventDeLEnvironnement
        wrapper.unmount()
    })
})
