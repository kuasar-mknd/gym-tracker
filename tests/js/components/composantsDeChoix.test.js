import { describe, it, expect, vi } from 'vitest'
import { mount } from '@vue/test-utils'

vi.mock('@/composables/useHaptics', () => ({ triggerHaptic: vi.fn() }))

import GlassSegmented, { TAILLES as TAILLES_SEGMENT } from '@/Components/UI/GlassSegmented.vue'
import GlassChip from '@/Components/UI/GlassChip.vue'
import GlassTile, { TONS } from '@/Components/UI/GlassTile.vue'
import GlassIcon, { TAILLES as TAILLES_ICONE } from '@/Components/UI/GlassIcon.vue'
import GlassStat from '@/Components/UI/GlassStat.vue'
import { triggerHaptic } from '@/composables/useHaptics'

const options = [
    { value: '7d', label: '7 jours' },
    { value: '30d', label: '30 jours', icon: 'calendar_month' },
    { value: '1y', label: '1 an' },
]

describe('GlassSegmented', () => {
    const monter = (props = {}) =>
        mount(GlassSegmented, { props: { modelValue: '30d', options, label: 'Période', ...props } })

    it('nomme le groupe, marque l’option retenue et rend son icône', () => {
        const wrapper = monter()

        expect(wrapper.get('[role="group"]').attributes('aria-label')).toBe('Période')
        const boutons = wrapper.findAll('button')
        expect(boutons.map((b) => b.attributes('aria-pressed'))).toEqual(['false', 'true', 'false'])
        expect(boutons[1].find('.material-symbols-outlined').text()).toBe('calendar_month')
    })

    it('émet la valeur choisie une seule fois par changement, avec un retour haptique', async () => {
        const wrapper = monter()

        await wrapper.findAll('button')[0].trigger('click')
        await wrapper.findAll('button')[1].trigger('click')

        expect(wrapper.emitted('update:modelValue')).toEqual([['7d']])
        expect(triggerHaptic).toHaveBeenCalledWith('toggle')
    })

    it('passe d’une option à l’autre aux flèches, en boucle', async () => {
        const wrapper = monter({ attachTo: document.body })

        await wrapper.findAll('button')[2].trigger('keydown', { key: 'ArrowRight' })
        await wrapper.findAll('button')[0].trigger('keydown', { key: 'ArrowLeft' })
        await wrapper.findAll('button')[0].trigger('keydown', { key: 'Tab' })

        expect(wrapper.emitted('update:modelValue')).toEqual([['7d'], ['1y']])
        wrapper.unmount()
    })

    it('prend toute la largeur en bloc et garde une cible de 44 px en petite taille', () => {
        const bloc = monter({ bloc: true })
        expect(bloc.get('[role="group"]').classes()).toContain('w-full')
        expect(bloc.findAll('button')[0].classes()).toContain('flex-1')

        const petit = monter({ size: 'sm' })
        expect(petit.findAll('button')[0].classes()).toContain('before:-inset-1')
        expect(TAILLES_SEGMENT).toEqual(['sm', 'md'])
    })
})

describe('GlassChip', () => {
    it('dit si elle est retenue et rend son contenu', () => {
        const active = mount(GlassChip, {
            props: { active: true, icon: 'fitness_center' },
            slots: { default: 'Pectoraux' },
        })
        expect(active.attributes('aria-pressed')).toBe('true')
        expect(active.text()).toContain('Pectoraux')
        expect(active.classes()).toContain('bg-text-main')

        const inactive = mount(GlassChip, { props: { size: 'sm' }, slots: { default: 'Dos' } })
        expect(inactive.attributes('aria-pressed')).toBe('false')
        expect(inactive.classes()).toContain('before:-inset-1.5')
    })
})

describe('GlassTile', () => {
    it('est un choix quand `active` est posé, une action sinon', () => {
        const choix = mount(GlassTile, { props: { label: 'Homme', active: true, ton: 'secondary' } })
        expect(choix.attributes('aria-pressed')).toBe('true')
        expect(choix.classes()).toContain('bg-accent-secondary')

        const action = mount(GlassTile, { props: { label: '250 ml', icon: 'water_drop', detail: 'un verre' } })
        expect(action.attributes('aria-pressed')).toBeUndefined()
        expect(action.text()).toContain('un verre')
        expect(TONS).toEqual(['primary', 'secondary', 'info'])
    })
})

describe('GlassIcon', () => {
    it('est décorative par défaut et nommée quand on lui donne un libellé', () => {
        const decorative = mount(GlassIcon, { props: { name: 'bolt' } })
        expect(decorative.attributes('aria-hidden')).toBe('true')
        expect(decorative.classes()).toContain('text-2xl')

        const nommee = mount(GlassIcon, { props: { name: 'bolt', size: 'hero', fill: true, label: 'Énergie' } })
        expect(nommee.attributes('role')).toBe('img')
        expect(nommee.attributes('aria-label')).toBe('Énergie')
        expect(nommee.classes()).toEqual(expect.arrayContaining(['text-6xl', 'icone-pleine']))
        expect(Object.keys(TAILLES_ICONE)).toEqual(['xs', 'sm', 'md', 'lg', 'xl', '2xl', 'hero'])
    })
})

describe('GlassStat', () => {
    it('rend le chiffre, l’unité, le libellé et la tendance sans la colorer', () => {
        const wrapper = mount(GlassStat, {
            props: {
                valeur: '78,4',
                unite: 'kg',
                libelle: 'Poids',
                tendance: { texte: '+0,3 kg', sens: 'hausse' },
                taille: 'lg',
            },
            global: { stubs: { GlassCard: { template: '<div><slot /></div>' } } },
        })

        expect(wrapper.text()).toContain('78,4')
        expect(wrapper.text()).toContain('kg')
        expect(wrapper.text()).toContain('Poids')
        expect(wrapper.text()).toContain('+0,3 kg')
        expect(wrapper.find('.material-symbols-outlined').text()).toBe('trending_up')
        expect(wrapper.html()).not.toContain('text-accent-danger')
    })
})
