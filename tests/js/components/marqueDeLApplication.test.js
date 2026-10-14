import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import MarqueDeLApplication from '@/Components/UI/MarqueDeLApplication.vue'
import { CADRE_DU_LOGO, TRACES_DE_LA_LETTRE, TRACES_DES_BARRES, logoEnSvg } from '@/Utils/dessinDuLogo'

const monter = (props = {}) => mount(MarqueDeLApplication, { props })

/** Les tracés d'un groupe du logo, lus dans le rendu. */
const tracesDuGroupe = (logo, classe) => logo.findAll(`g.${classe} path`).map((trace) => trace.attributes('d'))

describe('MarqueDeLApplication', () => {
    /**
     * Le logo est tracé dans la page et non chargé : l'en-tête reste entier
     * hors ligne, où public/logo.svg n'est pas précaché, et ses couleurs
     * suivent les jetons `logo-*` de la charte.
     */
    it('trace le logo dans la page, aux couleurs de la charte', () => {
        const marque = monter()
        const logo = marque.get('[data-testid="logo-de-l-application"]')

        expect(marque.find('img').exists()).toBe(false)
        expect(logo.element.tagName.toLowerCase()).toBe('svg')
        expect(logo.attributes('viewBox')).toBe(CADRE_DU_LOGO)
        expect(tracesDuGroupe(logo, 'fill-logo-lettre')).toEqual(TRACES_DE_LA_LETTRE)
        expect(tracesDuGroupe(logo, 'fill-logo-barres')).toEqual(TRACES_DES_BARRES)
    })

    /**
     * Le nom écrit à côté nomme le lien qui entoure la marque. Un nom posé
     * aussi sur le dessin ferait annoncer « GymTracker GymTracker ».
     */
    it('laisse le nom écrit nommer la marque, et tait le dessin', () => {
        const marque = monter()
        const logo = marque.get('[data-testid="logo-de-l-application"]')

        expect(marque.text()).toBe('GymTracker')
        expect(logo.attributes('aria-hidden')).toBe('true')
        expect(logo.attributes('focusable')).toBe('false')
        expect(logo.attributes('aria-label')).toBeUndefined()
    })

    it.each([
        ['barre', 'size-10', 'text-2xl', 'flex-row'],
        ['page', 'size-20', 'sr-only', 'flex-col'],
    ])('se dispose pour %s', (disposition, taille, nom, sens) => {
        const marque = monter({ disposition })

        expect(marque.get('svg').classes()).toContain(taille)
        expect(marque.get('svg + span').classes()).toContain(nom)
        expect(marque.classes()).toContain(sens)
    })

    /**
     * Sur le fond que teinte LiquidBackground, l'orange des barres ne rendait
     * que 2,86:1 ; la tuile de l'icône lui rend ses 3,21 quel que soit le fond.
     */
    it.each(['barre', 'page'])('pose le logo sur la tuile de l’icône, en %s', (disposition) => {
        expect(monter({ disposition }).get('svg').classes()).toContain('bg-logo-fond')
    })

    it('se met dans la barre de navigation par défaut', () => {
        expect(monter().get('svg').classes()).toContain('size-10')
    })

    /**
     * La barre de bureau, ouverte dès `sm`, n'a pas la place du logo et du nom
     * à la fois : les deux lui demandaient 1 103 px de large, et en deçà, le
     * menu du compte sortait de l'écran. Le nom ne s'y montre qu'à partir de
     * `xl`, et reste lu en deçà : le lien vers l'accueil se nomme toujours.
     * Au-dessus d'une page, le logo se montre seul, à la demande du
     * propriétaire, et le nom reste lu.
     */
    it('ne montre le nom dans la barre qu’à partir de xl, jamais au-dessus d’une page, et le laisse toujours lire', () => {
        const barre = monter({ disposition: 'barre' })
        const nomDeLaBarre = barre.get('svg + span')
        const nomDeLaPage = monter({ disposition: 'page' }).get('svg + span')

        expect(nomDeLaBarre.classes()).toEqual(expect.arrayContaining(['sr-only', 'xl:not-sr-only']))
        expect(nomDeLaBarre.attributes('aria-hidden')).toBeUndefined()
        expect(barre.text()).toBe('GymTracker')
        expect(nomDeLaPage.classes()).toContain('sr-only')
        expect(nomDeLaPage.classes()).not.toContain('xl:not-sr-only')
        expect(nomDeLaPage.attributes('aria-hidden')).toBeUndefined()
        expect(nomDeLaPage.text()).toBe('GymTracker')
    })
})

describe('logoEnSvg', () => {
    /** La page « hors ligne » l'écrit hors de Vue : même dessin, mêmes couleurs, même silence. */
    it('écrit le même logo que le composant', () => {
        const document_ = new DOMParser().parseFromString(logoEnSvg('size-20'), 'image/svg+xml')
        const logo = document_.documentElement
        const traces = (classe) =>
            [...logo.querySelectorAll(`g.${classe} path`)].map((trace) => trace.getAttribute('d'))

        expect(logo.getAttribute('viewBox')).toBe(CADRE_DU_LOGO)
        expect(logo.getAttribute('class')).toBe('size-20')
        expect(logo.getAttribute('aria-hidden')).toBe('true')
        expect(traces('fill-logo-lettre')).toEqual(TRACES_DE_LA_LETTRE)
        expect(traces('fill-logo-barres')).toEqual(TRACES_DES_BARRES)
    })
})
