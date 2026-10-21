import { it, expect, vi, beforeAll, afterEach } from 'vitest'
import { flushPromises } from '@vue/test-utils'

import { creerUnFauxServeur, fileDurable } from '../composables/seanceHorsLigne'
import { suivreLesPagesMontees } from './pagesMontees'
import {
    cliquer,
    fermerLaListeDesSeances,
    historiqueAuRepos,
    ouvrirLaListeDesSeances,
    present,
    rangees,
    saisir,
} from './seanceDansLHistorique'

const reseau = vi.hoisted(() => ({ serveur: null }))

vi.mock('@/Utils/http', () => ({ http: (config) => reseau.serveur.repondre(config) }))
vi.mock('@/composables/useHaptics', () => ({ triggerHaptic: vi.fn() }))
vi.mock('@formkit/drag-and-drop/vue', () => ({ dragAndDrop: () => {} }))

// La mise en page n'a rien à montrer : la page des modèles et la séance comptent seules.
vi.mock('@/Layouts/AuthenticatedLayout.vue', async () => {
    const { h: rendre } = await import('vue')

    return {
        default: {
            name: 'AuthenticatedLayout',
            setup:
                (_props, { slots }) =>
                () =>
                    rendre('div', [slots['header-actions']?.(), slots.default?.()]),
        },
    }
})
vi.mock('@/Components/Workout/WorkoutSettingsModal.vue', () => ({
    default: { name: 'WorkoutSettingsModal', render: () => null },
}))
vi.mock('@/Components/UI/ConfirmDialog.vue', () => ({ default: { name: 'ConfirmDialog', render: () => null } }))

/*
 * Lancer un modèle en ligne, quand la réponse se perd (#2049), avec le vrai
 * client d'Inertia, la vraie page des modèles, la vraie page de séance et la
 * vraie file hors ligne.
 *
 * En ligne, le lancement est une seule requête (`POST /api/v1/workouts` avec
 * `template_id`), que la file ne garde pas. Le serveur l'exécute, crée la
 * séance, ses lignes et ses séries sous les clés qu'il dérive, et la réponse
 * se perd en chemin. La page ne sait rien de ce qui a été créé : la séance du
 * modèle entre en file, notée tentée, sous les mêmes clés que le serveur
 * (`lancements`), et s'ouvre. La personne change un poids. Au retour du
 * réseau, chaque écriture rejouée retrouve, sous sa clé, ce que le serveur
 * avait déjà : rien n'est doublé, et le poids changé part en rattrapage.
 *
 * Un fichier, un test (le décor de `seanceDansLHistorique.js`).
 */

const pages = suivreLesPagesMontees()

/** Un exercice de deux séries, tel que le serveur le donne pour lancer le modèle. */
const LANCEMENT = {
    name: 'Jambes',
    lignes: [
        {
            exercise: { id: 7, name: 'Squat', type: 'strength' },
            order: 0,
            cle: 'modele-ligne:0:7',
            series: [
                { reps: 5, weight: 100, is_warmup: false, cle: 'modele-serie:0:0' },
                { reps: 5, weight: 120, is_warmup: false, cle: 'modele-serie:0:1' },
            ],
        },
    ],
}

/** La page des modèles, telle que le serveur la rend : le modèle 3, et de quoi le lancer. */
const pageDesModeles = () => ({
    component: 'Workouts/Templates/Index',
    url: '/templates',
    version: 'v1',
    props: {
        errors: {},
        flash: {},
        auth: { user: { id: 1, active_workout: null } },
        templates: [
            {
                id: 3,
                name: 'Jambes',
                description: null,
                workout_template_lines_count: 1,
                workout_template_lines: [{ id: 31, exercise: { name: 'Squat' }, workout_template_sets_count: 2 }],
            },
        ],
        lancements: { 3: LANCEMENT },
    },
    encryptHistory: true,
})

/**
 * Le serveur exécute le modèle comme `CreateWorkoutFromTemplateAction` : la
 * séance sous la clé de la requête, chaque ligne et chaque série sous la clé
 * que leur rang dérive. Puis la réponse se perd, et le réseau avec elle.
 *
 * @param {ReturnType<typeof creerUnFauxServeur>} serveur
 */
const executerLeModeleSansRendreLaReponse = (serveur) => (config) => {
    const cle = config.headers['Idempotency-Key']
    const seance = { id: serveur.prochaineSeance++, name: 'Jambes', started_at: config.data.started_at, ended_at: null }

    serveur.seances.set(seance.id, { ...seance, notes: null, idempotency_key: cle })
    serveur.creees.set(`/api/v1/workouts ${cle}`, seance.id)

    for (const [rang, ligneDuModele] of LANCEMENT.lignes.entries()) {
        const ligne = {
            id: serveur.prochaineLigne++,
            workout_id: seance.id,
            exercise_id: ligneDuModele.exercise.id,
            order: rang,
            sets: [],
            recommended_values: null,
        }

        serveur.lignes.set(ligne.id, ligne)
        serveur.creees.set(`/api/v1/workout-lines ${ligneDuModele.cle}`, ligne.id)

        for (const serieDuModele of ligneDuModele.series) {
            const serie = {
                id: serveur.prochaineSerie++,
                workout_line_id: ligne.id,
                reps: serieDuModele.reps,
                weight: serieDuModele.weight,
                is_warmup: serieDuModele.is_warmup,
                is_completed: false,
            }

            serveur.series.set(serie.id, serie)
            serveur.creees.set(`/api/v1/sets ${serieDuModele.cle}`, serie.id)
        }
    }

    serveur.enLigne = false

    throw { code: 'ERR_NETWORK', request: {} }
}

const composants = {}

let sync
let visites
let usePage

beforeAll(async () => {
    reseau.serveur = creerUnFauxServeur()
    reseau.serveur.pages = { '/templates': pageDesModeles }

    ;({ sync, visites } = await ouvrirLaListeDesSeances({
        serveur: reseau.serveur,
        chargerLaSeance: () => import('@/Pages/Workouts/Show.vue'),
        retenir: (application) => pages.retenir(application),
        avantInertia: async (inertia) => {
            usePage = inertia.usePage
            composants['Workouts/Templates/Index'] = (await import('@/Pages/Workouts/Templates/Index.vue')).default
        },
        ouverture: { adresse: '/templates', selecteur: '[dusk="execute-template-3"]', etatDeLHistorique: null },
        composants,
    }))
})

afterEach(async () => {
    await pages.demonterToutes()
    await fermerLaListeDesSeances()
})

it('lancé sans réponse, le modèle s’ouvre depuis la file, et le rejeu retrouve ce que le serveur avait créé : rien n’est doublé, le poids changé part en rattrapage', async () => {
    reseau.serveur.imposer.push(executerLeModeleSansRendreLaReponse(reseau.serveur))

    await cliquer('[dusk="execute-template-3"]')
    await vi.waitUntil(() => present('[dusk="exercise-card-0"]'))
    await flushPromises()

    // Une seule requête, celle du modèle ; la file n'en garde rien de tel.
    expect(reseau.serveur.resume()).toEqual([
        expect.stringMatching(/^post \/api\/v1\/workouts \{"started_at":"[^"]+","template_id":3\}$/),
    ])

    const file = fileDurable()
    const cle = file[0].headers['Idempotency-Key']

    // La séance du modèle en file, sous la clé de la requête partie et celles du serveur, tentée.
    expect(file.map((entree) => [entree.url, entree.headers['Idempotency-Key'], entree.tentee])).toEqual([
        ['/api/v1/workouts', cle, true],
        ['/api/v1/workout-lines', 'modele-ligne:0:7', true],
        ['/api/v1/sets', 'modele-serie:0:0', true],
        ['/api/v1/sets', 'modele-serie:0:1', true],
    ])
    expect(file[0].data).not.toHaveProperty('template_id')
    expect(reseau.serveur.creees.get(`/api/v1/workouts ${cle}`)).toBe(42)
    expect(window.location.pathname).toBe(`/workouts/en-attente/${cle}`)
    expect(rangees(0)).toBe(2)

    // Le poids de la seconde série change, fondu dans son entrée.
    await saisir('[dusk="weight-input-0-1"]', '125')
    await vi.waitUntil(() => fileDurable()[3]?.data.weight === 125)

    // Le réseau revient : chaque écriture retrouve, sous sa clé, ce que le serveur a déjà.
    reseau.serveur.requetes.length = 0
    visites.length = 0
    reseau.serveur.enLigne = true
    await sync.processQueue()

    expect(reseau.serveur.resume()).toEqual([
        expect.stringMatching(/^post \/api\/v1\/workouts \{"started_at":"[^"]+","name":"Jambes"\}$/),
        'post /api/v1/workout-lines {"workout_id":42,"exercise_id":7,"order":0}',
        'post /api/v1/sets {"workout_line_id":70,"reps":5,"weight":100,"is_warmup":false,"is_completed":false}',
        'post /api/v1/sets {"workout_line_id":70,"reps":5,"weight":125,"is_warmup":false,"is_completed":false}',
        'patch /api/v1/sets/101 {"weight":125}',
    ])
    expect(reseau.serveur.seances.size).toBe(1)
    expect(reseau.serveur.lignes.size).toBe(1)
    expect([...reseau.serveur.series.values()].map((serie) => [serie.id, serie.weight])).toEqual([
        [100, 100],
        [101, 125],
    ])
    expect(sync.failedRequests()).toEqual([])
    expect(fileDurable()).toEqual([])

    // La page passe à la séance réelle, une seule fois chaque série.
    await vi.waitUntil(() => window.location.pathname === '/workouts/42')
    await vi.waitUntil(() => usePage().props.workout.id === 42)
    await flushPromises()

    expect(visites).toEqual(['/workouts/42'])
    expect(rangees(0)).toBe(2)
    expect(document.querySelector('[dusk="weight-input-0-1"]').value).toBe('125')

    await historiqueAuRepos()
})
