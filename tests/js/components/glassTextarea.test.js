import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import GlassTextarea from '@/Components/UI/GlassTextarea.vue'

const monter = (props = {}) => mount(GlassTextarea, { props: { modelValue: '', label: 'Description', ...props } })

describe('GlassTextarea', () => {
    it('relie l’étiquette au champ et remonte la saisie', async () => {
        const wrapper = monter()
        const champ = wrapper.get('textarea')

        expect(wrapper.get('label').attributes('for')).toBe(champ.attributes('id'))
        await champ.setValue('Rowing lourd')

        expect(wrapper.emitted('update:modelValue')).toEqual([['Rowing lourd']])
    })

    it('compte les caractères quand une longueur est posée', async () => {
        const wrapper = monter({ modelValue: 'abc', maxlength: 10 })

        expect(wrapper.text()).toContain('3 / 10')
        expect(wrapper.get('textarea').attributes('maxlength')).toBe('10')
    })

    it('annonce l’erreur et la relie au champ', () => {
        const wrapper = monter({ error: 'Trop long.' })
        const champ = wrapper.get('textarea')

        expect(champ.attributes('aria-invalid')).toBe('true')
        expect(wrapper.get('[role="alert"]').attributes('id')).toBe(champ.attributes('aria-describedby'))
    })

    it('cache l’étiquette aux yeux mais pas aux lecteurs d’écran', () => {
        expect(monter({ hideLabel: true }).get('label').classes()).toContain('sr-only')
    })

    it('suit la hauteur du texte', async () => {
        const wrapper = monter({ modelValue: 'a' })
        const champ = wrapper.get('textarea').element
        Object.defineProperty(champ, 'scrollHeight', { configurable: true, value: 120 })

        await wrapper.setProps({ modelValue: 'a\nb\nc\nd' })
        await wrapper.vm.$nextTick()

        expect(champ.style.height).toBe('120px')
    })
})
