import { describe, it, expect, afterEach, beforeEach, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'

import SwipeableRow from '@/Components/UI/SwipeableRow.vue'
import RangeeDeSerie from '@/Components/Workout/RangeeDeSerie.vue'
import { evenementDeDoigt } from '../utils/geste'

describe('SwipeableRow — rangée ouverte', () => {
    const racines = []

    beforeEach(() => {
        vi.stubGlobal('requestAnimationFrame', () => 1)
        vi.stubGlobal('cancelAnimationFrame', () => {})
    })

    afterEach(() => {
        racines.splice(0).forEach((wrapper) => wrapper.unmount())
        vi.unstubAllGlobals()
    })

    const ouvrir = async () => {
        const wrapper = mount(SwipeableRow, {
            slots: { 'action-right': '<button type="button">Supprimer</button>', default: '<p>Série</p>' },
            attachTo: document.body,
        })
        racines.push(wrapper)
        const contenu = wrapper.find('[data-glissant]')
        const envoyer = (type, x, horloge) => contenu.element.dispatchEvent(evenementDeDoigt(type, { x, horloge }))

        envoyer('pointerdown', 200, 0)
        envoyer('pointermove', 188, 16)
        envoyer('pointermove', 100, 60)
        envoyer('pointerup', 100, 64)
        await flushPromises()

        return { wrapper, contenu }
    }

    it('se referme quand on touche ailleurs, pas quand on touche la rangée', async () => {
        const { wrapper, contenu } = await ouvrir()

        expect(wrapper.attributes('data-ouverte')).toBe('droite')

        contenu.element.dispatchEvent(new Event('pointerdown', { bubbles: true }))
        await flushPromises()
        expect(wrapper.attributes('data-ouverte')).toBe('droite')

        document.body.dispatchEvent(new Event('pointerdown', { bubbles: true }))
        await flushPromises()

        expect(wrapper.attributes('data-ouverte')).toBeUndefined()
    })
})

describe('RangeeDeSerie — champs de série', () => {
    it('porte une bordure lisible et un anneau de focus à la charte', () => {
        const wrapper = mount(RangeeDeSerie, {
            props: {
                set: { id: 9, weight: 80, reps: 5, is_completed: false, personal_record: null },
                index: 1,
                lineIndex: 2,
                line: { id: 1, exercise: { type: 'strength', name: 'Développé couché', default_rest_time: 120 } },
            },
            global: {
                stubs: { SwipeableRow: { template: '<div><slot /></div>' } },
                directives: { press: {} },
            },
        })

        const champs = wrapper.findAll('input[type="number"]')

        expect(champs).toHaveLength(2)
        for (const champ of champs) {
            expect(champ.classes()).toContain('border-text-muted')
            expect(champ.classes()).not.toContain('border-border')
            expect(champ.classes()).toContain('focus-visible:ring-2')
        }
    })
})
