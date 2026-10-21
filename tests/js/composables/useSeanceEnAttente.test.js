import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { h, ref } from 'vue'

import { chargerSyncService, poserLaPage, retirerLesEcouteursDuService } from '../utils/fileHorsLigne'
import { suivreLesPagesMontees } from '../pages/pagesMontees'

const serveur = vi.hoisted(() => ({
    /** @type {Array<(config: object) => Promise<object>>} */
    reponses: [],
    requetes: [],
}))

vi.mock('@/Utils/http', () => ({
    http: async (config) => {
        serveur.requetes.push(`${config.method} ${config.url}`)

        const reponse = serveur.reponses.shift()

        if (reponse === undefined) {
            throw { code: 'ERR_NETWORK', request: {} }
        }

        return reponse(config)
    },
}))
vi.mock('@inertiajs/vue3', () => ({ router: { visit: vi.fn() } }))

/*
 * La séance démarrée sans réseau, sur sa page (#2049, `useSeanceEnAttente`),
 * avec la vraie file hors ligne : chaque état que la page dit, et chaque
 * visite qu'elle fait, ou ne fait pas.
 */

const pages = suivreLesPagesMontees()

const CLE = 'cle-de-la-seance'

let sync
let module
let etatDuReseau
let routeur

/** La création en file, telle que « Démarrer » la met sans réseau. */
const mettreLaSeanceEnFile = () =>
    sync.mettreEnFile({
        method: 'post',
        url: '/api/v1/workouts',
        data: { started_at: '2026-10-19T08:15:00.000Z' },
        headers: { 'Idempotency-Key': CLE },
    })

/**
 * Monte une page qui suit cette séance.
 *
 * @param {object} seance
 * @param {{ occupee?: import('vue').Ref<boolean> }} options
 */
const monter = async (seance, { occupee = ref(false) } = {}) => {
    const { PendingIds } = await import('@/Utils/pendingIds')
    const pendingIds = new PendingIds()
    let suivi

    const wrapper = mount(
        {
            setup() {
                suivi = module.useSeanceEnAttente({ seance, pendingIds, occupee: () => occupee.value, routeur })

                return () => h('input', { id: 'champ' })
            },
        },
        { attachTo: document.body },
    )
    pages.retenir(wrapper)
    await flushPromises()

    return { ...suivi, pendingIds, wrapper }
}

/** Une tâche plus tard : l'essai d'adoption que la page planifie. */
const uneTache = () => new Promise((resolve) => setTimeout(resolve, 0))

beforeEach(async () => {
    localStorage.clear()
    serveur.reponses.length = 0
    serveur.requetes.length = 0
    globalThis.route = (nom, parametres) =>
        nom === 'workouts.show' ? `/workouts/${parametres.workout}` : { dashboard: '/dashboard' }[nom]

    sync = await chargerSyncService({ compte: 1 })
    await sync.pending
    module = await import('@/composables/useSeanceEnAttente')
    etatDuReseau = await import('@/composables/useEtatDuReseau')
    routeur = { visit: vi.fn() }
    etatDuReseau.noterQueLeServeurRepond()
})

afterEach(async () => {
    await pages.demonterToutes()
    retirerLesEcouteursDuService()
    poserLaPage(null)
    localStorage.clear()
})

describe('une séance du serveur', () => {
    it('n’est pas en attente, et rien ne s’y écoute', async () => {
        const { seanceProvisoire, etatDeLaSeance, seanceEnLectureSeule } = await monter({ id: 5 })

        expect(seanceProvisoire).toBe(false)
        expect(etatDeLaSeance.value).toBeNull()
        expect(seanceEnLectureSeule.value).toBe(false)
        expect(routeur.visit).not.toHaveBeenCalled()
    })
})

describe('une séance dont la création attend la file', () => {
    it('se nomme par son entrée, puis par l’identifiant que le serveur lui donne', async () => {
        const queueId = mettreLaSeanceEnFile()
        const { etatDeLaSeance, pendingIds } = await monter({ id: `temp-q${queueId}`, idempotency_key: CLE })

        expect(etatDeLaSeance.value).toBe(module.ETAT_EN_ATTENTE)
        await expect(pendingIds.reference(`temp-q${queueId}`)).resolves.toEqual({ enAttenteDe: queueId })

        serveur.reponses.push(async () => ({ status: 201, data: { data: { id: 42 } } }))
        await sync.processQueue()
        await uneTache()

        await expect(pendingIds.resolve(`temp-q${queueId}`)).resolves.toBe(42)
        expect(routeur.visit).toHaveBeenCalledTimes(1)
        expect(routeur.visit).toHaveBeenCalledWith(
            '/workouts/42',
            expect.objectContaining({ replace: true, preserveScroll: true }),
        )
    })

    /*
     * La création aboutit, mais la ligne qui la nomme (`{ enAttenteDe }`) vole
     * encore dans la file : la visite partie avant sa réponse ouvrait la séance
     * réelle sans l'exercice qu'elle ajoute (#2049, relu le 2026-10-21).
     */
    it('ne passe pas à la séance réelle tant qu’une écriture de la séance attend la file, puis y passe après sa réponse', async () => {
        const queueId = mettreLaSeanceEnFile()
        sync.mettreEnFile({
            method: 'post',
            url: '/api/v1/workout-lines',
            data: { workout_id: { enAttenteDe: queueId }, exercise_id: 7 },
            headers: { 'Idempotency-Key': 'cle-de-la-ligne' },
        })
        await monter({ id: `temp-q${queueId}`, idempotency_key: CLE })

        let repondreALaLigne = () => {}
        const ligneEnVol = new Promise((resolve) => {
            repondreALaLigne = () => resolve({ status: 201, data: { data: { id: 77 } } })
        })
        serveur.reponses.push(async () => ({ status: 201, data: { data: { id: 42 } } }))
        serveur.reponses.push(() => ligneEnVol)
        // La mise en file a déjà tenté la création, sans réseau.
        serveur.requetes.length = 0

        const vidage = sync.processQueue()
        await vi.waitFor(() =>
            expect(serveur.requetes).toEqual(['post /api/v1/workouts', 'post /api/v1/workout-lines']),
        )
        await uneTache()
        await uneTache()

        expect(sync.enAttente()).toBe(1)
        expect(routeur.visit).not.toHaveBeenCalled()

        repondreALaLigne()
        await vidage
        await uneTache()

        expect(sync.enAttente()).toBe(0)
        expect(routeur.visit).toHaveBeenCalledTimes(1)
        expect(routeur.visit).toHaveBeenCalledWith('/workouts/42', expect.objectContaining({ replace: true }))
    })

    it('ne passe pas à la séance réelle tant qu’un champ a le focus, puis y passe à sa sortie', async () => {
        const queueId = mettreLaSeanceEnFile()
        const { wrapper } = await monter({ id: `temp-q${queueId}`, idempotency_key: CLE })

        wrapper.find('#champ').element.focus()
        serveur.reponses.push(async () => ({ status: 201, data: { data: { id: 42 } } }))
        await sync.processQueue()
        await uneTache()

        expect(routeur.visit).not.toHaveBeenCalled()

        wrapper.find('#champ').element.blur()
        await uneTache()

        expect(routeur.visit).toHaveBeenCalledTimes(1)
    })

    it('attend que la personne ne soit plus occupée', async () => {
        const queueId = mettreLaSeanceEnFile()
        const occupee = ref(true)

        await monter({ id: `temp-q${queueId}`, idempotency_key: CLE }, { occupee })

        serveur.reponses.push(async () => ({ status: 201, data: { data: { id: 42 } } }))
        await sync.processQueue()
        await uneTache()

        expect(routeur.visit).not.toHaveBeenCalled()

        occupee.value = false
        await flushPromises()
        await uneTache()

        expect(routeur.visit).toHaveBeenCalledTimes(1)
    })

    /*
     * « Terminer » s'ouvre aussi sur une séance en attente (#2049) : la visite
     * de l'adoption remontait la page et fermait la question sous le doigt.
     */
    it('ne passe pas à la séance réelle tant qu’une fenêtre est ouverte, puis y passe à l’annonce suivante', async () => {
        const queueId = mettreLaSeanceEnFile()
        await monter({ id: `temp-q${queueId}`, idempotency_key: CLE })
        const fenetre = document.createElement('dialog')
        fenetre.setAttribute('open', '')
        document.body.append(fenetre)

        try {
            serveur.reponses.push(async () => ({ status: 201, data: { data: { id: 42 } } }))
            await sync.processQueue()
            await uneTache()

            expect(routeur.visit).not.toHaveBeenCalled()
        } finally {
            fenetre.remove()
        }

        window.dispatchEvent(new CustomEvent('sync:queued', { detail: {} }))
        await uneTache()

        expect(routeur.visit).toHaveBeenCalledTimes(1)
    })

    it('passe en lecture seule quand le serveur la refuse, et dit pourquoi', async () => {
        const queueId = mettreLaSeanceEnFile()
        const { etatDeLaSeance, seanceEnLectureSeule, pendingIds } = await monter({
            id: `temp-q${queueId}`,
            idempotency_key: CLE,
        })

        serveur.reponses.push(async () => {
            throw { response: { status: 409, data: { raison: 'seance-deja-en-cours', seance: { id: 9 } } } }
        })
        await sync.processQueue()
        await uneTache()

        expect(etatDeLaSeance.value).toBe(module.ETAT_REFUSEE)
        expect(seanceEnLectureSeule.value).toBe(true)
        await expect(pendingIds.resolve(`temp-q${queueId}`)).resolves.toBeNull()
        expect(routeur.visit).not.toHaveBeenCalled()
    })

    it('dit « non enregistrée » d’un refus pour une autre raison, ou d’une création retirée', async () => {
        const premiere = mettreLaSeanceEnFile()
        const refusee = await monter({ id: `temp-q${premiere}`, idempotency_key: CLE })

        serveur.reponses.push(async () => {
            throw { response: { status: 422, data: { message: 'started_at' } } }
        })
        await sync.processQueue()

        expect(refusee.etatDeLaSeance.value).toBe(module.ETAT_NON_ENREGISTREE)

        const seconde = mettreLaSeanceEnFile()
        const retiree = await monter({ id: `temp-q${seconde}`, idempotency_key: CLE })

        sync.retirerDeLaFile(seconde)
        await flushPromises()

        expect(retiree.etatDeLaSeance.value).toBe(module.ETAT_NON_ENREGISTREE)
    })
})

describe('une entrée d’historique dont la création n’est plus en file', () => {
    it('dit le refus qu’elle a reçu, sans visite', async () => {
        const queueId = mettreLaSeanceEnFile()

        serveur.reponses.push(async () => {
            throw { response: { status: 409, data: { raison: 'seance-deja-en-cours', seance: { id: 9 } } } }
        })
        await sync.processQueue()

        const { etatDeLaSeance, seanceEnLectureSeule } = await monter({ id: `temp-q${queueId}`, idempotency_key: CLE })

        expect(etatDeLaSeance.value).toBe(module.ETAT_REFUSEE)
        expect(seanceEnLectureSeule.value).toBe(true)
        expect(routeur.visit).not.toHaveBeenCalled()
    })

    it('ouvre la séance réelle quand l’onglet l’a vue créer', async () => {
        const queueId = mettreLaSeanceEnFile()

        serveur.reponses.push(async () => ({ status: 201, data: { data: { id: 42 } } }))
        await sync.processQueue()

        const { pendingIds } = await monter({ id: `temp-q${queueId}`, idempotency_key: CLE })

        expect(routeur.visit).toHaveBeenCalledWith('/workouts/42', expect.objectContaining({ replace: true }))
        await expect(pendingIds.resolve(`temp-q${queueId}`)).resolves.toBe(42)
    })

    it('ouvre l’adresse de sa clé quand le serveur répond, et dit qu’elle se retrouvera s’il ne répond pas', async () => {
        const { etatDeLaSeance, seanceEnLectureSeule } = await monter({ id: 'temp-qpartie', idempotency_key: CLE })

        expect(routeur.visit).toHaveBeenCalledWith(
            `/workouts/en-attente/${CLE}`,
            expect.objectContaining({ replace: true }),
        )
        expect(seanceEnLectureSeule.value).toBe(true)

        routeur.visit.mock.calls[0][1].onNetworkError()

        expect(etatDeLaSeance.value).toBe(module.ETAT_A_RETROUVER)
        expect(seanceEnLectureSeule.value).toBe(true)
    })

    it('dit qu’elle se retrouvera au retour du réseau, sans visite, quand le serveur ne répond pas', async () => {
        etatDuReseau.noterQueLeServeurNeRepondPas()

        const { etatDeLaSeance, pendingIds } = await monter({ id: 'temp-qpartie', idempotency_key: CLE })

        expect(etatDeLaSeance.value).toBe(module.ETAT_A_RETROUVER)
        expect(routeur.visit).not.toHaveBeenCalled()
        await expect(pendingIds.reference('temp-qpartie')).resolves.toBeNull()
    })
})
