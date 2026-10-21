import { describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { reactive } from 'vue'

const { page, attendreLaReponse } = vi.hoisted(() => ({
    page: { props: {} },
    attendreLaReponse: vi.fn(() => Promise.resolve()),
}))

vi.mock('@inertiajs/vue3', async () => {
    const { reactive: reactif } = await import('vue')

    return {
        Head: { template: '<div />' },
        Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
        usePage: () => reactif(page),
    }
})

vi.mock('@/Utils/messagesDuWorker', () => ({ attendreLaReponse }))

import HorsLigne from '@/Pages/HorsLigne.vue'
import { dateAvecJour, heureCourte } from '@/Utils/date'
import { passesSlot } from './pageStubs'

/*
 * La page que la coquille hors ligne monte à une adresse sans copie (#2048,
 * partie F) : ce qui s'ouvre sans réseau, nommé et daté, chaque entrée un
 * lien vers sa copie, et « Attendre la réponse » quand le worker l'a servie au
 * délai.
 */

const COPIE_DU = Date.UTC(2026, 9, 19, 16, 4)

const PAGES = [
    { chemin: '/dashboard', composant: 'Dashboard', copieDu: COPIE_DU, enCours: false },
    { chemin: '/workouts/12', composant: 'Workouts/Show', copieDu: COPIE_DU, enCours: true },
    { chemin: '/workouts/9', composant: 'Workouts/Show', copieDu: COPIE_DU, enCours: false },
    { chemin: '/tools/interval-timer', composant: 'Tools/IntervalTimer', copieDu: COPIE_DU, enCours: false },
    // Gardée depuis #2049, pour lancer un modèle sans réseau.
    { chemin: '/templates', composant: 'Workouts/Templates/Index', copieDu: COPIE_DU, enCours: false },
]

/**
 * La page, marquée comme une copie servie pour `raison`.
 *
 * @param {string} raison
 */
const monter = (raison) => {
    page.props = reactive({ horsLigne: { copieDu: COPIE_DU, raison, datesDesProps: {} } })

    return mount(HorsLigne, {
        props: { pagesSansReseau: PAGES },
        global: { stubs: { AuthenticatedLayout: passesSlot, GlassCard: passesSlot } },
    })
}

describe('la page HorsLigne', () => {
    it('dit que la page n’est pas disponible, et liste ce qui s’ouvre sans réseau, nommé et daté', () => {
        const wrapper = monter('reseau')
        const date = new Date(COPIE_DU)

        expect(wrapper.find('h2').text()).toBe("Cette page n'est pas disponible sans réseau")
        expect(wrapper.findAll('[dusk="page-sans-reseau"]').map((lien) => lien.attributes('href'))).toEqual([
            '/dashboard',
            '/workouts/12',
            '/workouts/9',
            '/tools/interval-timer',
            '/templates',
        ])
        expect(wrapper.findAll('[dusk="page-sans-reseau"] span:first-child').map((nom) => nom.text())).toEqual([
            'Accueil',
            'Séance en cours',
            'Séance terminée',
            "Minuteur d'intervalles",
            'Modèles',
        ])
        expect(wrapper.find('[dusk="page-sans-reseau"] span:last-child').text()).toBe(
            `copie du ${dateAvecJour(date)} à ${heureCourte(date)}`,
        )
    })

    it('sans réseau, ne propose pas d’attendre la réponse', () => {
        const wrapper = monter('reseau')

        expect(wrapper.find('[dusk="attendre-la-reponse"]').exists()).toBe(false)
    })

    /*
     * Servie au délai : le serveur répond peut-être, lentement. Le worker lève
     * le délai de l'adresse pendant une minute, puis la page se recharge.
     */
    it('servie au délai, propose d’attendre la réponse, une fois', async () => {
        const wrapper = monter('lent')
        const bouton = wrapper.find('[dusk="attendre-la-reponse"]')

        expect(bouton.text()).toBe('Attendre la réponse')
        expect(wrapper.text()).toContain('Le réseau ne répond pas')

        await bouton.trigger('click')

        expect(attendreLaReponse).toHaveBeenCalledTimes(1)
        expect(bouton.attributes('disabled')).toBeDefined()
    })
})
