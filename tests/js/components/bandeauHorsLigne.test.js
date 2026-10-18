import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'
import { reactive } from 'vue'

/*
 * Le bandeau d'une page servie depuis sa copie hors ligne (#2048, partie E).
 * Le vrai client d'Inertia le montre et le retire dans
 * `bandeauHorsLigneAvecInertia.test.js` ; ici, ce qu'il dit, d'après la marque
 * de la page.
 */

const page = reactive({ props: {} })

vi.mock('@inertiajs/vue3', () => ({ usePage: () => page }))

const reessayer = vi.hoisted(() => vi.fn())
const enRechargement = vi.hoisted(() => ({ valeur: null }))

vi.mock('@/Utils/visitesHorsLigne', async (original) => {
    const { ref: creer } = await import('vue')
    enRechargement.valeur = creer(false)

    return {
        ...(await original()),
        reessayerLaCopie: (...args) => reessayer(...args),
        rechargementDeLaCopie: enRechargement.valeur,
    }
})

const { default: BandeauHorsLigne } = await import('@/Components/UI/BandeauHorsLigne.vue')
const { ID_DE_LA_RAISON_DES_ECRITURES, RAISON_DES_ECRITURES_SUR_UNE_COPIE } =
    await import('@/composables/useCopieHorsLigne')

/** Le lundi 12 octobre 2026 à 18:04, à l'heure de l'appareil. */
const LUNDI_18_04 = new Date(2026, 9, 12, 18, 4).getTime()

const monter = () => mount(BandeauHorsLigne, { global: { directives: { press: {} } } })

const servir = (marque) => {
    page.props = { errors: {}, horsLigne: marque }
}

beforeEach(() => {
    page.props = { errors: {} }
    enRechargement.valeur.value = false
    reessayer.mockClear()
})

describe('BandeauHorsLigne', () => {
    it('ne dessine rien sur une page que le serveur a rendue', () => {
        expect(monter().find('[dusk="bandeau-hors-ligne"]').exists()).toBe(false)
    })

    it('dit qu’il n’y a pas de réseau, date la copie, et prévient que ce qui a changé n’y est pas', () => {
        servir({ copieDu: LUNDI_18_04, raison: 'reseau', datesDesProps: {} })
        const bandeau = monter()
        const region = bandeau.get('[role="status"]')

        expect(bandeau.get('[dusk="bandeau-hors-ligne-titre"]').text()).toBe(
            'Hors ligne · copie du lun. 12 oct. 2026 à 18:04',
        )
        expect(region.text()).toContain("Ce qui a changé depuis n'apparaît pas ici.")
        expect(region.attributes('aria-live')).toBe('polite')
        expect(region.classes()).toContain('info-fill')
        expect(region.text()).toContain('cloud_off')
    })

    it('dit que le réseau ne répond pas quand la copie est servie pour lenteur', () => {
        servir({ copieDu: LUNDI_18_04, raison: 'lent', datesDesProps: {} })

        expect(monter().get('[dusk="bandeau-hors-ligne-titre"]').text()).toBe(
            'Le réseau ne répond pas · copie du lun. 12 oct. 2026 à 18:04',
        )
    })

    /*
     * La séance se verse dans sa copie à chaque instantané, bien après la
     * réponse qui l'a ouverte : datée de cette réponse, elle aurait paru plus
     * ancienne que les séries qu'elle montre.
     */
    it('date la copie de sa prop la plus récente', () => {
        servir({
            copieDu: LUNDI_18_04,
            raison: 'reseau',
            datesDesProps: { workout: LUNDI_18_04 + 36 * 60_000, exercises: LUNDI_18_04 },
        })

        expect(monter().get('[dusk="bandeau-hors-ligne-titre"]').text()).toBe(
            'Hors ligne · copie du lun. 12 oct. 2026 à 18:40',
        )
    })

    it('ne montre pas de date qui ne se lit pas', () => {
        servir({ copieDu: 'hier', raison: 'reseau' })

        expect(monter().get('[dusk="bandeau-hors-ligne-titre"]').text()).toBe(
            "Hors ligne · copie gardée sur l'appareil",
        )
    })

    it('« Réessayer » recharge la page, et attend la fin du rechargement', async () => {
        servir({ copieDu: LUNDI_18_04, raison: 'reseau', datesDesProps: {} })
        const bandeau = monter()
        const bouton = bandeau.get('[dusk="reessayer-hors-ligne"]')

        expect(bouton.text()).toContain('Réessayer')
        expect(bouton.attributes('disabled')).toBeUndefined()

        await bouton.trigger('click')

        expect(reessayer).toHaveBeenCalledTimes(1)

        enRechargement.valeur.value = true
        await bandeau.vm.$nextTick()

        expect(bouton.attributes('disabled')).toBeDefined()
        expect(bouton.attributes('aria-busy')).toBe('true')
    })

    it('porte, hors de sa région annoncée, la raison des boutons d’écriture désactivés', () => {
        servir({ copieDu: LUNDI_18_04, raison: 'reseau', datesDesProps: {} })
        const raison = monter().get(`#${ID_DE_LA_RAISON_DES_ECRITURES}`)

        expect(raison.text()).toBe(RAISON_DES_ECRITURES_SUR_UNE_COPIE)
        expect(raison.attributes('hidden')).toBeDefined()
        expect(raison.element.closest('[role="status"]')).toBeNull()
    })

    it('part avec la marque, quand une réponse complète du serveur remplace la copie', async () => {
        servir({ copieDu: LUNDI_18_04, raison: 'reseau', datesDesProps: {} })
        const bandeau = monter()

        page.props = { errors: {} }
        await bandeau.vm.$nextTick()

        expect(bandeau.find('[dusk="bandeau-hors-ligne"]').exists()).toBe(false)
        expect(bandeau.find(`#${ID_DE_LA_RAISON_DES_ECRITURES}`).exists()).toBe(false)
    })
})
