import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'

import Checkbox from '@/Components/Form/Checkbox.vue'

const mountCheckbox = (props = {}) => mount(Checkbox, { props })
const box = (wrapper) => wrapper.get('input[type="checkbox"]')

describe('Checkbox — the two shapes it is bound to', () => {
    it('reads a boolean binding', () => {
        expect(box(mountCheckbox({ checked: true })).element.checked).toBe(true)
        expect(box(mountCheckbox({ checked: false })).element.checked).toBe(false)
    })

    it('is ticked when the bound array already holds its value', () => {
        expect(box(mountCheckbox({ checked: ['a', 'b'], value: 'b' })).element.checked).toBe(true)
    })

    it('is not ticked when the bound array holds someone else', () => {
        expect(box(mountCheckbox({ checked: ['a', 'c'], value: 'b' })).element.checked).toBe(false)
    })
})

describe('Checkbox — what it hands back', () => {
    it('adds its value to the array it was given, keeping the rest', async () => {
        const wrapper = mountCheckbox({ checked: ['a', 'c'], value: 'b' })

        await box(wrapper).setValue(true)

        expect(wrapper.emitted('update:checked')).toEqual([[['a', 'c', 'b']]])
    })

    it('takes its value back out, keeping the rest', async () => {
        const wrapper = mountCheckbox({ checked: ['a', 'b'], value: 'b' })

        await box(wrapper).setValue(false)

        expect(wrapper.emitted('update:checked')).toEqual([[['a']]])
    })

    /**
     * The callers bind model ids. A checkbox value goes through the DOM as a
     * string, so a number that came back as "7" would never match the `id === 7`
     * the parent then compares it against.
     */
    it('hands back an id as the number it was given, not as a string', async () => {
        const wrapper = mountCheckbox({ checked: [], value: 7 })

        await box(wrapper).setValue(true)

        expect(wrapper.emitted('update:checked')[0][0]).toEqual([7])
    })

    it('hands back a boolean when that is what it was bound to', async () => {
        const wrapper = mountCheckbox({ checked: false })

        await box(wrapper).setValue(true)

        expect(wrapper.emitted('update:checked')).toEqual([[true]])
    })
})
