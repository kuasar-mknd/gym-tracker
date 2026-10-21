import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { h, reactive } from 'vue'

import { chargerSyncService, poserLaPage, retirerLesEcouteursDuService } from '../utils/fileHorsLigne'
import { suivreLesPagesMontees } from '../pages/pagesMontees'
import { MODELE_SANS_RESEAU } from '@/Utils/visitesHorsLigne'

const inertia = vi.hoisted(() => ({
    router: { visit: vi.fn(), push: vi.fn(), replace: vi.fn() },
    page: null,
}))

/** Ce que le serveur rend, requête par requête ; sans réponse prévue, le réseau manque. */
const serveur = vi.hoisted(() => ({ requetes: [], reponses: [] }))

vi.mock('@/Utils/http', () => ({
    http: async (config) => {
        serveur.requetes.push({ method: config.method, url: config.url, data: config.data })

        const reponse = serveur.reponses.shift()

        if (reponse === undefined) {
            throw { code: 'ERR_NETWORK', request: {} }
        }

        return reponse()
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
        'api.v1.workout-lines.store': '/api/v1/workout-lines',
        'api.v1.sets.store': '/api/v1/sets',
        'api.v1.sets.update': `/api/v1/sets/${parametres?.set}`,
        'workouts.show': `/workouts/${parametres}`,
    })[nom]

const terminer = (workoutId) =>
    sync.mettreEnFile({
        method: 'patch',
        url: '/api/v1/workouts/finish',
        data: { workout_id: workoutId, ended_at: '2026-10-19T18:42:00.000Z' },
    })

const demarrer = async (...parametres) => {
    let demarrage

    pages.retenir(
        mount({
            setup() {
                demarrage = module.useDemarrageDUneSeance()

                return () => h('div')
            },
        }),
    )

    await demarrage.demarrer(...parametres)
    await flushPromises()
}

/** Les créations de séance en file, dans l'ordre, avec ce qui les suit. */
const file = () => sync.entreesDuCompte().map((entree) => `${entree.method} ${entree.url}`)

beforeEach(async () => {
    localStorage.clear()
    serveur.requetes.length = 0
    serveur.reponses.length = 0
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
    retirerLesEcouteursDuService()
    poserLaPage(null)
    localStorage.clear()

    /*
     * Une réponse du serveur relance la file une tâche plus tard (#2049) : la
     * relance de ce cas partait pendant le suivant, et vidait sa file, son
     * stockage étant le même. La file vidée d'abord, la relance attendue
     * ensuite ne trouve rien.
     */
    const { noterQueLeServeurRepond } = await import('@/composables/useEtatDuReseau')
    noterQueLeServeurRepond()
    await new Promise((resolve) => setTimeout(resolve, 0))
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

/*
 * Le verrou de l'onglet (#2049, relu le 2026-10-21) : il est au niveau du
 * module, et tient pour tous les boutons ensemble. Certains ne se désactivent
 * pas pendant le démarrage (l'état vide de la liste des séances) : pour eux,
 * il est la seule garde contre un second démarrage sous une autre clé, donc
 * une seconde séance.
 */
describe('le verrou de l’onglet', () => {
    /** Un bouton qui démarre, monté à part, avec son propre appel au composable. */
    const unBouton = () => {
        let demarrage

        pages.retenir(
            mount({
                setup() {
                    demarrage = module.useDemarrageDUneSeance()

                    return () => h('div')
                },
            }),
        )

        return demarrage
    }

    it('ne part qu’une fois quand deux boutons démarrent pendant que la première réponse se fait attendre', async () => {
        const { noterQueLeServeurRepond } = await import('@/composables/useEtatDuReseau')
        noterQueLeServeurRepond()
        let repondre = () => {}
        serveur.reponses.push(
            () =>
                new Promise((resolve) => {
                    repondre = () => resolve({ status: 201, data: { data: { id: 42 } } })
                }),
        )
        const accueil = unBouton()
        const barreDuBas = unBouton()

        const premier = accueil.demarrer()
        await flushPromises()

        expect(barreDuBas.enCours.value).toBe(true)

        await barreDuBas.demarrer()
        repondre()
        await premier
        await flushPromises()

        expect(serveur.requetes.filter((requete) => requete.url === '/api/v1/workouts')).toHaveLength(1)
        expect(inertia.router.visit).toHaveBeenCalledTimes(1)
        expect(inertia.router.visit).toHaveBeenCalledWith('/workouts/42', expect.any(Object))
    })
})

/*
 * Lancer un modèle (#2049) : `demarrer(modele)`, depuis la page des modèles,
 * qui porte de quoi le recopier sans réseau (`lancements`).
 */
describe('lancer un modèle', () => {
    const PUSH = { id: 3, name: 'Push A' }
    const LANCEMENT = {
        name: 'Push A',
        lignes: [
            {
                exercise: { id: 8, name: 'Développé couché', type: 'strength' },
                order: 0,
                cle: 'modele-ligne:0:8',
                series: [{ reps: 8, weight: 80, is_warmup: false, cle: 'modele-serie:0:0' }],
            },
        ],
    }

    /** La page des modèles, avec ce que le serveur donne pour les lancer. */
    const surLaPageDesModeles = (lancements = { 3: LANCEMENT }) => {
        inertia.page.component = 'Workouts/Templates/Index'
        inertia.page.url = '/templates'
        inertia.page.props.lancements = lancements
    }

    /** Une page de séance arrive, par quelque voie que ce soit. */
    const uneSeanceArrive = async (url) => {
        inertia.page.component = 'Workouts/Show'
        inertia.page.url = url
        inertia.page.props = { auth: inertia.page.props.auth, flash: {} }
        await flushPromises()
    }

    it('sans réseau, met en file la séance, ses lignes et ses séries sans les tenter, et l’ouvre avec les exercices du modèle', async () => {
        surLaPageDesModeles()

        await demarrer(PUSH)

        const [seance, ligne, serie] = sync.entreesDuCompte()

        expect(file()).toEqual(['post /api/v1/workouts', 'post /api/v1/workout-lines', 'post /api/v1/sets'])
        expect(seance.data).toEqual({ started_at: expect.any(String), name: 'Push A' })
        expect(ligne.headers['Idempotency-Key']).toBe('modele-ligne:0:8')
        expect(serie.headers['Idempotency-Key']).toBe('modele-serie:0:0')
        expect(sync.entreesDuCompte().some((entree) => entree.tentee)).toBe(false)
        expect(serveur.requetes).toEqual([])

        const poussee = inertia.router.push.mock.calls[0][0]

        expect(poussee.props.workout.id).toBe(`temp-q${seance.id}`)
        expect(poussee.props.exercises).toEqual([{ id: 8, name: 'Développé couché', type: 'strength' }])
    })

    it('sans réseau et sans le contenu du modèle, ne met rien en file et le dit', async () => {
        surLaPageDesModeles({})
        const annonces = []
        const noter = (evenement) => annonces.push(evenement.detail.message)
        window.addEventListener('gym:sans-reseau', noter)

        await demarrer(PUSH)
        window.removeEventListener('gym:sans-reseau', noter)

        expect(annonces).toEqual([MODELE_SANS_RESEAU])
        expect(file()).toEqual([])
        expect(inertia.router.push).not.toHaveBeenCalled()
    })

    it('ouvre la séance déjà en cours, et dit sur elle que le modèle n’a pas été démarré', async () => {
        surLaPageDesModeles()
        inertia.page.props.auth.user.active_workout = { id: 9, name: 'Jambes', idempotency_key: 'cle-9' }

        await demarrer(PUSH)

        expect(inertia.router.visit).toHaveBeenCalledWith('/workouts/9')
        expect(file()).toEqual([])

        await uneSeanceArrive('/workouts/9')

        expect(inertia.page.props.flash.error).toBe(module.messageDuModeleNonDemarre('Push A'))
    })

    it('en ligne, une seule requête avec le modèle ; sans réponse, sa séance entre en file, tentée, et s’ouvre', async () => {
        surLaPageDesModeles()
        const { noterQueLeServeurRepond } = await import('@/composables/useEtatDuReseau')
        noterQueLeServeurRepond()

        await demarrer(PUSH)

        expect(serveur.requetes).toEqual([
            { method: 'post', url: '/api/v1/workouts', data: { started_at: expect.any(String), template_id: 3 } },
        ])

        const entrees = sync.entreesDuCompte()

        expect(file()).toEqual(['post /api/v1/workouts', 'post /api/v1/workout-lines', 'post /api/v1/sets'])
        // La création en file ne porte pas le modèle : seul ce qui est en file se crée.
        expect(entrees[0].data).not.toHaveProperty('template_id')
        expect(entrees.map((entree) => entree.tentee)).toEqual([true, true, true])
        expect(inertia.router.push.mock.calls[0][0].props.workout.id).toBe(`temp-q${entrees[0].id}`)
    })

    it('en ligne, la séance que le serveur dit en cours s’ouvre, avec le message du modèle', async () => {
        surLaPageDesModeles()
        const { noterQueLeServeurRepond } = await import('@/composables/useEtatDuReseau')
        noterQueLeServeurRepond()
        serveur.reponses.push(() => {
            throw {
                response: {
                    status: 409,
                    data: {
                        message: 'Une séance est déjà en cours.',
                        raison: 'seance-deja-en-cours',
                        seance: { id: 9 },
                    },
                },
            }
        })

        await demarrer(PUSH)

        expect(inertia.router.visit).toHaveBeenCalledWith('/workouts/9')
        expect(file()).toEqual([])

        await uneSeanceArrive('/workouts/9')

        expect(inertia.page.props.flash.error).toBe(module.messageDuModeleNonDemarre('Push A'))
    })

    it('ne prend pas l’évènement d’un clic pour un modèle', async () => {
        await demarrer(new MouseEvent('click'))

        expect(file()).toEqual(['post /api/v1/workouts'])
        expect(sync.entreesDuCompte()[0].data).toEqual({ started_at: expect.any(String) })
    })

    it('ne dit rien sur une page qui n’est pas une séance, puis plus rien ensuite', async () => {
        surLaPageDesModeles()
        inertia.page.props.auth.user.active_workout = { id: 9, name: 'Jambes', idempotency_key: 'cle-9' }

        await demarrer(PUSH)

        inertia.page.url = '/dashboard'
        inertia.page.component = 'Dashboard'
        await flushPromises()

        expect(inertia.page.props.flash.error).toBeUndefined()

        await uneSeanceArrive('/workouts/9')

        expect(inertia.page.props.flash.error).toBeUndefined()
    })
})
