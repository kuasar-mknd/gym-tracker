import { describe, expect, it } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { defineComponent, h } from 'vue'

import { graphiqueAsynchrone } from '@/Utils/graphiqueAsynchrone'

/*
 * Un graphique dont le morceau ne se charge pas (#2048, partie E). Les
 * graphiques restent hors du precache (décision 9 du propriétaire) : sans
 * réseau, sur une copie hors ligne, l'import échoue. Le graphique laissait
 * un trou dans la page ; il dit désormais « Graphique indisponible hors
 * ligne », et le reste de la page reste là.
 */

/** Une carte qui montre un titre et son graphique. */
const carteAvec = (Graphique) =>
    defineComponent({
        setup: () => () => h('section', [h('h3', 'Volume'), h(Graphique, { data: [1, 2, 3] })]),
    })

describe('un graphique chargé à la demande', () => {
    it('dit « Graphique indisponible hors ligne » quand son morceau ne se charge pas', async () => {
        const erreurs = []
        const Graphique = graphiqueAsynchrone(() =>
            Promise.reject(new TypeError('Failed to fetch dynamically imported module')),
        )
        const carte = mount(carteAvec(Graphique), {
            global: { config: { errorHandler: (erreur) => erreurs.push(erreur.message) } },
        })

        await flushPromises()

        expect(carte.find('h3').text()).toBe('Volume')
        expect(carte.find('[data-graphique-indisponible]').exists()).toBe(true)
        expect(carte.find('[role="status"]').text()).toContain('Graphique indisponible hors ligne')
        // L'échec reste une erreur que le rapporteur voit, en ligne comme hors ligne.
        expect(erreurs).toEqual(['Failed to fetch dynamically imported module'])
        carte.unmount()
    })

    it('rend le graphique quand son morceau se charge', async () => {
        const Graphique = graphiqueAsynchrone(() =>
            Promise.resolve({
                __esModule: true,
                default: { props: ['data'], render: () => h('canvas', { role: 'img' }) },
            }),
        )
        const carte = mount(carteAvec(Graphique))

        await flushPromises()

        expect(carte.find('canvas').exists()).toBe(true)
        expect(carte.find('[data-graphique-indisponible]').exists()).toBe(false)
        carte.unmount()
    })
})
