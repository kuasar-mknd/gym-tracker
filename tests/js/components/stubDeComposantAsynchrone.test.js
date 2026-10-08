import { describe, it, expect, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { defineAsyncComponent, h, nextTick } from 'vue'

/**
 * Un composant asynchrone remplacé par un stub n'est pas chargé (#2023).
 *
 * Test Utils appelait le chargeur d'un composant `defineAsyncComponent`
 * remplacé par `true` ou par `shallow: true`, sans attendre ce chargement. Au
 * dernier test d'un fichier, cet import n'avait pas abouti quand le fichier se
 * terminait ; résolu hors de tout test, il ne passait plus par le mock : le
 * vrai graphique se chargeait, et si l'environnement était démonté entre-temps,
 * Vitest sortait en erreur, tous les tests au vert. `vitest.setup.js` rend
 * désormais ces stubs sans appeler le chargeur.
 *
 * La page est écrite comme le rendu compilé d'un `<script setup>` : le
 * composant asynchrone est créé dans `setup`, et le rendu le lit sous le nom de
 * sa variable, que Test Utils retrouve pour appliquer `stubs`.
 */
const pageAvec = (chargeur) => ({
    setup() {
        const Graphique = defineAsyncComponent(chargeur)

        return { Graphique }
    },
    render() {
        return h('section', [h(this.Graphique, { series: [1, 2] })])
    },
})

const chargeurDuVraiGraphique = () =>
    vi.fn(() => Promise.resolve({ props: ['series'], render: () => h('p', { class: 'vrai-graphique' }) }))

describe('Stub d’un composant chargé à la demande', () => {
    it('ne charge pas le composant remplacé par `true`', () => {
        const chargeur = chargeurDuVraiGraphique()

        const wrapper = mount(pageAvec(chargeur), { global: { stubs: { Graphique: true } } })

        expect(chargeur).not.toHaveBeenCalled()
        expect(wrapper.find('graphique-stub').exists()).toBe(true)
        expect(wrapper.findComponent({ name: 'Graphique' }).exists()).toBe(true)
    })

    it('ne charge pas le composant qu’un montage superficiel remplace', () => {
        const chargeur = chargeurDuVraiGraphique()

        const wrapper = mount(pageAvec(chargeur), { shallow: true })

        expect(chargeur).not.toHaveBeenCalled()
        expect(wrapper.find('graphique-stub').exists()).toBe(true)
    })

    it('charge toujours le composant qu’aucun stub ne remplace', async () => {
        const chargeur = chargeurDuVraiGraphique()

        const wrapper = mount(pageAvec(chargeur))
        await flushPromises()
        await nextTick()

        expect(chargeur).toHaveBeenCalledOnce()
        expect(wrapper.find('.vrai-graphique').exists()).toBe(true)
    })

    it('laisse un stub écrit à la main remplacer le composant', () => {
        const chargeur = chargeurDuVraiGraphique()

        const wrapper = mount(pageAvec(chargeur), {
            global: { stubs: { Graphique: { props: ['series'], template: '<p class="stub-a-la-main" />' } } },
        })

        expect(chargeur).not.toHaveBeenCalled()
        expect(wrapper.find('.stub-a-la-main').exists()).toBe(true)
    })
})
