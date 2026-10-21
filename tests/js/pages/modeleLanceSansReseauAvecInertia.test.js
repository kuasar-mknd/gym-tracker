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
 * Lancer un modèle sans réseau (#2049), depuis la vraie page des modèles
 * servie d'une copie, avec le vrai client d'Inertia, la vraie page de séance
 * et la vraie file hors ligne.
 *
 * « Lancer cette séance » faisait une visite d'Inertia (`templates.execute`),
 * qui ne disait sans réseau que « Il faut le réseau pour démarrer une
 * séance ». La copie de la page porte le contenu du modèle et les clés que le
 * serveur dérive (`lancements`) : la séance, ses exercices et ses séries
 * entrent en file d'un seul coup, la séance s'ouvre avec eux, une saisie se
 * fond dans l'entrée de sa série, et le retour du réseau crée le tout.
 *
 * Le décor (`seanceDansLHistorique.js`) monte le client une fois par fichier :
 * un fichier, un test. Le lancement parti sans réponse se joue dans
 * `modeleLanceSansReponseAvecInertia.test.js`, ce qui ne démarre pas dans
 * `modeleQuiNeDemarrePasAvecInertia.test.js`.
 */

const pages = suivreLesPagesMontees()

/** Deux exercices de deux séries, tels que le serveur les donne pour lancer le modèle. */
const LANCEMENT_JAMBES = {
    name: 'Jambes',
    lignes: [
        {
            exercise: { id: 7, name: 'Squat', type: 'strength' },
            order: 0,
            cle: 'modele-ligne:0:7',
            series: [
                { reps: 5, weight: 100, is_warmup: true, cle: 'modele-serie:0:0' },
                { reps: 5, weight: 120, is_warmup: false, cle: 'modele-serie:0:1' },
            ],
        },
        {
            exercise: { id: 8, name: 'Développé couché', type: 'strength' },
            order: 1,
            cle: 'modele-ligne:1:8',
            series: [
                { reps: 8, weight: 80, is_warmup: false, cle: 'modele-serie:1:0' },
                // Au plafond d'une série : le serveur ramène le modèle à ses bornes.
                { reps: 8, weight: 999.99, is_warmup: false, cle: 'modele-serie:1:1' },
            ],
        },
    ],
}

/** La page des modèles servie de sa copie : le modèle 3, et de quoi le lancer. */
const copieDeLaPageDesModeles = () => ({
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
                workout_template_lines_count: 2,
                workout_template_lines: [
                    { id: 31, exercise: { name: 'Squat' }, workout_template_sets_count: 2 },
                    { id: 32, exercise: { name: 'Développé couché' }, workout_template_sets_count: 2 },
                ],
            },
        ],
        lancements: { 3: LANCEMENT_JAMBES },
        horsLigne: { copieDu: Date.now() - 60_000, raison: 'reseau', datesDesProps: {} },
    },
    encryptHistory: true,
})

/** Les composants des autres pages, importés dans le graphe du test, juste avant le client. */
const composants = {}

let sync
let visites
let usePage

beforeAll(async () => {
    reseau.serveur = creerUnFauxServeur()
    reseau.serveur.pages = { '/templates': copieDeLaPageDesModeles }

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

/** Ce que montre la série `serie` de l'exercice `exercice` : son poids et ses répétitions. */
const valeurs = (exercice, serie) => [
    document.querySelector(`[dusk="weight-input-${exercice}-${serie}"]`)?.value,
    document.querySelector(`[dusk="reps-input-${exercice}-${serie}"]`)?.value,
]

it('lance le modèle depuis sa copie : la séance, ses exercices et ses séries en file sous les clés du serveur, une saisie fondue, puis tout créé au retour du réseau', async () => {
    reseau.serveur.enLigne = false

    // « Lancer » reste ouvert sur la copie ; supprimer, une visite, attend le réseau.
    expect(document.querySelector('[dusk="execute-template-3"]').disabled).toBe(false)
    expect(document.querySelector('[dusk="delete-template-3"]').disabled).toBe(true)

    await cliquer('[dusk="execute-template-3"]')
    await vi.waitUntil(() => present('[dusk="exercise-card-1"]'))
    await flushPromises()

    const file = fileDurable()
    const [creation] = file
    const cle = creation.headers['Idempotency-Key']

    // La séance en attente, à l'adresse de sa clé, ni tentée ni envoyée : la page est une copie.
    expect(window.location.pathname).toBe(`/workouts/en-attente/${cle}`)
    expect(usePage().props).not.toHaveProperty('horsLigne')
    expect(reseau.serveur.requetes).toEqual([])
    expect(file.map((entree) => [entree.url, entree.headers['Idempotency-Key']])).toEqual([
        ['/api/v1/workouts', cle],
        ['/api/v1/workout-lines', 'modele-ligne:0:7'],
        ['/api/v1/sets', 'modele-serie:0:0'],
        ['/api/v1/sets', 'modele-serie:0:1'],
        ['/api/v1/workout-lines', 'modele-ligne:1:8'],
        ['/api/v1/sets', 'modele-serie:1:0'],
        ['/api/v1/sets', 'modele-serie:1:1'],
    ])
    expect(creation.data).toEqual({ started_at: expect.any(String), name: 'Jambes' })
    expect(file.some((entree) => entree.tentee)).toBe(false)

    // Les exercices nommés d'après le modèle, et ses séries telles que le serveur les a bornées.
    expect(document.querySelector('[dusk="exercise-card-0"]').textContent).toContain('Squat')
    expect(document.querySelector('[dusk="exercise-card-1"]').textContent).toContain('Développé couché')
    expect([rangees(0), rangees(1)]).toEqual([2, 2])
    expect([valeurs(0, 0), valeurs(0, 1), valeurs(1, 0), valeurs(1, 1)]).toEqual([
        ['100', '5'],
        ['120', '5'],
        ['80', '8'],
        ['999.99', '8'],
    ])
    expect(usePage().props.exercises.map((exercice) => exercice.name)).toEqual(['Squat', 'Développé couché'])

    // Un poids modifié se fond dans l'entrée de sa série, sans brouillon ni requête.
    await saisir('[dusk="weight-input-0-1"]', '125')
    await vi.waitUntil(
        () =>
            fileDurable().find((entree) => entree.headers['Idempotency-Key'] === 'modele-serie:0:1')?.data.weight ===
            125,
    )

    expect(fileDurable()).toHaveLength(7)
    expect(Object.keys(localStorage).filter((cleDuStockage) => cleDuStockage.startsWith('draft_set_'))).toEqual([])
    expect(JSON.stringify(fileDurable())).not.toContain('temp-')
    expect(reseau.serveur.requetes).toEqual([])

    // Le réseau revient : la séance, puis chaque exercice et ses séries, sous les identifiants réels.
    visites.length = 0
    reseau.serveur.enLigne = true
    await sync.processQueue()

    expect(reseau.serveur.resume()).toEqual([
        expect.stringMatching(/^post \/api\/v1\/workouts \{"started_at":"[^"]+","name":"Jambes"\}$/),
        'post /api/v1/workout-lines {"workout_id":42,"exercise_id":7,"order":0}',
        'post /api/v1/sets {"workout_line_id":70,"reps":5,"weight":100,"is_warmup":true,"is_completed":false}',
        'post /api/v1/sets {"workout_line_id":70,"reps":5,"weight":125,"is_warmup":false,"is_completed":false}',
        'post /api/v1/workout-lines {"workout_id":42,"exercise_id":8,"order":1}',
        'post /api/v1/sets {"workout_line_id":71,"reps":8,"weight":80,"is_warmup":false,"is_completed":false}',
        'post /api/v1/sets {"workout_line_id":71,"reps":8,"weight":999.99,"is_warmup":false,"is_completed":false}',
    ])
    expect(reseau.serveur.seances.size).toBe(1)
    expect([...reseau.serveur.seances.values()][0].idempotency_key).toBe(cle)
    expect(sync.failedRequests()).toEqual([])
    expect(fileDurable()).toEqual([])

    // La page passe à la séance réelle, ses deux exercices et leurs quatre séries.
    await vi.waitUntil(() => window.location.pathname === '/workouts/42')
    await vi.waitUntil(() => usePage().props.workout.id === 42 && present('[dusk="exercise-card-1"]'))
    await flushPromises()

    expect(visites).toEqual(['/workouts/42'])
    expect([rangees(0), rangees(1)]).toEqual([2, 2])
    expect(valeurs(0, 1)).toEqual(['125', '5'])

    await historiqueAuRepos()
})
