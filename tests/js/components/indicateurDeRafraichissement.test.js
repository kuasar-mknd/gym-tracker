import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'

import IndicateurDeRafraichissement from '@/Components/UI/IndicateurDeRafraichissement.vue'

const monter = (props = {}) => mount(IndicateurDeRafraichissement, { props })

describe('IndicateurDeRafraichissement', () => {
    it('ne montre rien tant que personne ne tire la page', () => {
        expect(monter().find('.rounded-full').exists()).toBe(false)
    })

    it('descend avec le doigt, sans jamais dépasser cent cinquante pixels', async () => {
        const wrapper = monter({ distance: 60 })

        expect(wrapper.attributes('style')).toContain('translateY(60px)')
        expect(wrapper.text()).toContain('arrow_downward')

        await wrapper.setProps({ distance: 400 })

        expect(wrapper.attributes('style')).toContain('translateY(150px)')
    })

    it('retourne sa flèche passé le seuil où lâcher recharge', async () => {
        const wrapper = monter({ distance: 60 })

        expect(wrapper.get('span').attributes('style')).toContain('rotate(0deg)')

        await wrapper.setProps({ distance: 120 })

        expect(wrapper.get('span').attributes('style')).toContain('rotate(180deg)')
    })

    it('tourne pendant le rechargement, même une fois le doigt levé', () => {
        const wrapper = monter({ distance: 0, enCours: true })

        expect(wrapper.find('svg.animate-spin').exists()).toBe(true)
        expect(wrapper.find('span').exists()).toBe(false)
    })
})
