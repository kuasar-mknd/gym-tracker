import { describe, it, expect, beforeAll, afterEach } from 'vitest'
import { mount } from '@vue/test-utils'
import { nextTick } from 'vue'

import Modal from '@/Components/UI/Modal.vue'

/**
 * Le tiroir et la feuille : la poignée, le glissement vers le bas. jsdom n'a pas de <dialog> : ces deux méthodes en tiennent lieu.
 */
beforeAll(() => {
    HTMLDialogElement.prototype.showModal = function showModal() {
        this.open = true
    }
    HTMLDialogElement.prototype.close = function close() {
        if (!this.open) {
            return
        }

        this.open = false
        this.dispatchEvent(new Event('close'))
    }
})

const montes = []

const monter = (props = {}) => {
    const wrapper = mount(Modal, {
        props: { show: true, ...props },
        slots: { default: '<p>Contenu</p>' },
        attachTo: document.body,
    })

    montes.push(wrapper)

    return wrapper
}

const poignee = () => document.querySelector('[data-testid="poignee-du-tiroir"]')
const panneau = () => document.querySelector('.glass-modal')

const geste = (element, type, clientY, timeStamp) => {
    const evenement = new Event(type, { bubbles: true })

    Object.defineProperties(evenement, {
        clientY: { value: clientY },
        pointerId: { value: 1 },
        timeStamp: { value: timeStamp },
    })
    element.dispatchEvent(evenement)
}

afterEach(() => {
    montes.splice(0).forEach((wrapper) => wrapper.unmount())
    document.body.innerHTML = ''
})

describe('Modal, tiroir et feuille', () => {
    it.each([
        ['tiroir', true],
        ['feuille', true],
        ['center', false],
        ['bottom', false],
    ])('la poignée de la position « %s » : %s', async (position, presente) => {
        monter({ position })
        await nextTick()

        expect(poignee() !== null).toBe(presente)
    })

    it('la feuille tient à la hauteur de son contenu, le tiroir a une hauteur fixe', async () => {
        monter({ position: 'feuille' })
        await nextTick()
        expect(panneau().className).toContain('hauteur-max-tiroir')
        expect(panneau().className).not.toMatch(/(^|\s)hauteur-tiroir(\s|$)/)

        monter({ position: 'tiroir' })
        await nextTick()
        expect(document.querySelectorAll('.glass-modal')[1].className).toMatch(/(^|\s)hauteur-tiroir(\s|$)/)
    })

    it('se ferme quand la poignée descend de plus de 120 px', async () => {
        const wrapper = monter({ position: 'tiroir' })
        await nextTick()

        geste(poignee(), 'pointerdown', 100, 0)
        geste(poignee(), 'pointermove', 260, 500)
        await nextTick()
        expect(panneau().style.transform).toContain('translateY(160px)')

        geste(poignee(), 'pointerup', 260, 500)

        expect(wrapper.emitted('close')).toHaveLength(1)
    })

    it('se ferme sur un geste vif de plus de 40 px, même court', async () => {
        const wrapper = monter({ position: 'tiroir' })
        await nextTick()

        geste(poignee(), 'pointerdown', 100, 0)
        geste(poignee(), 'pointermove', 160, 50)
        geste(poignee(), 'pointerup', 160, 50)

        expect(wrapper.emitted('close')).toHaveLength(1)
    })

    it('revient à sa place sur un geste court et lent', async () => {
        const wrapper = monter({ position: 'tiroir' })
        await nextTick()

        geste(poignee(), 'pointerdown', 100, 0)
        geste(poignee(), 'pointermove', 150, 1000)
        geste(poignee(), 'pointerup', 150, 1000)
        await nextTick()

        expect(wrapper.emitted('close')).toBeUndefined()
        expect(panneau().style.transform).toBe('')
    })

    it('ne glisse pas quand la fenêtre n’est pas fermable', async () => {
        const wrapper = monter({ position: 'tiroir', closeable: false })
        await nextTick()

        geste(poignee(), 'pointerdown', 100, 0)
        geste(poignee(), 'pointermove', 400, 100)
        geste(poignee(), 'pointerup', 400, 100)

        expect(wrapper.emitted('close')).toBeUndefined()
    })

    it('repart de zéro à la réouverture, après un glissement', async () => {
        const wrapper = monter({ position: 'tiroir' })
        await nextTick()

        geste(poignee(), 'pointerdown', 100, 0)
        geste(poignee(), 'pointermove', 300, 100)
        geste(poignee(), 'pointerup', 300, 100)
        await wrapper.setProps({ show: false })
        await wrapper.setProps({ show: true })

        expect(panneau().style.transform).toBe('')
    })
})
