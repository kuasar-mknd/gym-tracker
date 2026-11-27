import { describe, it, expect, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'

/**
 * `Deferred` est remplacé plutôt que stubé : il attend une page Inertia, et ce
 * qui compte ici est la clé qu’il reçoit — c’est elle qui décide si la carte
 * sait un jour que ses données sont arrivées.
 */
vi.mock('@inertiajs/vue3', () => ({
    Deferred: {
        name: 'Deferred',
        props: { data: { type: [String, Array], default: null } },
        template: '<div><slot /></div>',
    },
}))

vi.mock('vue-chartjs', () => ({
    Line: { name: 'Line', props: { data: null, options: null }, template: '<div />' },
}))

/*
 * Le graphique est un defineAsyncComponent derrière un import dynamique.
 * Chargé ici d’abord, l’import de la carte se résout depuis le cache de
 * modules dans le tick qu’attend flushPromises — sinon le slot reste un nœud
 * commentaire et toute assertion sur le graphique porterait sur du vide.
 */
await import('@/Components/Stats/BodyFatChart.vue')

const BodyFatCard = (await import('@/Components/Stats/BodyFatCard.vue')).default

const mountCard = async (props = {}) => {
    const wrapper = mount(BodyFatCard, { props })

    await flushPromises()

    return wrapper
}

/** Le grand nombre de la carte. */
const reading = (wrapper) => wrapper.find('p.font-display').text().replace(/\s+/g, ' ')

/** Le graphique lui-même, pas le trou qu’il laisse dans le DOM. */
const chart = (wrapper) => wrapper.findComponent({ name: 'BodyFatChart' })

describe('BodyFatCard', () => {
    it('affiche un tiret plutôt qu’un zéro quand la masse grasse est inconnue', async () => {
        expect(reading(await mountCard({ bodyFat: null }))).toBe('— %')
        // 0 % de masse grasse n’existe pas : c’est une absence de mesure.
        expect(reading(await mountCard({ bodyFat: 0 }))).toBe('— %')
    })

    it('affiche la masse grasse mesurée', async () => {
        expect(reading(await mountCard({ bodyFat: 18.5 }))).toBe('18,5 %')
    })

    it('annonce l’absence d’historique plutôt qu’un graphique vide', async () => {
        const empty = await mountCard({ bodyFatHistory: [] })
        const missing = await mountCard({})

        // Le message seul ne prouve rien : un graphique tracé à côté de lui
        // laisserait le texte intact. C’est son absence qui est la promesse.
        expect(chart(empty).exists()).toBe(false)
        expect(empty.text()).toContain('Pas de données historiques')
        expect(chart(missing).exists()).toBe(false)
        expect(missing.text()).toContain('Pas de données historiques')
    })

    it('trace le graphique dès le premier point, avec l’historique reçu', async () => {
        const history = [{ date: '2026-03-01', body_fat: 18 }]
        const wrapper = await mountCard({ bodyFatHistory: history })

        // Le graphique doit être monté — pas seulement l’état vide absent — et
        // recevoir l’historique tel quel : c’est la carte qui le lui transmet.
        expect(chart(wrapper).exists()).toBe(true)
        expect(chart(wrapper).props('data')).toEqual(history)
        expect(wrapper.text()).not.toContain('Pas de données historiques')
    })

    it('transmet tout l’historique au graphique, sans le tronquer', async () => {
        const history = [
            { date: '2026-01-01', body_fat: 21 },
            { date: '2026-02-01', body_fat: 19.5 },
            { date: '2026-03-01', body_fat: 18 },
        ]
        const wrapper = await mountCard({ bodyFatHistory: history })

        expect(chart(wrapper).props('data')).toEqual(history)
    })

    it('attend la prop différée que la page Corps envoie, même quand elle n’est pas encore arrivée', async () => {
        const keys = (await mountCard({})).findAllComponents({ name: 'Deferred' }).map((d) => d.props('data'))

        expect(keys).toEqual(['bodyStats'])
    })
})
