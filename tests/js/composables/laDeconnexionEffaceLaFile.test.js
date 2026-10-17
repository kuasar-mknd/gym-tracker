import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'

import {
    chargerSyncService,
    naviguer,
    poserLaPage,
    restaurerDepuisLHistorique,
    retirerLesEcouteursDuService,
} from '../utils/fileHorsLigne'

const requete = vi.hoisted(() => vi.fn())
const routeur = vi.hoisted(() => ({ post: vi.fn() }))
const formulaire = vi.hoisted(() => ({ supprimer: vi.fn() }))

vi.mock('@/Utils/http', () => ({ http: (...args) => requete(...args) }))
vi.mock('@inertiajs/vue3', () => ({
    router: { post: (...args) => routeur.post(...args) },
    Link: { template: '<a><slot /></a>' },
    // L'écran de connexion qui suit la déconnexion : personne n'y est connecté.
    usePage: () => ({ props: { auth: { user: null } } }),
    useForm: (champs) => ({
        ...champs,
        errors: {},
        processing: false,
        delete: (...args) => formulaire.supprimer(...args),
        clearErrors: () => {},
        reset: () => {},
    }),
}))
// Le détachement de l'appareil et sa marque ont leurs propres tests (useAbonnementPush.test.js).
vi.mock('@/composables/useAbonnementPush', () => ({
    detacherLAppareil: () => Promise.resolve(),
    marquerLAbonnementARetransmettre: () => {},
}))

/**
 * La file appartenait à l'appareil, pas au compte (#1964). Sur un appareil
 * partagé, le compte suivant rejouait sous sa propre session ce que le
 * précédent avait laissé en file : ses préférences s'appliquaient au second, et
 * ses séries revenaient en 403, perdues pour leur auteur.
 *
 * La règle retenue : une écriture en file porte le compte qui l'a faite et ne
 * part que sous sa session. Une session qui expire, ou que le serveur ferme,
 * lui laisse ses écritures jusqu'à son retour, trente jours au plus (#2050).
 * La déconnexion, elle, ne les garde plus en clair sur l'appareil : elle
 * prévient, puis les efface, et l'écran qui suit le dit (#2050). Le parcours
 * avec le vrai client d'Inertia est dans
 * `deconnexionEffaceLaFileAvecInertia.test.js`.
 */

const envoyees = () =>
    requete.mock.calls.map(([config]) => `${config.method} ${config.url} ${JSON.stringify(config.data)}`)

const PREFERENCES = { preferences: { personal_record: false, training_reminder: false } }

/** A modifie ses préférences sans réseau ; au rejeu, sa session a expiré, sans qu'il se soit déconnecté. */
const aLaisseUneEcritureEnFile = async () => {
    const sync = await chargerSyncService({ compte: 1 })
    await sync.pending

    requete.mockRejectedValueOnce({ code: 'ERR_NETWORK', request: {} })
    await expect(sync.patch('/profile/preferences', PREFERENCES)).rejects.toMatchObject({ isOffline: true })

    requete.mockRejectedValueOnce({ response: { status: 401 } })
    await sync.processQueue()
    expect(sync.queue[0].authAttempts).toBe(1)

    return sync
}

const deconnexion = async () => import('@/composables/useDeconnexion')

const seDeconnecter = async () => (await deconnexion()).seDeconnecter()

/** La question posée, puis « Se déconnecter quand même ». */
const seDeconnecterQuandMeme = async () => {
    const { questionDeDeconnexion, seDeconnecterQuandMeme: confirmer } = await deconnexion()

    await seDeconnecter()
    expect(questionDeDeconnexion.value).not.toBeNull()

    return confirmer()
}

const ecranDeConnexion = async () => {
    const GuestLayout = (await import('@/Layouts/GuestLayout.vue')).default
    const ecran = mount(GuestLayout, { global: { stubs: { LiquidBackground: true } } })
    await flushPromises()

    return ecran
}

beforeEach(() => {
    localStorage.clear()
    sessionStorage.clear()
    requete.mockReset()
    routeur.post.mockReset()
    // Comme Inertia : une visite qui part appelle `onStart` dans l'appel même.
    routeur.post.mockImplementation((_url, _donnees, options) => options?.onStart?.())
    formulaire.supprimer.mockReset()
    globalThis.route = (nom) => `/${nom}`
})

afterEach(() => {
    vi.useRealTimers()
    vi.restoreAllMocks()
    retirerLesEcouteursDuService()
    poserLaPage(null)
})

describe('la file d’un compte dont la session a expiré', () => {
    /*
     * A ne s'est pas déconnecté : le serveur a fermé sa session. Rien ne lui a
     * été demandé, et rien n'est effacé ; son écriture l'attend.
     */
    it('n’envoie jamais sous la session de B ce que A a laissé en file, et le rend à A à son retour', async () => {
        await aLaisseUneEcritureEnFile()

        expect(routeur.post).not.toHaveBeenCalled()
        expect(JSON.parse(localStorage.getItem('offline_sync_queue')).map((entree) => entree.url)).toEqual([
            '/profile/preferences',
        ])

        // B se connecte (nouveau chargement) et coche une série.
        requete.mockReset()
        requete.mockResolvedValue({ data: { data: {} } })
        const sousB = await chargerSyncService({ compte: 2 })
        await sousB.pending
        await sousB.patch('/api/v1/sets/900', { is_completed: true })

        expect(envoyees()).toEqual(['patch /api/v1/sets/900 {"is_completed":true}'])

        // A revient : son écriture part sous sa propre session.
        const sousA = await chargerSyncService({ compte: 1 })
        await sousA.pending

        expect(envoyees()).toEqual([
            'patch /api/v1/sets/900 {"is_completed":true}',
            `patch /profile/preferences ${JSON.stringify(PREFERENCES)}`,
        ])
        expect(sousA.queue).toEqual([])
    })

    it('ne l’envoie pas non plus quand B se connecte sans recharger la page', async () => {
        const sync = await aLaisseUneEcritureEnFile()

        requete.mockReset()
        requete.mockResolvedValue({ data: { data: {} } })
        naviguer(null)
        naviguer(2)
        await sync.pending
        await sync.patch('/api/v1/sets/900', { is_completed: true })

        expect(envoyees()).toEqual(['patch /api/v1/sets/900 {"is_completed":true}'])
        expect(sync.queue.map((entree) => entree.url)).toEqual(['/profile/preferences'])
    })

    /*
     * Le bouton Retour restaure la page de A depuis l'historique, props de A
     * comprises, et Inertia annonce la navigation. Le service croyait A revenu
     * et vidait sa file sous la session de B.
     */
    it('ne l’envoie pas quand B revient, par le bouton Retour, sur une page de A', async () => {
        const sync = await aLaisseUneEcritureEnFile()

        requete.mockReset()
        requete.mockResolvedValue({ data: { data: {} } })
        naviguer(null)
        naviguer(2)
        await sync.pending

        restaurerDepuisLHistorique(null)
        restaurerDepuisLHistorique(1)
        await sync.pending

        expect(envoyees()).toEqual([])
        expect(sync.compte).toBe('2')
        expect(sync.queue.map((entree) => entree.url)).toEqual(['/profile/preferences'])
    })

    /*
     * Deux onglets, ou la PWA et le navigateur, partagent les cookies. B se
     * connecte dans l'autre : rien ne le dit à celui-ci, resté sur une page de
     * A, qui vide la file de A quand il revient au premier plan. Il envoie le
     * compte de A avec l'écriture ; le serveur, qui voit la session de B, la
     * refuse sans l'exécuter (VerifieLeCompteDeLEcriture), et elle reste à A.
     */
    it('envoie le compte de A depuis un onglet resté sur sa page, et garde l’écriture quand le serveur voit B', async () => {
        const sync = await aLaisseUneEcritureEnFile()

        requete.mockReset()
        requete.mockRejectedValue({ response: { status: 409, data: { raison: 'compte-different' } } })

        Object.defineProperty(document, 'visibilityState', { value: 'visible', configurable: true })
        document.dispatchEvent(new Event('visibilitychange'))
        await sync.pending

        expect(requete).toHaveBeenCalledTimes(1)
        expect(requete.mock.calls[0][0]).toMatchObject({
            url: '/profile/preferences',
            headers: { 'X-Compte-De-L-Ecriture': '1' },
        })
        expect(sync.queue).toEqual([expect.objectContaining({ url: '/profile/preferences', authAttempts: 1 })])
        expect(sync.failedRequests()).toEqual([])
    })
})

describe('la file d’un compte, à la déconnexion', () => {
    /*
     * La déconnexion gardait la file de A pour son retour, en clair sur
     * l'appareil, et l'écran de connexion disait qu'elle partirait à sa
     * prochaine connexion (#1964). Elle prévient désormais, puis efface (#2050).
     */
    it('demande d’abord, et ne part pas tant que la personne n’a pas répondu', async () => {
        await aLaisseUneEcritureEnFile()
        const { questionDeDeconnexion, resterConnecte } = await deconnexion()

        await seDeconnecter()

        expect(questionDeDeconnexion.value).toEqual({ nombre: 1 })
        expect(routeur.post).not.toHaveBeenCalled()

        resterConnecte()

        expect(questionDeDeconnexion.value).toBeNull()
        expect(routeur.post).not.toHaveBeenCalled()
        expect(JSON.parse(localStorage.getItem('offline_sync_queue')).map((entree) => entree.url)).toEqual([
            '/profile/preferences',
        ])
    })

    it('efface la file de A une fois la déconnexion acceptée : B ne la voit jamais, et A revenu ne trouve rien', async () => {
        await aLaisseUneEcritureEnFile()

        await seDeconnecterQuandMeme()

        expect(routeur.post).toHaveBeenCalledWith('/logout', {}, expect.any(Object))
        naviguer(null)
        routeur.post.mock.calls[0][2].onSuccess()
        routeur.post.mock.calls[0][2].onFinish()

        expect(localStorage.getItem('offline_sync_queue')).toBeNull()

        // B se connecte (nouveau chargement), puis A revient : rien de A ne part plus.
        requete.mockReset()
        requete.mockResolvedValue({ data: { data: {} } })
        const sousB = await chargerSyncService({ compte: 2 })
        await sousB.pending
        const sousA = await chargerSyncService({ compte: 1 })
        await sousA.pending

        expect(envoyees()).toEqual([])
        expect(sousA.enAttente()).toBe(0)
    })

    it('le dit sur l’écran qui suit, une seule fois, sans retenir la déconnexion', async () => {
        await aLaisseUneEcritureEnFile()

        await seDeconnecterQuandMeme()

        const ecran = await ecranDeConnexion()
        expect(ecran.find('[dusk="ecritures-effacees-a-la-deconnexion"]').text()).toBe(
            "Une modification faite hors ligne n'avait pas encore été envoyée : elle a été effacée de cet appareil avec la déconnexion.",
        )

        const ecranSuivant = await ecranDeConnexion()
        expect(ecranSuivant.find('[dusk="ecritures-effacees-a-la-deconnexion"]').exists()).toBe(false)

        // Un compte qui n'a rien en attente se déconnecte sans question ni avis.
        const sousC = await chargerSyncService({ compte: 3 })
        await sousC.pending
        await seDeconnecter()

        expect((await deconnexion()).questionDeDeconnexion.value).toBeNull()
        const apresUneDeconnexionSansAttente = await ecranDeConnexion()
        expect(apresUneDeconnexionSansAttente.find('[dusk="ecritures-effacees-a-la-deconnexion"]').exists()).toBe(false)
        expect(routeur.post).toHaveBeenCalledTimes(2)
    })

    it('efface les refus, les brouillons de séries et la recherche d’exercice que le compte laisse, une fois la déconnexion acceptée', async () => {
        localStorage.setItem(
            'offline_sync_failed',
            JSON.stringify([{ method: 'post', url: '/api/v1/sets', data: { reps: 8 }, compte: '1', status: 422 }]),
        )
        localStorage.setItem('draft_set_12', JSON.stringify({ reps: 8 }))
        localStorage.setItem('draft_set_13', JSON.stringify({ weight: 60 }))
        localStorage.setItem('gymtracker_add_exercise_search', 'Développé couché')
        localStorage.setItem('une-autre-clef', 'gardée')
        const sync = await chargerSyncService({ compte: 1 })
        await sync.pending

        await seDeconnecter()

        // Rien avant la réponse : la déconnexion peut encore échouer.
        expect(localStorage.getItem('draft_set_12')).not.toBeNull()
        expect(localStorage.getItem('gymtracker_add_exercise_search')).toBe('Développé couché')

        // La réponse mène à l'écran de connexion : le service n'a plus de compte quand `onSuccess` arrive.
        naviguer(null)
        routeur.post.mock.calls[0][2].onSuccess()
        routeur.post.mock.calls[0][2].onFinish()

        expect(localStorage.getItem('offline_sync_failed')).toBeNull()
        expect(localStorage.getItem('draft_set_12')).toBeNull()
        expect(localStorage.getItem('draft_set_13')).toBeNull()
        expect(localStorage.getItem('gymtracker_add_exercise_search')).toBeNull()
        expect(localStorage.getItem('une-autre-clef')).toBe('gardée')

        // Un stockage qui refuse tout ne retient pas la déconnexion suivante.
        localStorage.setItem('draft_set_14', JSON.stringify({ reps: 8 }))
        const refuser = () => {
            throw new DOMException('bloqué', 'SecurityError')
        }
        vi.spyOn(Storage.prototype, 'setItem').mockImplementation(refuser)
        vi.spyOn(Storage.prototype, 'removeItem').mockImplementation(refuser)

        await seDeconnecter()
        routeur.post.mock.calls[1][2].onSuccess()
        routeur.post.mock.calls[1][2].onFinish()

        expect(routeur.post).toHaveBeenCalledTimes(2)
    })

    /*
     * Le stockage refuse l'écriture de la file (quota atteint, navigation
     * privée) : l'écriture n'attend qu'en mémoire. La question ne lisait que le
     * stockage, elle aurait compté zéro, et la déconnexion l'aurait effacée
     * sans rien demander.
     */
    it('compte aussi l’écriture que le stockage n’a pas pu garder, et l’oublie une fois la déconnexion acceptée', async () => {
        const sync = await chargerSyncService({ compte: 1 })
        await sync.pending
        const ecrire = Storage.prototype.setItem
        vi.spyOn(Storage.prototype, 'setItem').mockImplementation(function (cle, valeur) {
            if (cle === 'offline_sync_queue') {
                throw new DOMException('plein', 'QuotaExceededError')
            }

            ecrire.call(this, cle, valeur)
        })
        requete.mockRejectedValueOnce({ code: 'ERR_NETWORK', request: {} })
        await expect(sync.patch('/api/v1/sets/5', { weight: 80 })).rejects.toMatchObject({ isOffline: true })
        expect(localStorage.getItem('offline_sync_queue')).toBeNull()
        const { questionDeDeconnexion, seDeconnecterQuandMeme: confirmer } = await deconnexion()

        await seDeconnecter()

        expect(questionDeDeconnexion.value).toEqual({ nombre: 1 })
        expect(routeur.post).not.toHaveBeenCalled()

        await confirmer()
        naviguer(null)
        routeur.post.mock.calls[0][2].onSuccess()
        routeur.post.mock.calls[0][2].onFinish()

        expect(sync.queue).toEqual([])
        expect((await ecranDeConnexion()).find('[dusk="ecritures-effacees-a-la-deconnexion"]').text()).toBe(
            "Une modification faite hors ligne n'avait pas encore été envoyée : elle a été effacée de cet appareil avec la déconnexion.",
        )
    })

    /*
     * En salle sans réseau, la déconnexion part et n'aboutit pas : la personne
     * reste connectée. Ses refus pas encore montrés et ses brouillons, seul
     * endroit où survit une valeur dont l'écriture a échoué, étaient effacés
     * avant l'envoi, et l'avis noté pour l'écran de connexion ressortait sur la
     * prochaine page d'invité. Ses écritures en attente non plus ne sont pas
     * effacées : elle a accepté de les perdre en se déconnectant, et elle ne
     * s'est pas déconnectée (#2050).
     */
    it('garde tout quand la déconnexion n’aboutit pas, et retire l’avis noté pour l’écran suivant', async () => {
        localStorage.setItem(
            'offline_sync_failed',
            JSON.stringify([{ method: 'post', url: '/api/v1/sets', data: { reps: 8 }, compte: '1', status: 422 }]),
        )
        localStorage.setItem('draft_set_12', JSON.stringify({ reps: 8 }))
        const sync = await aLaisseUneEcritureEnFile()
        requete.mockRejectedValue({ code: 'ERR_NETWORK', request: {} })

        // Inertia finit la visite sans succès : le réseau a coupé en route.
        routeur.post.mockImplementation((_url, _donnees, options) => options.onFinish())

        await seDeconnecterQuandMeme()

        expect(sync.compte).toBe('1')
        expect(sync.failedRequests()).toHaveLength(1)
        expect(localStorage.getItem('draft_set_12')).not.toBeNull()
        expect(sync.queue.map((entree) => entree.url)).toEqual(['/profile/preferences'])
        expect(JSON.parse(localStorage.getItem('offline_sync_queue')).map((entree) => entree.url)).toEqual([
            '/profile/preferences',
        ])
        expect((await ecranDeConnexion()).find('[dusk="ecritures-effacees-a-la-deconnexion"]').exists()).toBe(false)

        // Elle se relance, et repose la question : rien n'a été effacé.
        await seDeconnecterQuandMeme()

        expect(routeur.post).toHaveBeenCalledTimes(2)
    })
})

/*
 * Une écriture partie juste avant le clic, encore sans réponse, n'est pas
 * encore en file : la question ne la comptait pas, la déconnexion partait sans
 * rien demander, et si le réseau tombait ensuite, l'écriture entrait en file au
 * nom du compte parti, en clair, après l'effacement (#2050).
 */
describe('une écriture partie juste avant le clic', () => {
    const MESSAGE_UNE =
        "Une modification faite hors ligne n'avait pas encore été envoyée : elle a été effacée de cet appareil avec la déconnexion."

    /** Une écriture à laquelle le serveur ne répond pas encore : le test règle sa réponse. */
    const enVol = () => {
        const reglement = {}
        requete.mockImplementationOnce(
            () =>
                new Promise((resoudre, rejeter) => {
                    reglement.repondre = resoudre
                    reglement.echouer = rejeter
                }),
        )

        return reglement
    }

    it('attend sa réponse avant de compter : enregistrée, la déconnexion part sans question, une seule fois', async () => {
        const sync = await chargerSyncService({ compte: 1 })
        await sync.pending
        const reglement = enVol()
        const ecriture = sync.patch('/api/v1/sets/5', { weight: 80 })
        const { questionDeDeconnexion, seDeconnecter: deconnecter } = await deconnexion()

        const premier = deconnecter()
        expect(deconnecter()).toBe(premier)
        await flushPromises()

        // Elle vole encore : ni question, ni déconnexion.
        expect(questionDeDeconnexion.value).toBeNull()
        expect(routeur.post).not.toHaveBeenCalled()

        reglement.repondre({ data: { data: { id: 5, weight: 80 } } })
        await ecriture
        await premier

        expect(questionDeDeconnexion.value).toBeNull()
        expect(routeur.post).toHaveBeenCalledTimes(1)
    })

    it('tombée sans réseau pendant l’attente, elle est comptée par la question, puis effacée avec la déconnexion acceptée', async () => {
        const sync = await chargerSyncService({ compte: 1 })
        await sync.pending
        requete.mockRejectedValue({ code: 'ERR_NETWORK', request: {} })
        const reglement = enVol()
        const ecriture = sync.patch('/api/v1/sets/5', { weight: 80, notes: 'Note privée' })
        const { questionDeDeconnexion } = await deconnexion()

        const deconnexionDemandee = seDeconnecter()
        await flushPromises()
        expect(routeur.post).not.toHaveBeenCalled()

        reglement.echouer({ code: 'ERR_NETWORK', request: {} })
        await expect(ecriture).rejects.toMatchObject({ isOffline: true })
        await deconnexionDemandee

        expect(questionDeDeconnexion.value).toEqual({ nombre: 1 })
        expect(routeur.post).not.toHaveBeenCalled()

        await (await deconnexion()).seDeconnecterQuandMeme()
        naviguer(null)
        routeur.post.mock.calls[0][2].onSuccess()
        routeur.post.mock.calls[0][2].onFinish()

        expect(JSON.stringify({ ...localStorage })).not.toContain('Note privée')
        expect((await ecranDeConnexion()).find('[dusk="ecritures-effacees-a-la-deconnexion"]').text()).toBe(MESSAGE_UNE)
    })

    it('encore sans réponse au bout de l’attente, elle est comptée ; acceptée, la déconnexion ne la laisse pas revenir sur l’appareil', async () => {
        vi.useFakeTimers()
        const sync = await chargerSyncService({ compte: 1 })
        await sync.pending
        const reglement = enVol()
        const ecriture = sync.patch('/api/v1/sets/5', { weight: 80, notes: 'Note privée' })
        const {
            ATTENTE_MAX_DES_ECRITURES_MS,
            questionDeDeconnexion,
            seDeconnecterQuandMeme: confirmer,
        } = await deconnexion()
        const { reprendreLesEcrituresEffaceesALaDeconnexion } = await import('@/Utils/ecrituresEffaceesALaDeconnexion')

        const deconnexionDemandee = seDeconnecter()
        await vi.advanceTimersByTimeAsync(ATTENTE_MAX_DES_ECRITURES_MS - 1)

        expect(questionDeDeconnexion.value).toBeNull()
        expect(routeur.post).not.toHaveBeenCalled()

        await vi.advanceTimersByTimeAsync(1)
        await deconnexionDemandee

        expect(questionDeDeconnexion.value).toEqual({ nombre: 1 })
        expect(routeur.post).not.toHaveBeenCalled()

        await confirmer()
        naviguer(null)
        routeur.post.mock.calls[0][2].onSuccess()
        routeur.post.mock.calls[0][2].onFinish()

        // L'écran de connexion dit celle-là aussi effacée.
        expect(reprendreLesEcrituresEffaceesALaDeconnexion()).toBe(1)

        // Le réseau tombe après la déconnexion : la réponse ne la remet pas en file au nom du compte parti.
        reglement.echouer({ code: 'ERR_NETWORK', request: {} })

        await expect(ecriture).rejects.toMatchObject({ nonGardee: true })
        expect(JSON.stringify({ ...localStorage })).not.toContain('Note privée')
    })
})

describe('la file d’un compte supprimé', () => {
    /**
     * Un compte supprimé ne se reconnecte jamais : ce qu'il laissait en file ne
     * partirait jamais, et resterait sur l'appareil, alors que la suppression
     * promet d'effacer ses données.
     */
    it('s’efface avec lui, une fois la suppression acceptée, avec sa recherche d’exercice, et laisse aux autres comptes ce qui est à eux', async () => {
        const entree = (id, compte) => ({ id, method: 'patch', url: `/api/v1/sets/${id}`, data: { reps: 8 }, compte })
        const refus = (compte) => ({ method: 'post', url: '/api/v1/sets', data: { reps: 8 }, compte, status: 422 })
        localStorage.setItem('offline_sync_queue', JSON.stringify([entree('1', '1'), entree('2', '2')]))
        localStorage.setItem('offline_sync_failed', JSON.stringify([refus('1'), refus('2')]))
        localStorage.setItem('draft_set_12', JSON.stringify({ reps: 8 }))
        localStorage.setItem('gymtracker_add_exercise_search', 'Squat')
        requete.mockRejectedValue({ code: 'ERR_NETWORK', request: {} })
        const sync = await chargerSyncService({ compte: 1 })
        await sync.pending

        const DeleteUserForm = (await import('@/Pages/Profile/Partials/DeleteUserForm.vue')).default
        const formulaireDeSuppression = mount(DeleteUserForm, { shallow: true })
        formulaireDeSuppression.vm.deleteUser()

        // Rien avant la réponse : un mot de passe refusé garde tout.
        expect(JSON.parse(localStorage.getItem('offline_sync_queue'))).toHaveLength(2)

        // La réponse mène à une page où plus personne n'est connecté.
        naviguer(null)
        formulaire.supprimer.mock.calls[0][1].onSuccess()

        expect(JSON.parse(localStorage.getItem('offline_sync_queue')).map((e) => e.compte)).toEqual(['2'])
        expect(JSON.parse(localStorage.getItem('offline_sync_failed')).map((e) => e.compte)).toEqual(['2'])
        expect(localStorage.getItem('draft_set_12')).toBeNull()
        expect(localStorage.getItem('gymtracker_add_exercise_search')).toBeNull()
    })
})

/*
 * Une écriture qui attend depuis plus de trente jours est abandonnée, et un
 * avis attend le prochain écran du compte. La déconnexion l'effaçait avec le
 * reste, même jamais montré : sous le layout invité (la vérification de
 * l'adresse), qui ne le lit pas, ou quand un autre message occupait le toast.
 * La perte passait sans un mot (#2050).
 */
describe('l’avis des écritures abandonnées, à la déconnexion', () => {
    const MAINTENANT = new Date('2026-10-16T12:00:00.000Z')
    const JOUR = 24 * 60 * 60 * 1000
    const AVIS = 'gym-tracker:ecritures-abandonnees'
    const MESSAGE =
        "Une modification faite hors ligne le 15/09/2026 n'a pas pu être enregistrée : gardée 30 jours sur cet appareil sans pouvoir partir, elle a été abandonnée et effacée. Vérifie tes séances."

    /** Une écriture du compte 1 faite il y a 31 jours : le chargement l'abandonne et note l'avis. */
    const unAbandonPasEncoreDit = async () => {
        vi.useFakeTimers({ toFake: ['Date'] })
        vi.setSystemTime(MAINTENANT)
        localStorage.setItem(
            'offline_sync_queue',
            JSON.stringify([
                {
                    method: 'patch',
                    url: '/api/v1/sets/5',
                    data: { weight: 80 },
                    id: 'q1',
                    timestamp: new Date(MAINTENANT.getTime() - 31 * JOUR).toISOString(),
                    compte: '1',
                },
            ]),
        )
        const sync = await chargerSyncService({ compte: 1 })
        await sync.pending

        expect(sync.queue).toEqual([])
        expect(JSON.parse(localStorage.getItem(AVIS))['1'].nombre).toBe(1)

        return sync
    }

    it('le dit sur l’écran de connexion, une fois, quand la déconnexion l’efface', async () => {
        await unAbandonPasEncoreDit()

        // File vide : la déconnexion part sans question.
        await seDeconnecter()
        expect((await deconnexion()).questionDeDeconnexion.value).toBeNull()

        naviguer(null)
        routeur.post.mock.calls[0][2].onSuccess()
        routeur.post.mock.calls[0][2].onFinish()

        expect(localStorage.getItem(AVIS)).toBeNull()

        const ecran = await ecranDeConnexion()
        expect(ecran.find('[dusk="ecritures-abandonnees-a-la-deconnexion"]').text()).toBe(MESSAGE)
        expect(ecran.find('[dusk="ecritures-effacees-a-la-deconnexion"]').exists()).toBe(false)

        const ecranSuivant = await ecranDeConnexion()
        expect(ecranSuivant.find('[dusk="ecritures-abandonnees-a-la-deconnexion"]').exists()).toBe(false)
    })

    it('le garde pour le compte quand la déconnexion n’aboutit pas', async () => {
        await unAbandonPasEncoreDit()
        // Inertia finit la visite sans succès : le réseau a coupé en route.
        routeur.post.mockImplementation((_url, _donnees, options) => {
            options.onStart()
            options.onFinish()
        })

        await seDeconnecter()

        expect(JSON.parse(localStorage.getItem(AVIS))['1'].nombre).toBe(1)
        expect((await ecranDeConnexion()).find('[dusk="ecritures-abandonnees-a-la-deconnexion"]').exists()).toBe(false)
    })

    it('ne dit rien de l’avis d’un autre compte, qui l’attend toujours', async () => {
        localStorage.setItem(AVIS, JSON.stringify({ 2: { nombre: 3, depuis: null, le: Date.now() } }))
        const sync = await chargerSyncService({ compte: 1 })
        await sync.pending

        await seDeconnecter()
        naviguer(null)
        routeur.post.mock.calls[0][2].onSuccess()
        routeur.post.mock.calls[0][2].onFinish()

        expect((await ecranDeConnexion()).find('[dusk="ecritures-abandonnees-a-la-deconnexion"]').exists()).toBe(false)
        expect(JSON.parse(localStorage.getItem(AVIS))['2'].nombre).toBe(3)
    })

    it('se périme au bout d’une minute, et lit un avis illisible comme « rien »', async () => {
        vi.useFakeTimers()
        const { noterLesAbandonsALaDeconnexion, reprendreLesAbandonsALaDeconnexion } =
            await import('@/Utils/ecrituresEffaceesALaDeconnexion')

        noterLesAbandonsALaDeconnexion({ nombre: 2, depuis: 1 })
        vi.advanceTimersByTime(59_999)
        expect(reprendreLesAbandonsALaDeconnexion()).toEqual({ nombre: 2, depuis: 1 })
        expect(reprendreLesAbandonsALaDeconnexion()).toBeNull()

        noterLesAbandonsALaDeconnexion({ nombre: 2, depuis: null })
        vi.advanceTimersByTime(60_000)
        expect(reprendreLesAbandonsALaDeconnexion()).toBeNull()

        sessionStorage.setItem('gym-tracker:ecritures-abandonnees-a-la-deconnexion', '{coupé')
        expect(reprendreLesAbandonsALaDeconnexion()).toBeNull()

        sessionStorage.setItem(
            'gym-tracker:ecritures-abandonnees-a-la-deconnexion',
            JSON.stringify({ nombre: 0, depuis: null, le: Date.now() }),
        )
        expect(reprendreLesAbandonsALaDeconnexion()).toBeNull()

        sessionStorage.setItem(
            'gym-tracker:ecritures-abandonnees-a-la-deconnexion',
            JSON.stringify({ nombre: 1, depuis: 'hier', le: Date.now() }),
        )
        expect(reprendreLesAbandonsALaDeconnexion()).toEqual({ nombre: 1, depuis: null })
    })
})

describe('l’avis des écritures effacées à la déconnexion', () => {
    const avis = async () => import('@/Utils/ecrituresEffaceesALaDeconnexion')

    it('se périme au bout d’une minute : une déconnexion qui n’a pas abouti ne ressort pas plus tard', async () => {
        vi.useFakeTimers()
        const { noterLesEcrituresEffaceesALaDeconnexion, reprendreLesEcrituresEffaceesALaDeconnexion } = await avis()

        noterLesEcrituresEffaceesALaDeconnexion(3)
        vi.advanceTimersByTime(59_999)
        expect(reprendreLesEcrituresEffaceesALaDeconnexion()).toBe(3)

        noterLesEcrituresEffaceesALaDeconnexion(3)
        vi.advanceTimersByTime(60_000)
        expect(reprendreLesEcrituresEffaceesALaDeconnexion()).toBe(0)
    })

    it('n’écrit qu’un nombre, et efface l’avis précédent quand rien n’attend', async () => {
        const { noterLesEcrituresEffaceesALaDeconnexion, reprendreLesEcrituresEffaceesALaDeconnexion } = await avis()

        noterLesEcrituresEffaceesALaDeconnexion(2)
        expect(
            Object.keys(JSON.parse(sessionStorage.getItem('gym-tracker:ecritures-effacees-a-la-deconnexion'))),
        ).toEqual(['nombre', 'le'])

        noterLesEcrituresEffaceesALaDeconnexion(0)
        expect(reprendreLesEcrituresEffaceesALaDeconnexion()).toBe(0)
    })

    it('lit un avis illisible ou un stockage qui refuse comme « rien »', async () => {
        const { noterLesEcrituresEffaceesALaDeconnexion, reprendreLesEcrituresEffaceesALaDeconnexion } = await avis()

        sessionStorage.setItem('gym-tracker:ecritures-effacees-a-la-deconnexion', '{coupé')
        expect(reprendreLesEcrituresEffaceesALaDeconnexion()).toBe(0)

        sessionStorage.setItem(
            'gym-tracker:ecritures-effacees-a-la-deconnexion',
            JSON.stringify({ nombre: 'deux', le: Date.now() }),
        )
        expect(reprendreLesEcrituresEffaceesALaDeconnexion()).toBe(0)

        vi.spyOn(Storage.prototype, 'setItem').mockImplementation(() => {
            throw new DOMException('bloqué', 'SecurityError')
        })
        expect(() => noterLesEcrituresEffaceesALaDeconnexion(2)).not.toThrow()
    })

    it('accorde le message au nombre', async () => {
        const { messageDesEcrituresEffaceesALaDeconnexion } = await avis()

        expect(messageDesEcrituresEffaceesALaDeconnexion(4)).toBe(
            "4 modifications faites hors ligne n'avaient pas encore été envoyées : elles ont été effacées de cet appareil avec la déconnexion.",
        )
    })
})
