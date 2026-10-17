import { describe, it, expect, vi, beforeAll, beforeEach, afterEach, afterAll } from 'vitest'
import { createApp, h } from 'vue'
import { ordreRetabli, retirerDeLaListe, revenirSiLaVisiteEchoue, siLaVisiteAboutit } from '@/Utils/miseAJourOptimiste'
import { RequeteSimulee, chargementsCompletsPendant, reponseInertia, requetes, reseau, uneTache } from './reseauSimule'

/*
 * L'utilitaire des mises à jour optimistes (#2077), avec le vrai client
 * d'Inertia : `revenir` part une fois sur toute issue qui n'est pas un succès,
 * jamais sur un succès ; `aboutir` part une fois sur un succès ou un
 * rechargement complet, jamais sur un échec. Les pages qui s'en servent sont
 * jouées dans `tests/js/pages/misesAJourOptimistesAvecInertia.test.js`, la
 * déconnexion et la suppression du compte dans
 * `tests/js/composables/deconnexionEffaceLaFileAvecInertia.test.js`.
 */

/** La page de la liste, telle que le serveur l'a rendue. */
const pageDeLaListe = (elements = [1, 2, 3]) => ({
    component: 'Liste',
    url: '/liste',
    version: 'v',
    props: { errors: {}, elements, graphiques: null },
})

let routeur

const contenu = () => document.getElementById('contenu').textContent

beforeAll(async () => {
    vi.stubGlobal('XMLHttpRequest', RequeteSimulee)
    // jsdom ne fait pas défiler la page, et le dit à chaque visite.
    vi.spyOn(window, 'scrollTo').mockImplementation(() => {})
    document.body.innerHTML = '<div id="app"></div>'
    window.history.replaceState(null, '', '/liste')

    const inertia = await import('@inertiajs/vue3')
    routeur = inertia.router

    const Liste = {
        props: ['elements'],
        render() {
            return h('p', { id: 'contenu' }, this.elements.join(','))
        },
    }

    await inertia.createInertiaApp({
        page: pageDeLaListe(),
        resolve: () => Liste,
        setup: ({ el, App, props, plugin }) =>
            createApp({ render: () => h(App, props) })
                .use(plugin)
                .mount(el),
    })
})

beforeEach(async () => {
    // Une visite du client seul, sans requête : la liste revient telle qu'elle s'est ouverte.
    let remise = false
    routeur.replace({ ...pageDeLaListe(), onFinish: () => (remise = true) })
    await vi.waitUntil(() => remise, { interval: 1 })

    requetes.length = 0
    reseau.mode = 'muet'
    reseau.prochains.length = 0
    reseau.reponse = null
})

afterEach(() => {
    document.getElementById('inertia-error-dialog')?.remove()
})

afterAll(() => {
    vi.unstubAllGlobals()
    vi.restoreAllMocks()
})

const pageDErreur = (statut) => ({
    statut,
    entetes: { 'content-type': 'text/html; charset=UTF-8' },
    corps: `<!DOCTYPE html><html><body><h1>${statut}</h1></body></html>`,
})

/** Lance une suppression enveloppée, et rend ce que ses rappels ont vu. */
const supprimer = (options = {}) => {
    const vu = { retours: 0, succes: 0, fins: 0 }

    routeur.delete(
        '/liste/2',
        revenirSiLaVisiteEchoue(() => (vu.retours += 1), {
            onHttpException: () => false,
            onNetworkError: () => false,
            ...options,
            onSuccess: () => (vu.succes += 1),
            onFinish: () => (vu.fins += 1),
        }),
    )

    return vu
}

/**
 * Un rechargement en arrière-plan (`async`), renvoyé vers un chargement
 * complet (409 `X-Inertia-Location`, version des actifs changée) : Inertia
 * émet `location` sans recharger la page. Ce renvoi n'est celui d'aucune
 * visite enveloppée.
 */
const renvoyerUnRechargementEnArrierePlan = async () => {
    routeur.reload({ only: ['graphiques'] })
    await uneTache()
    requetes.at(-1).repondre({ statut: 409, entetes: { 'x-inertia-location': '/liste', 'x-inertia-version': 'v2' } })
    await uneTache()
    await uneTache()
    window.sessionStorage.removeItem('inertiaLocationVisit')
}

describe('revenirSiLaVisiteEchoue, avec le vrai client d’Inertia', () => {
    it.each([
        ['sans réseau', (requete) => requete.onerror()],
        ['sur un 500', (requete) => requete.repondre(pageDErreur(500))],
        ['sur un 403', (requete) => requete.repondre(pageDErreur(403))],
        ['sur un 404', (requete) => requete.repondre(pageDErreur(404))],
        ['sur un 419', (requete) => requete.repondre(pageDErreur(419))],
        ['sur un 429', (requete) => requete.repondre(pageDErreur(429))],
        ['sur un 503', (requete) => requete.repondre(pageDErreur(503))],
        [
            'sur un refus de validation',
            (requete) =>
                requete.repondre(
                    reponseInertia({ ...pageDeLaListe(), props: { elements: [1, 2, 3], errors: { nom: 'Requis.' } } }),
                ),
        ],
        ['quand la visite est annulée', () => routeur.cancelAll()],
        ['quand une autre visite l’interrompt', () => routeur.visit('/ailleurs')],
    ])('revient une fois %s, après les rappels de la visite', async (_cas, echouer) => {
        const vu = supprimer()
        await uneTache()

        echouer(requetes[0])
        await vi.waitUntil(() => vu.fins === 1)
        routeur.cancelAll()
        await uneTache()

        expect(vu).toEqual({ retours: 1, succes: 0, fins: 1 })
    })

    it('ne revient pas quand la visite réussit', async () => {
        const vu = supprimer()
        await uneTache()

        requetes[0].repondre(reponseInertia(pageDeLaListe([1, 3])))
        await vi.waitUntil(() => vu.fins === 1)

        expect(vu).toEqual({ retours: 0, succes: 1, fins: 1 })
        expect(contenu()).toBe('1,3')
    })

    /*
     * Un écouteur `before` qui annule arrête la visite sans aucun rappel,
     * `onFinish` compris : rien n'est parti, l'écran revient.
     */
    it('revient quand un écouteur `before` arrête la visite avant son départ', async () => {
        const retirer = routeur.on('before', () => false)

        const vu = supprimer()
        retirer()
        await uneTache()

        expect(requetes).toEqual([])
        expect(vu).toEqual({ retours: 1, succes: 0, fins: 0 })
    })

    it('revient quand le `onBefore` de la visite l’arrête, et lui rend son verdict', async () => {
        const vu = supprimer({ onBefore: () => false })
        await uneTache()

        expect(requetes).toEqual([])
        expect(vu.retours).toBe(1)
    })

    it('ne revient pas quand un écouteur `before` laisse partir la visite', async () => {
        const retirer = routeur.on('before', () => true)

        const vu = supprimer()
        retirer()
        await uneTache()

        expect(requetes).toHaveLength(1)
        expect(vu.retours).toBe(0)

        routeur.cancelAll()
        await uneTache()
        expect(vu.retours).toBe(1)
    })

    /*
     * Une redirection vers une adresse à fragment répond 409 `X-Inertia-Redirect` :
     * Inertia relance la visite avec les mêmes rappels, et la première finit
     * avant que la seconde réponde. Rien ne revient avant la fin de la seconde.
     */
    it.each([
        ['réussit', reponseInertia(pageDeLaListe([1, 3])), 0],
        ['échoue', pageDErreur(500), 1],
    ])('attend la fin de la visite relancée par un 409, qui %s', async (_cas, reponse, retours) => {
        const vu = supprimer()
        await uneTache()

        requetes[0].repondre({ statut: 409, entetes: { 'x-inertia-redirect': '/liste#fin' } })
        await vi.waitUntil(() => requetes.length === 2)

        expect(vu.retours).toBe(0)

        requetes[1].repondre(reponse)
        await vi.waitUntil(() => vu.fins === 2)

        expect(vu.retours).toBe(retours)
    })

    /*
     * La visite relancée interrompt la première : Inertia appelle le
     * `onFinish` de celle-ci avec `interrupted`, avant que la seconde
     * réponde. Cette interruption n'est pas un échec : la seconde, renvoyée
     * vers un chargement complet (la version des actifs a changé), ne fait
     * rien revenir.
     */
    it('ne revient pas quand la visite relancée par un 409 `X-Inertia-Redirect` recharge toute la page', async () => {
        const vu = supprimer()
        await uneTache()

        requetes[0].repondre({ statut: 409, entetes: { 'x-inertia-redirect': '/liste#fin' } })
        await vi.waitUntil(() => requetes.length === 2)

        const adresse = new URL('/liste', window.location.href).href
        const chargements = await chargementsCompletsPendant(async () => {
            requetes[1].repondre({ statut: 409, entetes: { 'x-inertia-location': adresse, 'x-inertia-version': 'v2' } })
            await vi.waitUntil(() => vu.fins === 2)
        })

        expect(chargements).toEqual([adresse])
        expect(vu).toEqual({ retours: 0, succes: 0, fins: 2 })
    })

    /*
     * Une autre visite qui interrompt la relance fait échouer la suppression,
     * elle : l'écran revient, même quand un rechargement en arrière-plan a
     * été renvoyé vers un chargement complet pendant ce temps.
     */
    it('revient quand une autre visite interrompt la visite relancée par un 409 `X-Inertia-Redirect`', async () => {
        const vu = supprimer()
        await uneTache()

        requetes[0].repondre({ statut: 409, entetes: { 'x-inertia-redirect': '/liste#fin' } })
        await vi.waitUntil(() => requetes.length === 2)
        await renvoyerUnRechargementEnArrierePlan()

        routeur.visit('/ailleurs')
        await vi.waitUntil(() => vu.fins === 2)
        routeur.cancelAll()
        await uneTache()

        expect(vu).toEqual({ retours: 1, succes: 0, fins: 2 })
    })

    /*
     * Une suppression faite juste après une mise en production : le DELETE
     * répond 303, et le GET qui le suit 409 `X-Inertia-Location`, la version
     * des actifs ayant changé. Inertia recharge toute la page sans appeler
     * `onSuccess` ni aucun rappel d'échec, puis appelle `onFinish`. Ce n'est
     * pas un échec : le serveur a répondu, et la page rechargée montre ce
     * qu'il a fait. jsdom ne navigue pas : le rechargement se compte sur une
     * `window.location` simulée le temps de la réponse.
     */
    it('ne revient pas quand la réponse recharge toute la page (409 X-Inertia-Location)', async () => {
        const vu = supprimer()
        await uneTache()

        const adresse = new URL('/liste', window.location.href).href
        const chargements = await chargementsCompletsPendant(async () => {
            requetes[0].repondre({ statut: 409, entetes: { 'x-inertia-location': adresse, 'x-inertia-version': 'v2' } })
            await vi.waitUntil(() => vu.fins === 1)
        })

        expect(chargements).toEqual([adresse])
        expect(vu).toEqual({ retours: 0, succes: 0, fins: 1 })
    })

    it('ne revient pas non plus quand un écouteur `location` retient ce rechargement', async () => {
        const renvois = []
        const retirer = routeur.on('location', (evenement) => {
            renvois.push(evenement.detail.url.pathname)

            return false
        })

        const vu = supprimer()
        await uneTache()

        requetes[0].repondre({ statut: 409, entetes: { 'x-inertia-location': '/liste' } })
        await vi.waitUntil(() => vu.fins === 1)
        retirer()

        expect(renvois).toEqual(['/liste'])
        expect(vu).toEqual({ retours: 0, succes: 0, fins: 1 })
    })

    /*
     * Le même renvoi, reçu par une autre visite : après une mise en
     * production, un rechargement en arrière-plan (`async`) répond 409
     * `X-Inertia-Location`, et Inertia émet `location` sans recharger la page
     * (version changée sur une visite asynchrone). La suppression en vol, qui
     * échoue ensuite, revient : ce renvoi n'était pas le sien.
     */
    it.each([
        ['sans réseau', (requete) => requete.onerror()],
        ['sur un 500', (requete) => requete.repondre(pageDErreur(500))],
        ['quand la visite est annulée', () => routeur.cancelAll()],
    ])('revient %s, même quand une autre visite a été renvoyée vers un chargement complet', async (_cas, echouer) => {
        const vu = supprimer()
        await uneTache()
        await renvoyerUnRechargementEnArrierePlan()

        echouer(requetes[0])
        await vi.waitUntil(() => vu.fins === 1)

        expect(vu).toEqual({ retours: 1, succes: 0, fins: 1 })
    })

    it('garde les autres options et les rappels de l’appelant', () => {
        const onBefore = vi.fn(() => true)
        const options = revenirSiLaVisiteEchoue(() => {}, { preserveScroll: true, only: ['a'], onBefore })

        expect(options).toMatchObject({ preserveScroll: true, only: ['a'] })
        expect(options.onBefore({})).toBe(true)
        expect(onBefore).toHaveBeenCalledOnce()
    })

    /*
     * `onHttpException` et `onNetworkError` rendent `false` pour taire la
     * fenêtre d'erreur ou le filet des visites : l'enveloppe rend ce que rend
     * le rappel de l'appelant.
     */
    it.each(['onError', 'onHttpException', 'onNetworkError'])('rend ce que rend le `%s` de l’appelant', (nom) => {
        const rappel = vi.fn(() => false)
        const options = revenirSiLaVisiteEchoue(() => {}, { [nom]: rappel })

        expect(options[nom]('détail')).toBe(false)
        expect(rappel).toHaveBeenCalledWith('détail')
        expect(revenirSiLaVisiteEchoue(() => {})[nom]('détail')).toBeUndefined()
    })

    it('revient sur une fin sans succès quand un faux routeur n’appelle que `onFinish`', () => {
        const revenir = vi.fn()

        revenirSiLaVisiteEchoue(revenir).onFinish()
        expect(revenir).toHaveBeenCalledOnce()

        const reussie = revenirSiLaVisiteEchoue(revenir)
        reussie.onSuccess()
        reussie.onFinish()
        expect(revenir).toHaveBeenCalledOnce()
    })
})

/** Lance une visite enveloppée par `siLaVisiteAboutit`, et rend ce que ses rappels ont vu, dans l'ordre. */
const envoyer = (options = {}) => {
    const vu = []

    routeur.delete(
        '/liste/2',
        siLaVisiteAboutit(() => vu.push('aboutie'), {
            onHttpException: () => false,
            onNetworkError: () => false,
            ...options,
            onSuccess: () => vu.push('succès'),
            onFinish: () => vu.push('fin'),
        }),
    )

    return vu
}

/*
 * Le pendant de `revenirSiLaVisiteEchoue`, pour ce que la page rechargée ne
 * ferait pas : la déconnexion et la suppression du compte effacent ce que
 * l'appareil garde pour lui. Dans `onSuccess` seul, une réponse qui recharge
 * toute la page, la session pourtant fermée, le laissait sur l'appareil.
 */
describe('siLaVisiteAboutit, avec le vrai client d’Inertia', () => {
    it('aboutit une fois sur un succès, avant le `onSuccess` de l’appelant', async () => {
        const vu = envoyer()
        await uneTache()

        requetes[0].repondre(reponseInertia(pageDeLaListe([1, 3])))
        await vi.waitUntil(() => vu.includes('fin'))

        expect(vu).toEqual(['aboutie', 'succès', 'fin'])
    })

    /*
     * La redirection qui suit une déconnexion ou une suppression du compte
     * répond 409 `X-Inertia-Location` quand la version des actifs a changé :
     * Inertia recharge toute la page sans `onSuccess`, puis appelle
     * `onFinish`. `aboutir` part avant ce dernier, que la déconnexion lit.
     */
    it('aboutit une fois, avant `onFinish`, quand la réponse recharge toute la page', async () => {
        const vu = envoyer()
        await uneTache()

        const adresse = new URL('/', window.location.href).href
        const chargements = await chargementsCompletsPendant(async () => {
            requetes[0].repondre({ statut: 409, entetes: { 'x-inertia-location': adresse, 'x-inertia-version': 'v2' } })
            await vi.waitUntil(() => vu.includes('fin'))
        })

        expect(chargements).toEqual([adresse])
        expect(vu).toEqual(['aboutie', 'fin'])
    })

    it.each([
        ['sans réseau', (requete) => requete.onerror()],
        ['sur un 500', (requete) => requete.repondre(pageDErreur(500))],
        ['sur un 419', (requete) => requete.repondre(pageDErreur(419))],
        [
            'sur un refus de validation',
            (requete) =>
                requete.repondre(
                    reponseInertia({ ...pageDeLaListe(), props: { elements: [1, 2, 3], errors: { nom: 'Requis.' } } }),
                ),
        ],
        ['quand la visite est annulée', () => routeur.cancelAll()],
        ['quand une autre visite l’interrompt', () => routeur.visit('/ailleurs')],
    ])('n’aboutit pas %s', async (_cas, echouer) => {
        const vu = envoyer()
        await uneTache()

        echouer(requetes[0])
        await vi.waitUntil(() => vu.includes('fin'))
        routeur.cancelAll()
        await uneTache()

        expect(vu).toEqual(['fin'])
    })

    it('n’aboutit pas quand un écouteur `before` arrête la visite avant son départ', async () => {
        const retirer = routeur.on('before', () => false)

        const vu = envoyer()
        retirer()
        await uneTache()

        expect(requetes).toEqual([])
        expect(vu).toEqual([])
    })

    it('n’aboutit pas quand une autre visite a été renvoyée vers un chargement complet et que celle-ci échoue', async () => {
        const vu = envoyer()
        await uneTache()
        await renvoyerUnRechargementEnArrierePlan()

        requetes[0].repondre(pageDErreur(500))
        await vi.waitUntil(() => vu.includes('fin'))

        expect(vu).toEqual(['fin'])
    })

    it('aboutit une fois quand la visite relancée par un 409 `X-Inertia-Redirect` réussit', async () => {
        const vu = envoyer()
        await uneTache()

        requetes[0].repondre({ statut: 409, entetes: { 'x-inertia-redirect': '/liste#fin' } })
        await vi.waitUntil(() => requetes.length === 2)
        requetes[1].repondre(reponseInertia(pageDeLaListe([1, 3])))
        await vi.waitUntil(() => vu.filter((rappel) => rappel === 'fin').length === 2)

        expect(vu.filter((rappel) => rappel === 'aboutie')).toEqual(['aboutie'])
    })

    /*
     * La relance interrompt la première visite, qui finit avec `interrupted`
     * avant que la seconde réponde : ce n'est pas un échec, et la seconde,
     * renvoyée vers un chargement complet, aboutit.
     */
    it('aboutit une fois quand la visite relancée par un 409 `X-Inertia-Redirect` recharge toute la page', async () => {
        const vu = envoyer()
        await uneTache()

        requetes[0].repondre({ statut: 409, entetes: { 'x-inertia-redirect': '/liste#fin' } })
        await vi.waitUntil(() => requetes.length === 2)

        const adresse = new URL('/', window.location.href).href
        const chargements = await chargementsCompletsPendant(async () => {
            requetes[1].repondre({ statut: 409, entetes: { 'x-inertia-location': adresse, 'x-inertia-version': 'v2' } })
            await vi.waitUntil(() => vu.filter((rappel) => rappel === 'fin').length === 2)
        })

        expect(chargements).toEqual([adresse])
        expect(vu).toEqual(['fin', 'aboutie', 'fin'])
    })

    it('n’aboutit pas quand une autre visite interrompt la visite relancée par un 409 `X-Inertia-Redirect`', async () => {
        const vu = envoyer()
        await uneTache()

        requetes[0].repondre({ statut: 409, entetes: { 'x-inertia-redirect': '/liste#fin' } })
        await vi.waitUntil(() => requetes.length === 2)
        await renvoyerUnRechargementEnArrierePlan()

        routeur.visit('/ailleurs')
        await vi.waitUntil(() => vu.filter((rappel) => rappel === 'fin').length === 2)
        routeur.cancelAll()
        await uneTache()

        expect(vu).toEqual(['fin', 'fin'])
    })

    it('aboutit sur `onSuccess` quand un faux routeur l’appelle seul, et une seule fois', () => {
        const aboutir = vi.fn()
        const options = siLaVisiteAboutit(aboutir)

        options.onSuccess()
        options.onFinish()
        expect(aboutir).toHaveBeenCalledOnce()

        siLaVisiteAboutit(aboutir).onFinish()
        expect(aboutir).toHaveBeenCalledOnce()
    })
})

/*
 * Pourquoi pas l'option `optimistic` d'Inertia : sur la version installée,
 * elle revient bien sans réseau et sur une réponse en erreur, mais un
 * rechargement partiel qui répond pendant qu'elle attend recopie la valeur
 * déjà changée dans sa base, et l'échec qui suit ne remet plus rien. Les
 * séances chargent leurs graphiques ainsi, juste après l'ouverture de la
 * page. Si ce cas tombe, une montée d'Inertia l'a corrigé : l'option peut
 * être reconsidérée (`.ai/rules/js.md`).
 */
describe('l’option `optimistic` d’Inertia, sur la version installée', () => {
    const supprimerEnOptimiste = () => {
        const vu = { fins: 0 }

        routeur.delete('/liste/2', {
            optimistic: (props) => ({ elements: props.elements.filter((element) => element !== 2) }),
            onNetworkError: () => false,
            onHttpException: () => false,
            onFinish: () => (vu.fins += 1),
        })

        return vu
    }

    it('revient sans réseau', async () => {
        const vu = supprimerEnOptimiste()
        await uneTache()
        expect(contenu()).toBe('1,3')

        requetes[0].onerror()
        await vi.waitUntil(() => vu.fins === 1)
        await uneTache()

        expect(contenu()).toBe('1,2,3')
    })

    it('ne revient plus quand un rechargement partiel a répondu pendant la suppression', async () => {
        const vu = supprimerEnOptimiste()
        await uneTache()

        routeur.reload({ only: ['graphiques'] })
        await uneTache()
        requetes[1].repondre(reponseInertia({ ...pageDeLaListe(), props: { graphiques: { semaines: [] } } }))
        await uneTache()
        await uneTache()

        requetes[0].repondre(pageDErreur(500))
        await vi.waitUntil(() => vu.fins === 1)
        await uneTache()

        expect(contenu()).toBe('1,3')
    })
})

describe('retirerDeLaListe', () => {
    const liste = (...cles) => cles.map((id) => ({ id }))
    const cles = (elements) => elements.map((element) => element.id)

    it('rend `null` et ne touche à rien quand l’élément n’y est pas', () => {
        const elements = liste(1, 2)

        expect(retirerDeLaListe(() => elements, 9)).toBeNull()
        expect(cles(elements)).toEqual([1, 2])
    })

    it('retire, puis remet à la même place', () => {
        const elements = liste(1, 2, 3)
        const remettre = retirerDeLaListe(() => elements, 2)

        expect(cles(elements)).toEqual([1, 3])
        remettre()
        expect(cles(elements)).toEqual([1, 2, 3])
    })

    it('remet chaque élément à sa place quand deux retraits reviennent dans l’ordre inverse', () => {
        const elements = liste(1, 2, 3, 4)
        const remettre2 = retirerDeLaListe(() => elements, 2)
        const remettre3 = retirerDeLaListe(() => elements, 3)

        remettre3()
        expect(cles(elements)).toEqual([1, 3, 4])
        remettre2()
        expect(cles(elements)).toEqual([1, 2, 3, 4])
    })

    it('remet derrière son précédent quand plus aucun de ses suivants n’est là', () => {
        const elements = liste(1, 2, 3)
        const remettre = retirerDeLaListe(() => elements, 2)
        elements.splice(1, 1)

        remettre()
        expect(cles(elements)).toEqual([1, 2])
    })

    it('remet à son indice, borné, quand aucun voisin n’est resté', () => {
        const elements = liste(1, 2, 3)
        const remettre = retirerDeLaListe(() => elements, 3)
        elements.splice(0, 2, { id: 8 })

        remettre()
        expect(cles(elements)).toEqual([8, 3])
    })

    it('relit la liste, que la page remplace à chaque réponse, et ne double pas un élément revenu', () => {
        let elements = liste(1, 2, 3)
        const remettre = retirerDeLaListe(() => elements, 2)

        elements = liste(1, 2, 3, 4)
        remettre()
        expect(cles(elements)).toEqual([1, 2, 3, 4])

        elements = liste(1, 3, 4)
        remettre()
        expect(cles(elements)).toEqual([1, 2, 3, 4])
    })

    it('lit la clé par `cleDe`', () => {
        const elements = [{ cle: 'a' }, { cle: 'b' }]
        const remettre = retirerDeLaListe(() => elements, 'a', { cleDe: (element) => element.cle })

        expect(elements).toEqual([{ cle: 'b' }])
        remettre()
        expect(elements).toEqual([{ cle: 'a' }, { cle: 'b' }])
    })
})

describe('ordreRetabli', () => {
    const liste = (...cles) => cles.map((id) => ({ id }))
    const cles = (elements) => elements.map((element) => element.id)

    it('remet l’ordre confirmé', () => {
        expect(cles(ordreRetabli(liste(2, 1, 3), [1, 2, 3]))).toEqual([1, 2, 3])
    })

    it('garde à la fin, dans l’ordre de l’écran, ce que l’ordre confirmé ne connaît pas', () => {
        expect(cles(ordreRetabli(liste(9, 2, 1, 8), [1, 2]))).toEqual([1, 2, 9, 8])
    })

    it('oublie ce que l’ordre confirmé nomme et que l’écran n’a plus', () => {
        expect(cles(ordreRetabli(liste(2, 1), [1, 5, 2]))).toEqual([1, 2])
    })

    it('rend les mêmes objets', () => {
        const elements = liste(2, 1)

        expect(ordreRetabli(elements, [1, 2])).toEqual([elements[1], elements[0]])
        expect(ordreRetabli(elements, [1, 2])[0]).toBe(elements[1])
    })
})
