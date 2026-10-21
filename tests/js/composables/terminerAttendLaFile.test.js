import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'

import { retirerLesEcouteursDuService, poserLaPage } from '../utils/fileHorsLigne'
import {
    creerUnFauxServeur,
    fileDurable,
    monterLaSeance,
    routeDeTest,
    seances,
    uneReponsePerdue,
} from './seanceHorsLigne'

const reseau = vi.hoisted(() => ({ serveur: null }))
const inertia = vi.hoisted(() => ({ patch: vi.fn(), visit: vi.fn() }))

vi.mock('@/Utils/http', () => ({ http: (config) => reseau.serveur.repondre(config) }))
vi.mock('@inertiajs/vue3', () => ({
    router: { post: vi.fn(), patch: (...args) => inertia.patch(...args), visit: inertia.visit, flash: vi.fn() },
    useForm: (champs) => ({ ...champs, transform: vi.fn(), patch: vi.fn() }),
    // Les réglages se recalent à la sortie d'une copie hors ligne : la page du serveur ici (#2048).
    usePage: () => ({ props: {} }),
}))
vi.mock('@/composables/useHaptics', () => ({ triggerHaptic: vi.fn() }))
vi.mock('@formkit/drag-and-drop/vue', () => ({ dragAndDrop: () => {} }))

/**
 * « Terminer » n'attendait que la saisie en cours, pas la file hors ligne. La
 * clôture partait hors de la file ; les écritures qui y restaient revenaient
 * ensuite refusées, puisqu'une séance close n'accepte plus de série, et la page
 * était déjà partie (#1961). Deux cas : le réseau revenu sans évènement
 * `online`, et un vidage déjà en cours au moment de confirmer.
 *
 * Depuis #2049, la fin passe elle-même par la file (`PATCH
 * /api/v1/workouts/finish`, à l'heure du geste) : quand la file ne se vide pas,
 * la fin s'y range derrière les écritures qui attendent, et la séance se
 * montre close au lieu de rester ouverte. Seule une écriture encore en vol au
 * bout de l'attente empêche la fin.
 */

const seance = () => ({
    id: 5,
    name: 'Jambes',
    started_at: '2026-10-05T10:00:00.000000Z',
    notes: null,
    workout_lines: [
        {
            id: 1,
            exercise_id: 7,
            exercise: { id: 7, name: 'Squat', type: 'strength' },
            recommended_values: null,
            sets: [{ id: 3, weight: 80, reps: 5, is_completed: true, is_warmup: false }],
        },
    ],
})

/** Ce que voyaient la file et le serveur au moment où la fin de la séance a atteint le serveur. */
let aLaCloture

/** Les fins de séance que la file garde, telles qu'un rechargement les relirait. */
const finsEnFile = () => fileDurable().filter((entree) => entree.url === '/api/v1/workouts/finish')

beforeEach(() => {
    localStorage.clear()
    reseau.serveur = creerUnFauxServeur()
    globalThis.route = routeDeTest
    aLaCloture = []
    inertia.patch.mockReset()
    inertia.visit.mockReset()

    const repondre = reseau.serveur.repondre

    reseau.serveur.repondre = (config) => {
        if (reseau.serveur.enLigne && config.url === '/api/v1/workouts/finish') {
            aLaCloture.push({
                url: config.url,
                donnees: config.data,
                enFile: fileDurable().length,
                recues: reseau.serveur.resume(),
            })
        }

        return repondre(config)
    }
})

afterEach(async () => {
    await seances.demonterToutes()
    vi.useRealTimers()
    retirerLesEcouteursDuService()
    poserLaPage(null)
})

const ajouterUneSerieHorsLigne = async (page) => {
    reseau.serveur.enLigne = false
    page.addSet(1)
    await flushPromises()
}

describe('terminer une séance', () => {
    it('vide la file avant de clore, quand le réseau est revenu sans évènement', async () => {
        const page = await monterLaSeance(seance())
        await ajouterUneSerieHorsLigne(page)

        reseau.serveur.enLigne = true
        page.finishWorkout()
        await page.confirmFinishWorkout()

        expect(aLaCloture).toEqual([
            {
                url: '/api/v1/workouts/finish',
                donnees: { workout_id: 5, ended_at: expect.any(String) },
                enFile: 0,
                recues: ['post /api/v1/sets {"workout_line_id":1,"is_completed":false,"weight":80,"reps":5}'],
            },
        ])
        expect(page.ecrituresEnAttente.value).toBe(0)
        expect(inertia.patch).not.toHaveBeenCalled()
        expect(inertia.visit).toHaveBeenCalledWith('/dashboard', expect.any(Object))
    })

    it('attend la fin d’un vidage déjà en cours avant de clore', async () => {
        const page = await monterLaSeance(seance())
        await ajouterUneSerieHorsLigne(page)

        let repondre
        const reponse = new Promise((resolve) => (repondre = resolve))
        reseau.serveur.imposer.push(async (config) => {
            await reponse

            return { data: { data: { id: 100, ...config.data } } }
        })

        // Le retour du réseau a lancé un vidage, qui attend encore le serveur.
        reseau.serveur.enLigne = true
        page.sync.processQueue()
        await flushPromises()

        page.finishWorkout()
        const cloture = page.confirmFinishWorkout()
        await flushPromises()

        expect(aLaCloture).toEqual([])

        repondre()
        await cloture

        expect(aLaCloture).toHaveLength(1)
        expect(aLaCloture[0].enFile).toBe(0)
        expect(aLaCloture[0].recues).toHaveLength(1)
    })

    /*
     * La séance restait ouverte, et la modale disait combien de modifications
     * attendaient (#1961). La fin s'y range désormais derrière elles (#2049) :
     * la séance se montre close, et la file les envoie avant elle.
     */
    it('se montre close, sa fin en file derrière les modifications qui attendent, et les suit au retour du réseau', async () => {
        const page = await monterLaSeance(seance())
        await ajouterUneSerieHorsLigne(page)
        page.addSet(1)
        await flushPromises()

        page.finishWorkout()
        await page.confirmFinishWorkout()

        expect(aLaCloture).toEqual([])
        expect(page.showFinishModal.value).toBe(false)
        expect(page.ecrituresEnAttente.value).toBe(0)
        expect(fileDurable().map((entree) => entree.url)).toEqual([
            '/api/v1/sets',
            '/api/v1/sets',
            '/api/v1/workouts/finish',
        ])
        expect(page.localWorkout.value).toMatchObject({ _finEnAttente: finsEnFile()[0].id })
        expect(page.localWorkout.value.ended_at).toBe(finsEnFile()[0].data.ended_at)

        // Le réseau revient : la file envoie les séries, puis la fin.
        reseau.serveur.enLigne = true
        await page.sync.processQueue()

        expect(aLaCloture).toHaveLength(1)
        expect(aLaCloture[0].enFile).toBe(1)
        expect(aLaCloture[0].recues).toHaveLength(2)
        expect(fileDurable()).toEqual([])
    })

    it('se montre close, sa fin derrière l’écriture que le serveur fait attendre par une erreur passagère', async () => {
        const page = await monterLaSeance(seance())
        await ajouterUneSerieHorsLigne(page)
        // La relance programmée après l'erreur ne doit pas survivre au test.
        vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] })
        reseau.serveur.enLigne = true
        reseau.serveur.imposer.push(() => Promise.reject({ response: { status: 503 } }))

        page.finishWorkout()
        const cloture = page.confirmFinishWorkout()
        await vi.advanceTimersByTimeAsync(0)
        await cloture

        expect(aLaCloture).toEqual([])
        expect(page.ecrituresEnAttente.value).toBe(0)
        expect(fileDurable().map((entree) => entree.url)).toEqual(['/api/v1/sets', '/api/v1/workouts/finish'])
        expect(page.localWorkout.value._finEnAttente).toBe(finsEnFile()[0].id)
    })

    /*
     * Ce qui empêche encore la fin (#1961) : une écriture partie sans réponse
     * encore, qui arriverait après elle, sur une séance close.
     */
    it('reste ouverte et dit combien de modifications sont encore en route, au bout de l’attente maximale', async () => {
        const page = await monterLaSeance(seance())
        const { ATTENTE_MAX_DE_LA_FILE_MS } = await import('@/composables/useReglagesDeLaSeance')
        // Une série ajoutée en ligne, dont la création reste sans réponse.
        reseau.serveur.imposer.push(() => new Promise(() => {}))
        page.addSet(1)
        await flushPromises()

        vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] })
        page.finishWorkout()
        const cloture = page.confirmFinishWorkout()
        await vi.advanceTimersByTimeAsync(ATTENTE_MAX_DE_LA_FILE_MS)
        await cloture

        expect(page.sync.ecrituresEnCours()).toBe(1)
        expect(page.ecrituresEnAttente.value).toBe(1)
        expect(page.showFinishModal.value).toBe(true)
        expect(page.clotureEnCours.value).toBe(false)
        expect(aLaCloture).toEqual([])
        expect(finsEnFile()).toEqual([])
        expect(page.localWorkout.value.ended_at).toBeFalsy()
    })
})

describe('terminer pendant un vidage qui traîne', () => {
    /** Le réseau est revenu, mais la requête du vidage tarde : rend de quoi la libérer. */
    const unVidageQuiTraine = async (page) => {
        await ajouterUneSerieHorsLigne(page)

        let liberer
        reseau.serveur.enLigne = true
        reseau.serveur.imposer.push(async (config) => {
            await new Promise((resolve) => (liberer = resolve))

            return { data: { data: { id: 100, ...config.data, created_at: 'c', updated_at: 'u' } } }
        })
        page.sync.processQueue()
        await flushPromises()

        return () => liberer()
    }

    it('montre l’attente et ne clôt qu’une fois, même appuyé deux fois', async () => {
        const page = await monterLaSeance(seance())
        const liberer = await unVidageQuiTraine(page)

        page.finishWorkout()
        const premier = page.confirmFinishWorkout()
        await flushPromises()
        const second = page.confirmFinishWorkout()
        await flushPromises()

        expect(page.clotureEnCours.value).toBe(true)
        expect(aLaCloture).toEqual([])

        liberer()
        await premier
        await second

        expect(aLaCloture).toHaveLength(1)
        expect(aLaCloture[0].enFile).toBe(0)
        expect(inertia.visit).toHaveBeenCalledTimes(1)
        expect(page.clotureEnCours.value).toBe(false)
    })

    /*
     * « Annuler », le fond ou Échap pendant l'attente fermaient la modale, et
     * la clôture partait quand même une fois l'attente finie, jusqu'à huit
     * secondes plus tard. Une séance close ne se rouvre pas.
     */
    it('ne clôt pas quand on annule pendant l’attente, et une nouvelle confirmation ne clôt qu’une fois', async () => {
        const page = await monterLaSeance(seance())
        const liberer = await unVidageQuiTraine(page)

        page.finishWorkout()
        const abandonnee = page.confirmFinishWorkout()
        await flushPromises()

        page.annulerLaCloture()

        expect(page.showFinishModal.value).toBe(false)
        expect(page.clotureEnCours.value).toBe(false)

        // On se ravise et on confirme de nouveau, pendant que le vidage traîne encore.
        page.finishWorkout()
        const reprise = page.confirmFinishWorkout()
        await flushPromises()

        expect(page.clotureEnCours.value).toBe(true)

        liberer()
        await abandonnee
        await reprise

        expect(aLaCloture).toHaveLength(1)
        expect(aLaCloture[0].enFile).toBe(0)
    })

    it('ne clôt jamais une tentative abandonnée, même une fois la file vidée', async () => {
        const page = await monterLaSeance(seance())
        const liberer = await unVidageQuiTraine(page)

        page.finishWorkout()
        const cloture = page.confirmFinishWorkout()
        await flushPromises()

        page.annulerLaCloture()
        liberer()
        await cloture
        await flushPromises()

        expect(aLaCloture).toEqual([])
        expect(page.sync.queue).toEqual([])
        expect(page.clotureEnCours.value).toBe(false)
        expect(page.localWorkout.value.ended_at).toBeFalsy()
    })

    /*
     * La séance restait ouverte, la modale disant ce qui attendait (#1961) :
     * au bout de l'attente, la fin se range derrière l'écriture qui traîne
     * (#2049), et le vidage en cours l'enverra après elle.
     */
    it('rend la main au bout de l’attente maximale : la fin attend en file derrière l’écriture qui traîne', async () => {
        const page = await monterLaSeance(seance())
        const liberer = await unVidageQuiTraine(page)
        const { ATTENTE_MAX_DE_LA_FILE_MS } = await import('@/composables/useReglagesDeLaSeance')
        vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout'] })

        page.finishWorkout()
        const cloture = page.confirmFinishWorkout()
        await vi.advanceTimersByTimeAsync(ATTENTE_MAX_DE_LA_FILE_MS)
        await cloture

        expect(aLaCloture).toEqual([])
        expect(page.ecrituresEnAttente.value).toBe(0)
        expect(page.clotureEnCours.value).toBe(false)
        expect(page.showFinishModal.value).toBe(false)
        expect(fileDurable().map((entree) => entree.url)).toEqual(['/api/v1/sets', '/api/v1/workouts/finish'])

        // L'écriture qui traînait aboutit : le vidage envoie la fin derrière elle.
        vi.useRealTimers()
        liberer()
        await vi.waitUntil(() => aLaCloture.length === 1)

        expect(aLaCloture[0].recues.map((recue) => recue.split(' ')[1])).toEqual(['/api/v1/sets'])
    })
})

/**
 * Le vidage déclenche lui-même des écritures : le rattrapage d'une création
 * dont le serveur a ignoré la charge, et ce qui a été tapé pendant qu'une
 * création rejouée volait, que l'adoption renvoie hors de la file. La clôture
 * ne regardait que la file : elle partait pendant qu'elles volaient encore, et
 * le serveur, qui traite les deux en parallèle, pouvait refuser la correction
 * d'une séance déjà close.
 */
describe('terminer pendant que le vidage écrit encore', () => {
    /** Retient au réseau chaque modification qui atteint le serveur : rend celles en vol et de quoi les libérer. */
    const retenirLesModifications = () => {
        const enVol = []
        const liberations = []
        const repondre = reseau.serveur.repondre

        reseau.serveur.repondre = async (config) => {
            if (config.method === 'patch' && config.url.startsWith('/api/v1/sets/')) {
                enVol.push(`${config.method} ${config.url} ${JSON.stringify(config.data)}`)
                await new Promise((resolve) => liberations.push(resolve))
            }

            return repondre(config)
        }

        return { enVol, liberer: () => liberations.splice(0).forEach((resolve) => resolve()) }
    }

    it('attend le rattrapage d’une réponse perdue, mis en file à la place de la création', async () => {
        const page = await monterLaSeance(seance())
        uneReponsePerdue(reseau.serveur)
        page.addSet(1)
        await flushPromises()
        page.saisieTerminee(page.ligne().sets.at(-1), 'reps', '3')
        await flushPromises()

        // Le réseau revient sans évènement ; le rattrapage traîne.
        reseau.serveur.enLigne = true
        const modifications = retenirLesModifications()

        page.finishWorkout()
        const cloture = page.confirmFinishWorkout()
        await flushPromises()

        expect(modifications.enVol).toEqual(['patch /api/v1/sets/100 {"reps":3}'])
        expect(aLaCloture).toEqual([])

        modifications.liberer()
        await cloture

        expect(aLaCloture).toHaveLength(1)
        expect(aLaCloture[0].recues.at(-1)).toBe('patch /api/v1/sets/100 {"reps":3}')
        expect(reseau.serveur.series.get(100)).toMatchObject({ reps: 3 })
    })

    it('attend ce que l’adoption renvoie hors de la file : la saisie faite pendant que la création rejouée volait', async () => {
        const page = await monterLaSeance(seance())
        await ajouterUneSerieHorsLigne(page)
        const serie = page.ligne().sets.at(-1)

        let libererLaCreation
        reseau.serveur.enLigne = true
        reseau.serveur.imposer.push(async (config) => {
            await new Promise((resolve) => (libererLaCreation = resolve))

            return { data: { data: { id: 100, ...config.data, created_at: 'c', updated_at: 'u' } } }
        })
        page.sync.processQueue()
        await flushPromises()

        // Tapé pendant que la création vole : rien où se fondre, l'adoption le renverra.
        page.saisieTerminee(serie, 'reps', '3')
        const modifications = retenirLesModifications()

        page.finishWorkout()
        const cloture = page.confirmFinishWorkout()
        await flushPromises()

        libererLaCreation()
        await flushPromises()

        expect(modifications.enVol).toEqual(['patch /api/v1/sets/100 {"reps":3}'])
        expect(page.sync.queue).toEqual([])
        expect(aLaCloture).toEqual([])

        modifications.liberer()
        await cloture

        expect(aLaCloture).toHaveLength(1)
        expect(aLaCloture[0].recues.at(-1)).toBe('patch /api/v1/sets/100 {"reps":3}')
    })

    it('attend la coche faite pendant que la création rejouée volait, qui part quelques étapes après elle', async () => {
        const page = await monterLaSeance(seance())
        await ajouterUneSerieHorsLigne(page)
        const serie = page.ligne().sets.at(-1)

        let libererLaCreation
        reseau.serveur.enLigne = true
        reseau.serveur.imposer.push(async (config) => {
            await new Promise((resolve) => (libererLaCreation = resolve))

            return { data: { data: { id: 100, ...config.data, created_at: 'c', updated_at: 'u' } } }
        })
        page.sync.processQueue()
        await flushPromises()

        // Cochée pendant que la création vole : la coche attend son identifiant.
        page.toggleSetCompletion(serie)
        const modifications = retenirLesModifications()

        page.finishWorkout()
        const cloture = page.confirmFinishWorkout()
        await flushPromises()

        libererLaCreation()
        await flushPromises()

        expect(modifications.enVol).toEqual(['patch /api/v1/sets/100 {"is_completed":true}'])
        expect(aLaCloture).toEqual([])

        modifications.liberer()
        await cloture

        expect(aLaCloture).toHaveLength(1)
        expect(aLaCloture[0].recues.at(-1)).toBe('patch /api/v1/sets/100 {"is_completed":true}')
        expect(page.ecrituresEnAttente.value).toBe(0)
    })
})

describe('la modale de fin', () => {
    const modale = async (enAttente) => {
        const WorkoutFinishModal = (await import('@/Components/Workout/WorkoutFinishModal.vue')).default

        return mount(WorkoutFinishModal, {
            props: { show: true, enAttente },
            global: { stubs: { Modal: { template: '<div><slot /></div>' } } },
        })
    }

    /*
     * Ce qui attend en file ne retient plus la fin (#2049) : la modale ne dit
     * plus que les modifications encore en route vers le serveur.
     */
    it('dit combien de modifications sont encore en route, et que la séance reste ouverte ; rien quand rien n’attend', async () => {
        expect((await modale(3)).find('[dusk="finish-workout-pending"]').text()).toBe(
            '3 modifications sont encore en route vers le serveur. La séance reste ouverte : réessaie dans un instant.',
        )
        expect((await modale(1)).find('[dusk="finish-workout-pending"]').text()).toBe(
            'Une modification est encore en route vers le serveur. La séance reste ouverte : réessaie dans un instant.',
        )
        expect((await modale(0)).find('[dusk="finish-workout-pending"]').exists()).toBe(false)
    })

    it('montre l’envoi en cours, et « Confirmer » ne se rappuie pas', async () => {
        const WorkoutFinishModal = (await import('@/Components/Workout/WorkoutFinishModal.vue')).default
        const enCours = mount(WorkoutFinishModal, {
            props: { show: true, enAttente: 2, enCours: true },
            global: { stubs: { Modal: { template: '<div><slot /></div>' } } },
        })

        expect(enCours.find('[dusk="finish-workout-sending"]').text()).toBe('Envoi des modifications en attente…')
        expect(enCours.find('[dusk="finish-workout-pending"]').exists()).toBe(false)
        expect(enCours.find('[dusk="confirm-finish-button"]').attributes('disabled')).toBeDefined()

        await enCours.find('[dusk="confirm-finish-button"]').trigger('click')
        expect(enCours.emitted('confirm')).toBeUndefined()

        expect((await modale(0)).find('[dusk="finish-workout-sending"]').exists()).toBe(false)
    })
})
