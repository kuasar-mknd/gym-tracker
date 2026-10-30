import { describe, it, expect, vi, afterEach } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { reactive } from 'vue'

import { titresExposes } from '../conventions/exposition'

/**
 * De vraies pages dans le vrai layout : chacune expose un seul `<h1>`, sur
 * téléphone comme sur bureau (#2120). Une par façon de porter le titre de
 * bureau : l'en-tête de page (`#header`), le titre `sr-only` que le layout
 * tire de `page-title`, le titre que la page pose dans son contenu
 * (`titre-dans-la-page`), et le calculateur, qui pose le sien à toute largeur.
 * `titresDePage.test.js` tient toutes les pages ; ceux-ci prouvent le rendu.
 */
const page = reactive({ props: {}, url: '/', component: 'Page' })

vi.mock('@inertiajs/vue3', async () => {
    const { reactive: reactif } = await vi.importActual('vue')

    return {
        Head: { template: '<div />' },
        Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
        usePage: () => page,
        router: { post: vi.fn(), delete: vi.fn(), visit: vi.fn(), reload: vi.fn() },
        useForm: (donnees) =>
            reactif({ ...donnees, errors: {}, processing: false, post: vi.fn(), put: vi.fn(), reset: vi.fn() }),
    }
})
vi.mock('@/composables/useAbonnementPush', () => ({ rapprocherLAbonnementPush: () => {} }))
vi.mock('@/composables/useDeconnexion', () => ({ seDeconnecter: () => {} }))
vi.mock('@/Utils/http', () => ({ http: () => Promise.reject({ code: 'ERR_NETWORK', request: {} }) }))
vi.mock('@/Components/Stats/SupplementUsageChart.vue', () => ({ __esModule: true, default: { template: '<div />' } }))
vi.mock('@/Components/Stats/OneRepMaxPercentagesChart.vue', () => ({
    __esModule: true,
    default: { template: '<div />' },
}))

const route = (nom) => (nom === undefined ? { current: () => false } : `/${nom}`)
globalThis.route = route

const OneRepMax = (await import('@/Pages/Tools/OneRepMax.vue')).default
const Raccourcis = (await import('@/Pages/Raccourcis.vue')).default
const SupplementsIndex = (await import('@/Pages/Supplements/Index.vue')).default
const ToolsIndex = (await import('@/Pages/Tools/Index.vue')).default

const montees = []

afterEach(() => {
    montees.splice(0).forEach((wrapper) => wrapper.unmount())
})

const monter = async (composant, props = {}) => {
    page.props = { auth: { user: { name: 'Samuel', unread_notifications_count: 0 } }, flash: {} }

    const wrapper = mount(composant, {
        props,
        global: {
            directives: { press: {} },
            mocks: { route, $page: page },
            stubs: { LiquidBackground: true, CelebrationModal: true, BottomNav: true, QuestionDeDeconnexion: true },
        },
    })
    montees.push(wrapper)
    await flushPromises()

    return wrapper
}

describe('un seul h1 par page, sur téléphone comme sur bureau (#2120)', () => {
    it('les outils : l’en-tête mobile, puis l’en-tête de page', async () => {
        expect(titresExposes((await monter(ToolsIndex)).element)).toEqual({ telephone: ['Outils'], bureau: ['Outils'] })
    })

    it('les raccourcis : l’en-tête mobile, puis le titre que le layout pose pour les lecteurs d’écran', async () => {
        const wrapper = await monter(Raccourcis)

        expect(titresExposes(wrapper.element)).toEqual({ telephone: ['Raccourcis'], bureau: ['Raccourcis'] })
        expect(wrapper.findAll('h1').at(-1).classes()).toContain('sr-only')
    })

    it('les compléments : l’en-tête mobile, puis le titre que la page pose sur bureau', async () => {
        const wrapper = await monter(SupplementsIndex, { supplements: [], usageHistory: [] })

        expect(titresExposes(wrapper.element)).toEqual({ telephone: ['Compléments'], bureau: ['Mes Compléments'] })
    })

    it('un calculateur : son propre titre, à toute largeur', async () => {
        const wrapper = await monter(OneRepMax, { history: [] })

        expect(titresExposes(wrapper.element)).toEqual({
            telephone: ['Calculatrice 1RM'],
            bureau: ['Calculatrice 1RM'],
        })
    })
})
