import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'

const rechargerMaintenant = vi.hoisted(() => vi.fn())

vi.mock('@/Utils/miseAJourDuWorker', async (original) => ({
    ...(await original()),
    rechargerMaintenant: (...args) => rechargerMaintenant(...args),
}))

const { nouvelleVersionPrete } = await import('@/Utils/miseAJourDuWorker')
const { default: BandeauDeMiseAJour } = await import('@/Components/UI/BandeauDeMiseAJour.vue')

const monter = () => mount(BandeauDeMiseAJour, { global: { directives: { press: {} } } })

beforeEach(() => {
    nouvelleVersionPrete.value = false
    rechargerMaintenant.mockClear()
})

describe('BandeauDeMiseAJour', () => {
    it('ne dessine rien tant qu’aucune nouvelle version n’est prête', () => {
        expect(monter().find('[dusk="bandeau-mise-a-jour"]').exists()).toBe(false)
    })

    it('propose de recharger quand une nouvelle version a pris la main, sans le faire', async () => {
        const wrapper = monter()

        nouvelleVersionPrete.value = true
        await wrapper.vm.$nextTick()

        const bandeau = wrapper.find('[dusk="bandeau-mise-a-jour"]')
        expect(bandeau.exists()).toBe(true)
        expect(bandeau.attributes('role')).toBe('status')
        expect(bandeau.text()).toContain('Nouvelle version prête')
        expect(rechargerMaintenant).not.toHaveBeenCalled()
    })

    it('recharge sur le geste', async () => {
        nouvelleVersionPrete.value = true
        const wrapper = monter()

        await wrapper.find('[dusk="recharger-la-nouvelle-version"]').trigger('click')

        expect(rechargerMaintenant).toHaveBeenCalledTimes(1)
    })

    it('se range sur « Plus tard », la nouvelle version attendant la page suivante', async () => {
        nouvelleVersionPrete.value = true
        const wrapper = monter()

        await wrapper.find('[aria-label="Plus tard"]').trigger('click')

        expect(wrapper.find('[dusk="bandeau-mise-a-jour"]').exists()).toBe(false)
        expect(rechargerMaintenant).not.toHaveBeenCalled()
    })
})
