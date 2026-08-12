import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'

import GlassToggle from '@/Components/UI/GlassToggle.vue'

const mountToggle = (props = {}) =>
    mount(GlassToggle, {
        props,
        global: { directives: { press: {} } },
    })

const control = (wrapper) => wrapper.get('button[role="switch"]')

describe('GlassToggle', () => {
    it('asks for the opposite of what it currently shows', async () => {
        const off = mountToggle({ modelValue: false })
        const on = mountToggle({ modelValue: true })

        await control(off).trigger('click')
        await control(on).trigger('click')

        expect(off.emitted('update:modelValue')).toEqual([[true]])
        expect(on.emitted('update:modelValue')).toEqual([[false]])
    })

    it('reports its state to assistive technology', () => {
        expect(control(mountToggle({ modelValue: true })).attributes('aria-checked')).toBe('true')
        expect(control(mountToggle({ modelValue: false })).attributes('aria-checked')).toBe('false')
    })

    it('cannot be flipped while disabled', async () => {
        const wrapper = mountToggle({ modelValue: false, disabled: true })

        // The attribute is the first line of defence — a browser delivers no
        // click to a disabled control at all.
        expect(control(wrapper).attributes('disabled')).toBeDefined()

        // Dispatched rather than triggered: Vue Test Utils declines to trigger on
        // a disabled element, so a trigger here would pass whatever the handler
        // does and the `if (!props.disabled)` guard would go unexercised.
        control(wrapper).element.dispatchEvent(new MouseEvent('click'))
        await wrapper.vm.$nextTick()

        expect(wrapper.emitted('update:modelValue')).toBeUndefined()
    })

    it('stays operable when it is not disabled', async () => {
        const wrapper = mountToggle({ modelValue: false })

        expect(control(wrapper).attributes('disabled')).toBeUndefined()

        await control(wrapper).trigger('click')

        expect(wrapper.emitted('update:modelValue')).toEqual([[true]])
    })

    it('names itself with its label so a screen reader can tell two switches apart', () => {
        const wrapper = mountToggle({ modelValue: false, label: 'Notifications push' })

        expect(control(wrapper).attributes('aria-label')).toBe('Notifications push')
    })

    it('renders the label and description it is given', () => {
        const wrapper = mountToggle({
            modelValue: false,
            label: 'Notifications push',
            description: 'Recevoir une alerte à chaque record',
        })

        expect(wrapper.text()).toContain('Notifications push')
        expect(wrapper.text()).toContain('Recevoir une alerte à chaque record')
    })

    it('moves the thumb across when it is on, and only then', () => {
        const thumb = (wrapper) => wrapper.get('button[role="switch"] span').classes()

        expect(thumb(mountToggle({ modelValue: true }))).toContain('translate-x-5')
        expect(thumb(mountToggle({ modelValue: false }))).toContain('translate-x-0')
        expect(thumb(mountToggle({ modelValue: true, size: 'sm' }))).toContain('translate-x-4')
        expect(thumb(mountToggle({ modelValue: false, size: 'sm' }))).toContain('translate-x-0')
    })
})
