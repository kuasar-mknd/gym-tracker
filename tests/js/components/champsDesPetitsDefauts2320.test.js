import { describe, it, expect, afterEach } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'

import SwipeableRow from '@/Components/UI/SwipeableRow.vue'
import RangeeDeSerie from '@/Components/Workout/RangeeDeSerie.vue'

describe('SwipeableRow — rangée ouverte', () => {
    const racines = []

    afterEach(() => {
        racines.splice(0).forEach((wrapper) => wrapper.unmount())
    })

    const ouvrir = async () => {
        const wrapper = mount(SwipeableRow, {
            slots: { 'action-right': '<button type="button">Supprimer</button>', default: '<p>Série</p>' },
            attachTo: document.body,
        })
        racines.push(wrapper)
        const contenu = wrapper.find('.relative.z-10')
        await contenu.trigger('touchstart', { touches: [{ clientX: 200, clientY: 10 }] })
        await contenu.trigger('touchmove', { touches: [{ clientX: 100, clientY: 10 }] })
        await contenu.trigger('touchend')

        return { wrapper, contenu }
    }

    it('se referme quand on touche ailleurs, pas quand on touche la rangée', async () => {
        const { contenu } = await ouvrir()
        const ouverte = contenu.attributes('style')

        expect(ouverte).toMatch(/translateX\(-\d+/)

        contenu.element.dispatchEvent(new Event('pointerdown', { bubbles: true }))
        expect(contenu.attributes('style')).toBe(ouverte)

        document.body.dispatchEvent(new Event('pointerdown', { bubbles: true }))
        await flushPromises()

        expect(contenu.attributes('style')).toMatch(/translateX\(0px\)/)
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
