import { afterAll, beforeAll, expect, it, vi } from 'vitest'
import { createApp, h } from 'vue'
import { flushPromises } from '@vue/test-utils'

import {
    RequeteSimulee,
    relayerLesRejetsNonGeres,
    reponseInertia,
    requetes,
    reseau,
    uneTache,
} from '../utils/reseauSimule'

// La mise en page n'a rien à montrer ici : le formulaire du jeûne seul compte.
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
 * Le graphique de l'historique n'a rien à voir avec ce qui se joue ici, et il
 * chargerait Chart.js. `defineAsyncComponent` ne prend `default` que d'un
 * module qui se dit module (`__esModule`).
 */
vi.mock('@/Components/Stats/FastingHistoryChart.vue', () => ({
    __esModule: true,
    default: { name: 'FastingHistoryChart', render: () => null },
}))

/*
 * Commencer, terminer, puis recommencer sur la page restée ouverte, avec le
 * vrai client d'Inertia et la vraie page (#2111).
 *
 * `useForm` envoie par `router.post` et `router.patch`, qui gardent l'état :
 * la page n'est jamais remontée, et le champ « Début », rempli à son seul
 * montage, gardait l'heure du premier jeûne. Restée ouverte entre deux jeûnes
 * (la PWA gardée en mémoire), elle envoyait au « Commencer » suivant le début
 * du jeûne précédent, d'avant sa fin : le serveur l'acceptait, le nouveau
 * jeûne chevauchait l'ancien, et la page affichait aussitôt « 24:00:00 » et
 * « Objectif atteint ! ».
 *
 * Seule l'horloge `Date` est simulée : le client d'Inertia garde ses vraies
 * minuteries. Le client ne démarre qu'une fois par fichier : un fichier, un test.
 */

const MONTAGE = new Date('2026-03-09T19:00:00Z')
const FIN = new Date('2026-03-10T11:00:00Z')
const SECOND_GESTE = new Date('2026-03-10T19:00:00Z')

/** L'heure murale de l'appareil, à la minute, rebâtie de l'API `Date` : la forme d'un champ `datetime-local`. */
const pad = (valeur) => String(valeur).padStart(2, '0')
const heureDuChamp = (date) =>
    `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}` +
    `T${pad(date.getHours())}:${pad(date.getMinutes())}`

const historiqueVide = { data: [], current_page: 1, last_page: 1, prev_page_url: null, next_page_url: null }

/**
 * La page du jeûne telle que le serveur la rend.
 *
 * @param {{ activeFast?: object|null, history?: object }} props
 */
const pageDuJeune = ({ activeFast = null, history = historiqueVide } = {}) => ({
    component: 'Tools/Fasting/Index',
    url: '/tools/fasting',
    version: 'version-de-la-page',
    encryptHistory: false,
    clearHistory: false,
    props: { errors: {}, auth: { user: { id: 7, name: 'Camille Jeune' } }, activeFast, history },
})

/** Le jeûne de seize heures que le serveur sert pour un début reçu à `debut`, sous la forme d'Eloquent. */
const jeuneEnCours = (id, debut) => ({
    id,
    type: '16:8',
    start_time: debut.toISOString().replace('Z', '000Z'),
    end_time: null,
    target_duration_minutes: 16 * 60,
    status: 'active',
})

let routeur
let application
let relais

const champDuDebut = () => document.querySelector('input[type="datetime-local"]')
/** Un titre que la page garde affiché du premier jeûne au second : le même élément tant qu'elle n'est pas remontée. */
const titreDeLHistorique = () =>
    [...document.querySelectorAll('h3')].find((titre) => titre.textContent.trim() === 'Historique détaillé')
const boutonTerminer = () =>
    [...document.querySelectorAll('button')].find((bouton) => bouton.textContent.includes('Terminer le jeûne'))

/**
 * Joue un geste qui écrit, et attend que la page servie en réponse soit posée.
 *
 * @param {() => void} geste
 * @param {object} reponse la page que le serveur rend après l'écriture
 * @returns {Promise<{ methode: string, adresse: string, corps: Record<string, unknown> }>} l'écriture partie
 */
const ecrire = async (geste, reponse) => {
    const depuis = requetes.length
    let finie = false
    const arreter = routeur.on('finish', () => {
        finie = true
    })

    reseau.reponse = reponseInertia(reponse)

    try {
        geste()
        await vi.waitUntil(() => finie, { timeout: 3000 })
    } finally {
        arreter()
    }

    await flushPromises()

    const parties = requetes.slice(depuis)

    expect(parties).toHaveLength(1)

    return {
        methode: parties[0].methode.toUpperCase(),
        adresse: new URL(parties[0].url, window.location.href).pathname,
        corps: JSON.parse(parties[0].corps),
    }
}

beforeAll(async () => {
    vi.useFakeTimers({ toFake: ['Date'] })
    vi.setSystemTime(MONTAGE)
    vi.stubGlobal('XMLHttpRequest', RequeteSimulee)
    // jsdom ne fait pas défiler la page, et le dit à chaque visite.
    vi.spyOn(window, 'scrollTo').mockImplementation(() => {})
    document.body.innerHTML = '<div id="app"></div>'
    window.history.replaceState(null, '', '/tools/fasting')
    globalThis.route = (nom, parametre) => `/${nom}${parametre === undefined ? '' : `/${parametre}`}`
    reseau.mode = 'repond'
    reseau.prochains.length = 0

    const inertia = await import('@inertiajs/vue3')
    const { default: Jeune } = await import('@/Pages/Tools/Fasting/Index.vue')
    routeur = inertia.router

    await inertia.createInertiaApp({
        page: pageDuJeune(),
        resolve: () => Jeune,
        setup: ({ el, App, props, plugin }) => {
            // Comme `main.js` : la directive des appuis, ici sans effet.
            application = createApp({ render: () => h(App, props) })
                .use(plugin)
                .directive('press', {})
            application.mount(el)
        },
    })

    await vi.waitUntil(() => champDuDebut() !== null)
    relais = relayerLesRejetsNonGeres()
})

afterAll(async () => {
    relais.retirer()
    routeur.cancelAll()
    await uneTache()
    application.unmount()
    vi.useRealTimers()
    vi.unstubAllGlobals()
    vi.restoreAllMocks()
})

it('recommence à l’instant du second geste, sur la page restée montée depuis le premier jeûne', async () => {
    const titre = titreDeLHistorique()

    expect(champDuDebut().value).toBe(heureDuChamp(MONTAGE))

    // `vi.waitUntil` avance l'horloge simulée à chaque attente (`.ai/rules/tests-js.md`) : le geste se joue à l'heure dite.
    vi.setSystemTime(MONTAGE)
    const premier = await ecrire(
        () => document.querySelector('form').dispatchEvent(new Event('submit', { bubbles: true, cancelable: true })),
        pageDuJeune({ activeFast: jeuneEnCours(1, MONTAGE) }),
    )

    expect(premier).toMatchObject({ methode: 'POST', adresse: '/tools.fasting.store' })
    expect(premier.corps.start_time).toBe(MONTAGE.toISOString())

    // Seize heures plus tard, la personne termine le jeûne.
    vi.setSystemTime(FIN)
    const termine = { ...jeuneEnCours(1, MONTAGE), end_time: '2026-03-10T11:00:00.000000Z', status: 'completed' }
    const fin = await ecrire(
        () => boutonTerminer().click(),
        pageDuJeune({ history: { ...historiqueVide, data: [termine] } }),
    )

    expect(fin).toMatchObject({ methode: 'PATCH', adresse: '/tools.fasting.update/1' })
    expect(fin.corps.end_time).toBe(FIN.toISOString())

    // La page n'a pas été remontée, et le formulaire reparaît avec l'heure où
    // il reparaît, et non plus celle du premier jeûne.
    expect(titreDeLHistorique()).toBe(titre)
    expect(champDuDebut().value).toBe(heureDuChamp(FIN))

    // Huit heures plus tard, sur la même page, elle recommence sans toucher au champ.
    vi.setSystemTime(SECOND_GESTE)
    const second = await ecrire(
        () => document.querySelector('form').dispatchEvent(new Event('submit', { bubbles: true, cancelable: true })),
        pageDuJeune({ activeFast: jeuneEnCours(2, SECOND_GESTE), history: { ...historiqueVide, data: [termine] } }),
    )

    expect(second).toMatchObject({ methode: 'POST', adresse: '/tools.fasting.store' })
    expect(titreDeLHistorique()).toBe(titre)
    expect(second.corps.start_time).toBe(SECOND_GESTE.toISOString())
    expect(Date.parse(second.corps.start_time)).toBeGreaterThan(Date.parse(termine.end_time))

    await uneTache()
    expect(relais.rejets).toEqual([])
})
