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
import { ID_DE_LA_RAISON_DES_ECRITURES } from '@/composables/useCopieHorsLigne'

// La mise en page n'a rien à montrer ici : les préréglages seuls comptent.
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
 * Le minuteur d'intervalles servi depuis sa copie hors ligne, puis rechargé
 * en entier au retour du réseau (#2048, partie E, seconde relecture du
 * 2026-10-18), avec le vrai client d'Inertia, la vraie page, le vrai service
 * des copies et le magasin chiffré sur une base en mémoire.
 *
 * `router.reload` garde l'état de la page. « Modifier le minuteur », resté
 * ouvert sur la copie, remplissait le formulaire des valeurs du préréglage de
 * la copie ; le réseau revenu, la liste montrait le préréglage du serveur, et
 * « Mettre à jour » renvoyait pourtant celles de la copie, par-dessus lui.
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
 * Le minuteur d'intervalles, avec les préréglages du compte.
 *
 * @param {Array<object>} timers
 */
const pageDuMinuteur = (timers) =>
    pageDe({ url: '/tools/interval-timer', component: 'Tools/IntervalTimer', props: { timers } })

/** Une page de l'application, d'où part la visite. */
const Outils = { name: 'Outils', render: () => h('p', { id: 'outils' }, 'Outils') }

let routeur
let magasin
let application
let relais
let pageInertia

const bouton = (texte) => [...document.querySelectorAll('button')].find((b) => b.textContent.trim() === texte)
const parLibelle = (libelle) =>
    [...document.querySelectorAll('button')].find((b) => b.getAttribute('aria-label') === libelle)
const champDuNom = () => document.querySelector('input[placeholder="ex. Tabata"]')
const titreDuFormulaire = () => document.querySelector('h3')?.textContent.trim()

beforeAll(async () => {
    vi.stubGlobal('XMLHttpRequest', RequeteSimulee)
    vi.spyOn(window, 'scrollTo').mockImplementation(() => {})
    document.body.innerHTML = '<div id="app"></div>'
    window.history.replaceState(null, '', '/tools')
    globalThis.route = (nom, parametre) => `/${nom}${parametre === undefined ? '' : `/${parametre}`}`
    localStorage.setItem(CLEF_DU_TITULAIRE_DE_L_APPAREIL, `compte:${COMPTE}`)
    document.cookie = `gt_copies=${cleAuHasard()}; path=/`

    const { creerLeMagasinDesCopies, ouvrirLaBaseDuNavigateur } = await import('@/Utils/copiesHorsLigne')
    const fabrique = creerUneFabriqueEnMemoire()
    magasin = creerLeMagasinDesCopies({ ouvrirLaBase: () => ouvrirLaBaseDuNavigateur(fabrique) })

    const visites = await import('@/Utils/visitesHorsLigne')
    const inertia = await import('@inertiajs/vue3')
    const { default: Minuteur } = await import('@/Pages/Tools/IntervalTimer.vue')
    routeur = inertia.router

    visites.installerLesVisitesHorsLigne({ routeur, lireUneCopie: (adresse) => magasin.lireUneCopie(adresse) })

    for (const evenement of ['navigate', 'clientVisit', 'success']) {
        routeur.on(evenement, (detail) => (pageInertia = detail.detail.page))
    }

    await inertia.createInertiaApp({
        page: pageDe({ url: '/tools', component: 'Tools/Index' }),
        resolve: (nom) => (nom === 'Tools/IntervalTimer' ? Minuteur : Outils),
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

it('un préréglage ne s’ouvre pas en modification sur la copie, et la mise à jour d’après part avec les valeurs du serveur', async () => {
    const deLaCopie = {
        id: 7,
        name: 'Sprint de la copie',
        work_seconds: 3,
        rest_seconds: 2,
        rounds: 2,
        warmup_seconds: 2,
    }
    const duServeur = {
        id: 7,
        name: 'Sprint du serveur',
        work_seconds: 40,
        rest_seconds: 20,
        rounds: 10,
        warmup_seconds: 5,
    }

    expect(await magasin.ecrireUneCopie(pageDuMinuteur([deLaCopie]))).toBe('ecrite')

    // Sans réseau, le lien ouvre la copie.
    reseau.mode = 'coupe'
    routeur.visit('/tools/interval-timer')
    await vi.waitUntil(() => pageInertia?.props?.horsLigne !== undefined && bouton('Préréglages') !== undefined, {
        timeout: 3000,
    })

    bouton('Préréglages').click()
    await flushPromises()
    const nomSurLaCopie = champDuNom()

    // La personne touche « Modifier le minuteur » sur la copie : le formulaire n'en prend rien.
    parLibelle('Modifier le minuteur').click()
    await flushPromises()

    expect(titreDuFormulaire()).toBe('Nouveau minuteur')
    expect(champDuNom().value).toBe('Tabata')
    expect(parLibelle('Modifier le minuteur').disabled).toBe(true)
    expect(parLibelle('Modifier le minuteur').getAttribute('aria-describedby')).toBe(ID_DE_LA_RAISON_DES_ECRITURES)

    // Le réseau revient ; ailleurs, le préréglage a changé.
    reseau.mode = 'repond'
    reseau.reponse = reponseInertia(pageDuMinuteur([duServeur]))
    const { noterQueLeServeurRepond } = await import('@/composables/useEtatDuReseau')
    noterQueLeServeurRepond()
    await vi.waitUntil(
        () => pageInertia?.props?.horsLigne === undefined && !parLibelle('Modifier le minuteur').disabled,
        { timeout: 3000 },
    )
    await flushPromises()

    // La page n'a pas été remontée, et la liste montre le serveur ; le formulaire n'a rien de la copie.
    expect(champDuNom()).toBe(nomSurLaCopie)
    expect(document.body.textContent).toContain('Sprint du serveur')
    expect(titreDuFormulaire()).toBe('Nouveau minuteur')
    expect(champDuNom().value).toBe('Tabata')

    // Le préréglage s'ouvre maintenant depuis la page du serveur, et part tel qu'elle le montre.
    parLibelle('Modifier le minuteur').click()
    await flushPromises()
    expect(champDuNom().value).toBe('Sprint du serveur')

    const depuis = requetes.length
    let envoye = false
    const arreter = routeur.on('finish', (evenement) => {
        envoye ||= evenement.detail.visit.method === 'patch'
    })

    try {
        bouton('Mettre à jour').click()
        await vi.waitUntil(() => envoye, { timeout: 3000 })
    } finally {
        arreter()
    }

    const envois = requetes.slice(depuis).filter((requete) => requete.methode.toUpperCase() !== 'GET')

    expect(envois).toHaveLength(1)
    expect(new URL(envois[0].url, window.location.href).pathname).toBe('/tools.interval-timer.update/7')
    expect(JSON.parse(envois[0].corps)).toEqual(duServeur)

    await uneTache()
    expect(relais.rejets).toEqual([])
})
