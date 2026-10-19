import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { h, reactive } from 'vue'

import { chargerSyncService, poserLaPage, retirerLesEcouteursDuService } from '../utils/fileHorsLigne'
import { suivreLesPagesMontees } from '../pages/pagesMontees'

const inertia = vi.hoisted(() => ({
    router: { visit: vi.fn(), push: vi.fn(), replace: vi.fn() },
    page: null,
}))

vi.mock('@/Utils/http', () => ({
    http: async () => {
        throw { code: 'ERR_NETWORK', request: {} }
    },
}))
vi.mock('@inertiajs/vue3', () => ({ router: inertia.router, usePage: () => inertia.page }))
vi.mock('@/Utils/copiesHorsLigne', () => ({ magasinDesCopies: () => ({ lireUneCopie: async () => null }) }))
vi.mock('@/composables/useHaptics', () => ({ triggerHaptic: vi.fn() }))

/*
 * « Démarrer » après « Terminer » sans réseau (#2049) : la fin attend la file,
 * et la séance qu'elle clôt n'est plus en cours. Démarrer ne la rouvre pas,
 * ni celle du serveur, ni celle qu'une création en file fait naître : une
 * nouvelle séance se crée, derrière cette fin.
 */

const pages = suivreLesPagesMontees()

let sync
let module

const routeDeTest = (nom, parametres) =>
    ({
        'api.v1.workouts.store': '/api/v1/workouts',
        'workouts.show': `/workouts/${parametres}`,
    })[nom]

const terminer = (workoutId) =>
    sync.mettreEnFile({
        method: 'patch',
        url: '/api/v1/workouts/finish',
        data: { workout_id: workoutId, ended_at: '2026-10-19T18:42:00.000Z' },
    })

const demarrer = async () => {
    let demarrage

    pages.retenir(
        mount({
            setup() {
                demarrage = module.useDemarrageDUneSeance()

                return () => h('div')
            },
        }),
    )

    await demarrage.demarrer()
    await flushPromises()
}

/** Les créations de séance en file, dans l'ordre, avec ce qui les suit. */
const file = () => sync.entreesDuCompte().map((entree) => `${entree.method} ${entree.url}`)

beforeEach(async () => {
    localStorage.clear()
    Object.values(inertia.router).forEach((appel) => appel.mockClear())
    globalThis.route = routeDeTest
    inertia.page = reactive({
        component: 'Dashboard',
        url: '/dashboard',
        version: 'v1',
        props: { auth: { user: { id: 1, active_workout: null } }, flash: {} },
    })

    sync = await chargerSyncService({ compte: 1 })
    await sync.pending
    module = await import('@/composables/useDemarrageDUneSeance')

    const { noterQueLeServeurNeRepondPas } = await import('@/composables/useEtatDuReseau')
    noterQueLeServeurNeRepondPas()
})

afterEach(async () => {
    await pages.demonterToutes()
    const { noterQueLeServeurRepond } = await import('@/composables/useEtatDuReseau')
    noterQueLeServeurRepond()
    retirerLesEcouteursDuService()
    poserLaPage(null)
    localStorage.clear()
})

describe('démarrer après une séance terminée sans réseau', () => {
    it('ne rouvre pas la séance du serveur que la fin clôt : une nouvelle se crée derrière elle', async () => {
        inertia.page.props.auth.user.active_workout = { id: 9, name: 'Push A', idempotency_key: 'cle-9' }
        terminer(9)

        await demarrer()

        expect(inertia.router.visit).not.toHaveBeenCalled()
        expect(file()).toEqual(['patch /api/v1/workouts/finish', 'post /api/v1/workouts'])
        expect(inertia.router.push).toHaveBeenCalledTimes(1)
        expect(inertia.router.push.mock.calls[0][0].props.workout.id).toBe(`temp-q${sync.entreesDuCompte()[1].id}`)
    })

    it('ne rouvre pas la séance démarrée sans réseau que la fin clôt', async () => {
        const premiere = sync.mettreEnFile({ method: 'post', url: '/api/v1/workouts', data: {} })
        terminer({ enAttenteDe: premiere })

        await demarrer()

        expect(file()).toEqual(['post /api/v1/workouts', 'patch /api/v1/workouts/finish', 'post /api/v1/workouts'])
        expect(inertia.router.push.mock.calls[0][0].props.workout.id).not.toBe(`temp-q${premiere}`)
    })

    it('rouvre toujours la séance en cours, que rien ne clôt', async () => {
        inertia.page.props.auth.user.active_workout = { id: 9, name: 'Push A', idempotency_key: 'cle-9' }
        terminer(8)

        await demarrer()

        expect(inertia.router.visit).toHaveBeenCalledWith('/workouts/9')
        expect(file()).toEqual(['patch /api/v1/workouts/finish'])
    })
})
