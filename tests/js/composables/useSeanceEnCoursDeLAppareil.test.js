import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { h, reactive } from 'vue'

import { chargerSyncService, poserLaPage, retirerLesEcouteursDuService } from '../utils/fileHorsLigne'
import { suivreLesPagesMontees } from '../pages/pagesMontees'

const serveur = vi.hoisted(() => ({
    /** @type {Array<(config: object) => Promise<object>>} */
    reponses: [],
}))

const inertia = vi.hoisted(() => ({
    router: { reload: vi.fn(), visit: vi.fn(), push: vi.fn(), replace: vi.fn() },
    page: null,
}))

vi.mock('@/Utils/http', () => ({
    http: async (config) => {
        const reponse = serveur.reponses.shift()

        if (reponse === undefined) {
            throw { code: 'ERR_NETWORK', request: {} }
        }

        return reponse(config)
    },
}))
vi.mock('@inertiajs/vue3', () => ({ router: inertia.router, usePage: () => inertia.page }))
vi.mock('@/Utils/copiesHorsLigne', () => ({ magasinDesCopies: () => ({ lireUneCopie: async () => null }) }))
vi.mock('@/composables/useHaptics', () => ({ triggerHaptic: vi.fn() }))

/*
 * La séance en cours sur l'appareil, pour le layout (#2049,
 * `useSeanceEnCoursDeLAppareil`), avec la vraie file hors ligne : celle du
 * serveur ou celle qu'une création en file fait naître, le compte redemandé
 * quand la création est rejouée, et le refus dit une fois.
 */

const pages = suivreLesPagesMontees()

const CLE = 'cle-de-la-seance'

let sync
let module

const mettreLaSeanceEnFile = () =>
    sync.mettreEnFile({
        method: 'post',
        url: '/api/v1/workouts',
        data: { started_at: '2026-10-19T08:15:00.000Z' },
        headers: { 'Idempotency-Key': CLE },
    })

/** Un layout qui suit la séance en cours de l'appareil. */
const monter = async () => {
    let suivi

    const wrapper = mount({
        setup() {
            suivi = module.useSeanceEnCoursDeLAppareil({ page: inertia.page })

            return () => h('div')
        },
    })
    pages.retenir(wrapper)
    await flushPromises()

    return suivi
}

/** Une tâche plus tard : le refus s'annonce après ce qu'il emporte. */
const uneTache = () => new Promise((resolve) => setTimeout(resolve, 0))

/** Un refus de la séance au rejeu : une autre était en cours. */
const unRefus = async () => {
    throw { response: { status: 409, data: { raison: 'seance-deja-en-cours', seance: { id: 9 } } } }
}

beforeEach(async () => {
    localStorage.clear()
    serveur.reponses.length = 0
    Object.values(inertia.router).forEach((appel) => appel.mockClear())
    inertia.page = reactive({
        component: 'Dashboard',
        url: '/dashboard',
        version: 'v1',
        props: { auth: { user: { id: 1, active_workout: null } }, flash: {} },
    })

    sync = await chargerSyncService({ compte: 1 })
    await sync.pending
    module = await import('@/composables/useSeanceEnCoursDeLAppareil')
})

afterEach(async () => {
    await pages.demonterToutes()
    retirerLesEcouteursDuService()
    poserLaPage(null)
    localStorage.clear()
})

describe('la séance en cours', () => {
    it('est celle du serveur, puis celle qu’une création en file fait naître, dès son entrée en file', async () => {
        inertia.page.props.auth.user.active_workout = { id: 9, name: 'Push A' }
        const { seanceEnCours } = await monter()

        expect(seanceEnCours.value).toEqual({ id: 9, name: 'Push A' })

        inertia.page.props.auth.user.active_workout = null
        const queueId = mettreLaSeanceEnFile()
        await flushPromises()

        expect(seanceEnCours.value).toMatchObject({
            id: `temp-q${queueId}`,
            enAttenteDe: queueId,
            idempotency_key: CLE,
        })
    })

    it('s’ouvre côté client depuis sa création en file, et sinon par l’adresse de sa clé', async () => {
        const queueId = mettreLaSeanceEnFile()
        const { seanceEnCours, ouvrir } = await monter()

        await ouvrir(seanceEnCours.value)

        expect(inertia.router.push).toHaveBeenCalledTimes(1)
        expect(inertia.router.push.mock.calls[0][0]).toMatchObject({
            component: 'Workouts/Show',
            url: `/workouts/en-attente/${CLE}`,
            props: { workout: { id: `temp-q${queueId}` } },
        })

        await ouvrir({ enAttenteDe: 'partie', idempotency_key: CLE })

        expect(inertia.router.visit).toHaveBeenCalledWith(`/workouts/en-attente/${CLE}`)
    })
})

describe('la création rejouée', () => {
    it('redemande le compte, qui porte désormais la séance', async () => {
        mettreLaSeanceEnFile()
        await monter()

        serveur.reponses.push(async () => ({ status: 201, data: { data: { id: 42 } } }))
        await sync.processQueue()

        expect(inertia.router.reload).toHaveBeenCalledWith({ only: ['auth'] })
    })

    it.each([
        ['sur une copie hors ligne', { props: { horsLigne: { copieDu: 1, raison: 'reseau', datesDesProps: {} } } }],
        ['sur la séance en attente elle-même', { component: 'Workouts/Show', url: `/workouts/en-attente/${CLE}` }],
    ])('ne redemande rien %s', async (_cas, page) => {
        mettreLaSeanceEnFile()
        Object.assign(inertia.page, { ...page, props: { ...inertia.page.props, ...page.props } })
        await monter()

        serveur.reponses.push(async () => ({ status: 201, data: { data: { id: 42 } } }))
        await sync.processQueue()

        expect(inertia.router.reload).not.toHaveBeenCalled()
    })
})

describe('la création refusée', () => {
    it('se dit une fois, avec ce qu’elle emporte', async () => {
        const queueId = mettreLaSeanceEnFile()
        sync.mettreEnFile({
            method: 'post',
            url: '/api/v1/workout-lines',
            data: { workout_id: { enAttenteDe: queueId }, exercise_id: 8 },
        })
        await monter()

        serveur.reponses.push(unRefus)
        await sync.processQueue()
        await uneTache()

        expect(inertia.page.props.flash.error).toBe(
            "La séance démarrée sans réseau n'a pas été enregistrée : une autre séance était déjà en cours. 1 modification de cette séance ne partira pas.",
        )
        expect(sync.failedRequests().find((refus) => refus.id === queueId)).toMatchObject({ annonce: true })

        // Un autre écran : le refus déjà dit ne revient pas.
        inertia.page.props.flash.error = null
        await pages.demonterToutes()
        await monter()
        await uneTache()

        expect(inertia.page.props.flash.error).toBeNull()
    })

    it('se dit au montage quand il est arrivé sur un autre écran', async () => {
        mettreLaSeanceEnFile()
        serveur.reponses.push(async () => {
            throw { response: { status: 422, data: { message: 'started_at' } } }
        })
        await sync.processQueue()

        await monter()
        await uneTache()

        expect(inertia.page.props.flash.error).toBe("La séance démarrée sans réseau n'a pas été enregistrée.")
    })
})
