import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { mount } from '@vue/test-utils'
import ConfirmDialog from '@/Components/UI/ConfirmDialog.vue'

beforeEach(() => vi.useFakeTimers())
afterEach(() => vi.useRealTimers())

const monter = (props) =>
    mount(ConfirmDialog, {
        props: { titre: 'Terminer ?', ouvert: true, ...props },
        attachTo: document.body,
        global: { stubs: { Modal: { template: '<div><slot /></div>' } } },
    })

describe('ConfirmDialog', () => {
    it('n’accepte pour le bouton qui agit que « danger » (une perte) ou « primary » (un geste ordinaire)', () => {
        const { validator, default: parDefaut } = ConfirmDialog.props.varianteConfirmation

        expect(parDefaut).toBe('danger')
        expect(validator('danger')).toBe(true)
        expect(validator('primary')).toBe(true)
        expect(validator('secondary')).toBe(false)
        expect(validator('rouge')).toBe(false)
    })

    describe('le focus à la fermeture, quand le bouton qui avait ouvert le dialogue a disparu', () => {
        let wrapper
        let cible

        beforeEach(() => {
            cible = document.createElement('h3')
            cible.tabIndex = -1
            document.body.appendChild(cible)
        })

        afterEach(() => {
            wrapper.unmount()
            cible.remove()
        })

        it('va à l’élément désigné, une fois le dialogue retiré', async () => {
            wrapper = monter({ focusApres: () => cible })
            document.activeElement.blur()

            await wrapper.setProps({ ouvert: false })
            expect(document.activeElement).not.toBe(cible)

            vi.advanceTimersByTime(250)
            expect(document.activeElement).toBe(cible)
        })

        it('laisse le focus où il est quand il n’est pas perdu', async () => {
            const bouton = document.createElement('button')
            document.body.appendChild(bouton)
            wrapper = monter({ focusApres: () => cible })
            bouton.focus()

            await wrapper.setProps({ ouvert: false })
            vi.advanceTimersByTime(250)

            expect(document.activeElement).toBe(bouton)
            bouton.remove()
        })

        it('ne fait rien sans élément désigné', async () => {
            wrapper = monter({})

            await wrapper.setProps({ ouvert: false })

            expect(vi.getTimerCount()).toBe(0)
        })
    })
})
