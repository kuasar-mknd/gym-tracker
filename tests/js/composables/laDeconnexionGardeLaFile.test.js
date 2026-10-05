import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'

import { chargerSyncService, naviguer, poserLaPage, retirerLesEcouteursDuService } from '../utils/fileHorsLigne'

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
 * part que sous sa session. La déconnexion la garde pour ce compte, sans
 * attendre, et l'écran qui suit le dit.
 */

const envoyees = () =>
    requete.mock.calls.map(([config]) => `${config.method} ${config.url} ${JSON.stringify(config.data)}`)

const PREFERENCES = { preferences: { personal_record: false, training_reminder: false } }

/** A modifie ses préférences sans réseau ; au rejeu, sa session a expiré. */
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

const seDeconnecter = async () => (await import('@/composables/useDeconnexion')).seDeconnecter()

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
    formulaire.supprimer.mockReset()
    globalThis.route = (nom) => `/${nom}`
})

afterEach(() => {
    vi.useRealTimers()
    vi.restoreAllMocks()
    retirerLesEcouteursDuService()
    poserLaPage(null)
})

describe('la file d’un compte, à la déconnexion', () => {
    it('n’envoie jamais sous la session de B ce que A a laissé en file, et le rend à A à son retour', async () => {
        await aLaisseUneEcritureEnFile()

        await seDeconnecter()

        expect(routeur.post).toHaveBeenCalledWith('/logout', {}, expect.any(Object))
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
        await seDeconnecter()

        requete.mockReset()
        requete.mockResolvedValue({ data: { data: {} } })
        naviguer(null)
        naviguer(2)
        await sync.pending
        await sync.patch('/api/v1/sets/900', { is_completed: true })

        expect(envoyees()).toEqual(['patch /api/v1/sets/900 {"is_completed":true}'])
        expect(sync.queue.map((entree) => entree.url)).toEqual(['/profile/preferences'])
    })

    it('le dit sur l’écran qui suit, une seule fois, sans retenir la déconnexion', async () => {
        await aLaisseUneEcritureEnFile()

        await seDeconnecter()

        const ecran = await ecranDeConnexion()
        expect(ecran.find('[dusk="ecritures-gardees"]').text()).toBe(
            "Une modification faite hors ligne n'a pas encore été envoyée. Elle reste sur cet appareil et partira à la prochaine connexion de ce compte, jamais sous un autre.",
        )

        const ecranSuivant = await ecranDeConnexion()
        expect(ecranSuivant.find('[dusk="ecritures-gardees"]').exists()).toBe(false)

        // Un compte qui n'a rien en attente se déconnecte sans avis.
        const sousC = await chargerSyncService({ compte: 3 })
        await sousC.pending
        await seDeconnecter()

        const apresUneDeconnexionSansAttente = await ecranDeConnexion()
        expect(apresUneDeconnexionSansAttente.find('[dusk="ecritures-gardees"]').exists()).toBe(false)
        expect(routeur.post).toHaveBeenCalledTimes(2)
    })

    it('efface les refus et les brouillons de séries que le compte laisse', async () => {
        localStorage.setItem(
            'offline_sync_failed',
            JSON.stringify([{ method: 'post', url: '/api/v1/sets', data: { reps: 8 }, compte: '1', status: 422 }]),
        )
        localStorage.setItem('draft_set_12', JSON.stringify({ reps: 8 }))
        localStorage.setItem('draft_set_13', JSON.stringify({ weight: 60 }))
        localStorage.setItem('une-autre-clef', 'gardée')
        const sync = await chargerSyncService({ compte: 1 })
        await sync.pending

        await seDeconnecter()

        expect(localStorage.getItem('offline_sync_failed')).toBeNull()
        expect(localStorage.getItem('draft_set_12')).toBeNull()
        expect(localStorage.getItem('draft_set_13')).toBeNull()
        expect(localStorage.getItem('une-autre-clef')).toBe('gardée')

        // Un stockage qui refuse tout ne retient pas la déconnexion suivante.
        localStorage.setItem('draft_set_14', JSON.stringify({ reps: 8 }))
        const refuser = () => {
            throw new DOMException('bloqué', 'SecurityError')
        }
        vi.spyOn(Storage.prototype, 'setItem').mockImplementation(refuser)
        vi.spyOn(Storage.prototype, 'removeItem').mockImplementation(refuser)
        routeur.post.mock.calls[0][2].onFinish()

        await seDeconnecter()

        expect(routeur.post).toHaveBeenCalledTimes(2)
    })
})

describe('la file d’un compte supprimé', () => {
    /**
     * Un compte supprimé ne se reconnecte jamais : ce qu'il laissait en file ne
     * partirait jamais, et resterait sur l'appareil, alors que la suppression
     * promet d'effacer ses données.
     */
    it('s’efface avec lui, une fois la suppression acceptée, et laisse aux autres comptes ce qui est à eux', async () => {
        const entree = (id, compte) => ({ id, method: 'patch', url: `/api/v1/sets/${id}`, data: { reps: 8 }, compte })
        const refus = (compte) => ({ method: 'post', url: '/api/v1/sets', data: { reps: 8 }, compte, status: 422 })
        localStorage.setItem('offline_sync_queue', JSON.stringify([entree('1', '1'), entree('2', '2')]))
        localStorage.setItem('offline_sync_failed', JSON.stringify([refus('1'), refus('2')]))
        localStorage.setItem('draft_set_12', JSON.stringify({ reps: 8 }))
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
    })
})

describe('l’avis des écritures gardées', () => {
    const avis = async () => import('@/Utils/ecrituresGardees')

    it('se périme au bout d’une minute : une déconnexion qui n’a pas abouti ne ressort pas plus tard', async () => {
        vi.useFakeTimers()
        const { noterLesEcrituresGardees, reprendreLesEcrituresGardees } = await avis()

        noterLesEcrituresGardees(3)
        vi.advanceTimersByTime(59_999)
        expect(reprendreLesEcrituresGardees()).toBe(3)

        noterLesEcrituresGardees(3)
        vi.advanceTimersByTime(60_000)
        expect(reprendreLesEcrituresGardees()).toBe(0)
    })

    it('n’écrit qu’un nombre, et efface l’avis précédent quand rien n’attend', async () => {
        const { noterLesEcrituresGardees, reprendreLesEcrituresGardees } = await avis()

        noterLesEcrituresGardees(2)
        expect(Object.keys(JSON.parse(sessionStorage.getItem('gym-tracker:ecritures-gardees')))).toEqual([
            'nombre',
            'le',
        ])

        noterLesEcrituresGardees(0)
        expect(reprendreLesEcrituresGardees()).toBe(0)
    })

    it('lit un avis illisible ou un stockage qui refuse comme « rien »', async () => {
        const { noterLesEcrituresGardees, reprendreLesEcrituresGardees } = await avis()

        sessionStorage.setItem('gym-tracker:ecritures-gardees', '{coupé')
        expect(reprendreLesEcrituresGardees()).toBe(0)

        sessionStorage.setItem('gym-tracker:ecritures-gardees', JSON.stringify({ nombre: 'deux', le: Date.now() }))
        expect(reprendreLesEcrituresGardees()).toBe(0)

        vi.spyOn(Storage.prototype, 'setItem').mockImplementation(() => {
            throw new DOMException('bloqué', 'SecurityError')
        })
        expect(() => noterLesEcrituresGardees(2)).not.toThrow()
    })

    it('accorde le message au nombre', async () => {
        const { messageDesEcrituresGardees } = await avis()

        expect(messageDesEcrituresGardees(4)).toBe(
            "4 modifications faites hors ligne n'ont pas encore été envoyées. Elles restent sur cet appareil et partiront à la prochaine connexion de ce compte, jamais sous un autre.",
        )
    })
})
