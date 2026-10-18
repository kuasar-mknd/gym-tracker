import { afterAll, beforeAll, expect, it, vi } from 'vitest'
import { createApp, h } from 'vue'
import { flushPromises } from '@vue/test-utils'

import { creerUneFabriqueEnMemoire } from '../utils/baseIndexeeEnMemoire'
import {
    RequeteSimulee,
    relayerLesRejetsNonGeres,
    reponseInertia,
    requetes,
    reseau,
    uneTache,
} from '../utils/reseauSimule'
import { CLEF_DU_TITULAIRE_DE_L_APPAREIL } from '@/Utils/historiqueDuCompte'

// La mise en page n'a rien à montrer ici : le formulaire des paliers seul compte.
vi.mock('@/Layouts/AuthenticatedLayout.vue', async () => {
    const { h: rendre } = await import('vue')

    return {
        default: {
            name: 'AuthenticatedLayout',
            setup:
                (_props, { slots }) =>
                () =>
                    rendre('div', slots.default?.()),
        },
    }
})

/*
 * Le calculateur d'échauffement servi depuis sa copie hors ligne, puis
 * rechargé en entier au retour du réseau (#2048, partie E), avec le vrai
 * client d'Inertia, la vraie page, le vrai service des copies et le magasin
 * chiffré sur une base en mémoire.
 *
 * `router.reload` garde l'état de la page : le formulaire des paliers, rempli
 * au montage depuis les préférences de la copie, les gardait, et
 * « Enregistrer » renvoyait au serveur l'arrondi et les paliers de la copie
 * alors que la page montrait ceux du serveur. Ce que la personne a changé sur
 * la copie, elle, reste : elle le voit, et l'enregistre ainsi.
 *
 * Le client d'Inertia ne démarre qu'une fois par fichier : un fichier, un test.
 */

const COMPTE = 7

/** Une clé de 32 octets tirés au hasard, en base64url, comme le serveur la tire. */
const cleAuHasard = () =>
    btoa(String.fromCharCode(...crypto.getRandomValues(new Uint8Array(32))))
        .replace(/\+/g, '-')
        .replace(/\//g, '_')
        .replace(/=+$/, '')

/**
 * Une page du compte, telle que le serveur la rend en entier.
 *
 * @param {{ url: string, component: string, props?: object }} page
 */
const pageDe = ({ url, component, props = {} }) => ({
    component,
    url,
    version: 'version-de-la-page',
    encryptHistory: true,
    clearHistory: false,
    props: {
        errors: {},
        auth: { user: { id: COMPTE, name: 'Camille Horsligne', active_workout: null, latest_achievement: null } },
        ...props,
    },
})

/**
 * Le calculateur d'échauffement, avec les préférences du compte.
 *
 * @param {{ bar_weight: number, rounding_increment: number, steps: Array<object> }} preference
 */
const pageDEchauffement = (preference) =>
    pageDe({ url: '/tools/warmup', component: 'Tools/WarmupCalculator', props: { preference } })

/** Une page de l'application, d'où part la visite. */
const Outils = { name: 'Outils', render: () => h('p', { id: 'outils' }, 'Outils') }

let routeur
let magasin
let application
let relais
let pageInertia

const champ = (nom) => document.querySelector(`input[aria-label="${nom}"]`)
const champDeLaBarre = () => document.querySelector('#warmup-bar-weight')
const boutonEnregistrer = () =>
    [...document.querySelectorAll('button')].find((bouton) => bouton.textContent.includes('Enregistrer'))

/**
 * Tape une valeur dans un champ, comme au clavier.
 *
 * @param {HTMLInputElement} element
 * @param {string} valeur
 */
const taper = async (element, valeur) => {
    element.value = valeur
    element.dispatchEvent(new Event('input'))
    await flushPromises()
}

beforeAll(async () => {
    vi.stubGlobal('XMLHttpRequest', RequeteSimulee)
    vi.spyOn(window, 'scrollTo').mockImplementation(() => {})
    document.body.innerHTML = '<div id="app"></div>'
    window.history.replaceState(null, '', '/tools')
    globalThis.route = (nom) => `/${nom}`
    localStorage.setItem(CLEF_DU_TITULAIRE_DE_L_APPAREIL, `compte:${COMPTE}`)
    document.cookie = `gt_copies=${cleAuHasard()}; path=/`

    const { creerLeMagasinDesCopies, ouvrirLaBaseDuNavigateur } = await import('@/Utils/copiesHorsLigne')
    const fabrique = creerUneFabriqueEnMemoire()
    magasin = creerLeMagasinDesCopies({ ouvrirLaBase: () => ouvrirLaBaseDuNavigateur(fabrique) })

    const visites = await import('@/Utils/visitesHorsLigne')
    const inertia = await import('@inertiajs/vue3')
    const { default: Echauffement } = await import('@/Pages/Tools/WarmupCalculator.vue')
    routeur = inertia.router

    visites.installerLesVisitesHorsLigne({ routeur, lireUneCopie: (adresse) => magasin.lireUneCopie(adresse) })

    for (const evenement of ['navigate', 'clientVisit', 'success']) {
        routeur.on(evenement, (detail) => (pageInertia = detail.detail.page))
    }

    await inertia.createInertiaApp({
        page: pageDe({ url: '/tools', component: 'Tools/Index' }),
        resolve: (nom) => (nom === 'Tools/WarmupCalculator' ? Echauffement : Outils),
        setup: ({ el, App, props, plugin }) => {
            // Comme `main.js` : la directive des appuis, ici sans effet.
            application = createApp({ render: () => h(App, props) })
                .use(plugin)
                .directive('press', {})
            application.mount(el)
        },
    })

    await vi.waitUntil(() => pageInertia !== undefined)
    relais = relayerLesRejetsNonGeres()
})

afterAll(async () => {
    relais.retirer()
    routeur.cancelAll()
    await uneTache()
    application.unmount()
    await magasin.fermer()
    vi.unstubAllGlobals()
    vi.restoreAllMocks()
})

it('rechargé au retour du réseau, il enregistre les préférences du serveur, et ce que la personne a changé sur la copie', async () => {
    expect(
        await magasin.ecrireUneCopie(
            pageDEchauffement({
                bar_weight: 20,
                rounding_increment: 2.5,
                steps: [{ percent: 50, reps: 5, label: 'Palier de la copie' }],
            }),
        ),
    ).toBe('ecrite')

    // Sans réseau, le lien ouvre la copie : enregistrer y attend le réseau.
    reseau.mode = 'coupe'
    routeur.visit('/tools/warmup')
    await vi.waitUntil(() => pageInertia?.props?.horsLigne !== undefined && champDeLaBarre() !== null, {
        timeout: 3000,
    })

    expect(champ('Label, palier 1').value).toBe('Palier de la copie')
    expect(boutonEnregistrer().disabled).toBe(true)

    // Sur la copie, la personne règle le poids de sa barre pour son calcul.
    const barreSurLaCopie = champDeLaBarre()
    await taper(barreSurLaCopie, '25')

    // Le réseau revient ; ailleurs, l'arrondi et les paliers ont changé, et la barre aussi.
    const paliersDuServeur = [
        { percent: 40, reps: 8, label: 'Barre vide' },
        { percent: 60, reps: 5, label: '' },
    ]
    reseau.mode = 'repond'
    reseau.reponse = reponseInertia(
        pageDEchauffement({ bar_weight: 15, rounding_increment: 1, steps: paliersDuServeur }),
    )
    const { noterQueLeServeurRepond } = await import('@/composables/useEtatDuReseau')
    noterQueLeServeurRepond()
    await vi.waitUntil(() => pageInertia?.props?.horsLigne === undefined && !boutonEnregistrer().disabled, {
        timeout: 3000,
    })
    await flushPromises()

    // La page n'a pas été remontée : le formulaire est celui de la copie, recalé.
    expect(champDeLaBarre()).toBe(barreSurLaCopie)
    expect(champ('Label, palier 1').value).toBe('Barre vide')
    expect(champ('Label, palier 2')).not.toBeNull()
    expect(champDeLaBarre().value).toBe('25')

    // « Enregistrer », une fois la page rechargée.
    const depuis = requetes.length
    let enregistre = false
    const arreter = routeur.on('finish', (evenement) => {
        enregistre ||= evenement.detail.visit.method === 'post'
    })

    try {
        boutonEnregistrer().click()
        await vi.waitUntil(() => enregistre, { timeout: 3000 })
    } finally {
        arreter()
    }

    const envois = requetes.slice(depuis).filter((requete) => requete.methode.toUpperCase() === 'POST')

    expect(envois).toHaveLength(1)
    expect(new URL(envois[0].url, window.location.href).pathname).toBe('/tools.warmup.update')
    expect(JSON.parse(envois[0].corps)).toEqual({ bar_weight: 25, rounding_increment: 1, steps: paliersDuServeur })

    await uneTache()
    expect(relais.rejets).toEqual([])
})
