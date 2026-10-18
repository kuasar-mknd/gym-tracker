import { afterEach, beforeAll, expect, it, vi } from 'vitest'
import { flushPromises } from '@vue/test-utils'

import { creerUneFabriqueEnMemoire } from '../utils/baseIndexeeEnMemoire'
import { creerUnFauxServeur } from '../composables/seanceHorsLigne'
import { suivreLesPagesMontees } from './pagesMontees'
import {
    fermerLaListeDesSeances,
    historiqueAuRepos,
    ouvrirLaListeDesSeances,
    present,
    saisir,
    SQUAT,
} from './seanceDansLHistorique'

const reseau = vi.hoisted(() => ({ serveur: null }))

/** Le formulaire des réglages que la page passe à sa modale, lu tel qu'elle le tient. */
const reglages = vi.hoisted(() => ({ formulaire: () => null }))

/** La bibliothèque que la page passe à la modale d'ajout d'un exercice, lue telle qu'elle la tient. */
const bibliotheque = vi.hoisted(() => ({ exercices: () => null }))

vi.mock('@/Utils/http', () => ({ http: (config) => reseau.serveur.repondre(config) }))
vi.mock('@/composables/useHaptics', () => ({ triggerHaptic: vi.fn() }))
vi.mock('@formkit/drag-and-drop/vue', () => ({ dragAndDrop: () => {} }))

// La mise en page et les modales, fermées ici, n'ont rien à montrer : la séance seule compte.
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
vi.mock('@/Components/Workout/AjoutDExerciceModal.vue', () => ({
    default: {
        name: 'AjoutDExerciceModal',
        props: ['show', 'exercises'],
        setup(props) {
            bibliotheque.exercices = () => props.exercises

            return () => null
        },
    },
}))
vi.mock('@/Components/Workout/WorkoutSettingsModal.vue', () => ({
    default: {
        name: 'WorkoutSettingsModal',
        props: ['show', 'form'],
        setup(props) {
            reglages.formulaire = () => props.form

            return () => null
        },
    },
}))
vi.mock('@/Components/Workout/WorkoutFinishModal.vue', () => ({
    default: { name: 'WorkoutFinishModal', render: () => null },
}))
vi.mock('@/Components/UI/ConfirmDialog.vue', () => ({ default: { name: 'ConfirmDialog', render: () => null } }))
vi.mock('@/Components/Workout/RestTimer.vue', () => ({ default: { name: 'RestTimer', render: () => null } }))

/*
 * La séance servie depuis sa copie hors ligne, puis rechargée en entier au
 * retour du réseau (#2048, partie E), avec le vrai client d'Inertia, la vraie
 * page de séance, le vrai service des copies, la vraie écriture des copies et
 * le magasin chiffré sur une base en mémoire.
 *
 * `router.reload` garde l'état de la page : la séance n'est pas remontée, et
 * ce qu'elle avait tiré de la copie au montage y restait. Ses réglages
 * repartaient au serveur avec le nom et les notes de la copie, et sa copie ne
 * recevait plus aucun instantané tant qu'elle restait ouverte : une série
 * acceptée ensuite manquait à la copie, servie de nouveau sans réseau. Sa
 * bibliothèque d'exercices restait aussi celle de la copie : un exercice créé
 * ailleurs n'y était pas, un exercice supprimé ailleurs s'y proposait encore
 * (seconde relecture du 2026-10-18).
 *
 * Le décor vient de `seanceDansLHistorique.js` : un fichier, un test.
 */

const pages = suivreLesPagesMontees()

/** Un exercice que le serveur ne rend qu'après la copie, créé ailleurs. */
const TIRAGE = { id: 99, name: 'Tirage horizontal', type: 'strength', category: 'Dos', default_rest_time: 90 }

/** La clé du témoin, posée comme le serveur la pose : 32 octets en base64url. */
const CLE = 'AQIDBAUGBwgJCgsMDQ4PEBESExQVFhcYGRobHB0eHyA'

let visiter
let sync
let magasin
let ecriture
let fabriqueDeLaBase
let routeur

/** Une condition, le temps que la saisie parte après son propre délai. */
const attendre = (condition) => vi.waitUntil(condition, { timeout: 4000 })

/** Le poids de la première série dans la copie de la séance. */
const poidsDansLaCopie = async () =>
    (await magasin.lireUneCopie('/workouts/5')).page.props.workout.workout_lines[0].sets[0].weight

/** Une visite d'Inertia sans réseau : la requête échoue aussitôt, comme hors ligne. */
class RequeteSansReseau {
    upload = {}
    status = 0

    open() {}

    setRequestHeader() {}

    getAllResponseHeaders() {
        return ''
    }

    abort() {
        this.onabort?.()
    }

    send() {
        queueMicrotask(() => this.onerror?.())
    }
}

beforeAll(async () => {
    reseau.serveur = creerUnFauxServeur({
        series: [{ id: 3, workout_line_id: 1, weight: 80, reps: 5, is_completed: true }],
    })
    reseau.serveur.lignes.set(1, { id: 1, workout_id: 5, exercise_id: 7 })
    document.cookie = `gt_copies=${CLE}; path=/`
    fabriqueDeLaBase = creerUneFabriqueEnMemoire()

    ;({ visiter, sync } = await ouvrirLaListeDesSeances({
        serveur: reseau.serveur,
        chargerLaSeance: () => import('@/Pages/Workouts/Show.vue'),
        retenir: (application) => pages.retenir(application),
        // Comme `main.js` : les visites hors ligne, puis l'écriture des copies, avant Inertia.
        avantInertia: async ({ router }) => {
            const { creerLeMagasinDesCopies, ouvrirLaBaseDuNavigateur } = await import('@/Utils/copiesHorsLigne')
            const { installerLEcritureDesCopies } = await import('@/Utils/ecritureDesCopies')
            const { installerLesVisitesHorsLigne } = await import('@/Utils/visitesHorsLigne')

            routeur = router
            magasin = creerLeMagasinDesCopies({ ouvrirLaBase: () => ouvrirLaBaseDuNavigateur(fabriqueDeLaBase) })
            installerLesVisitesHorsLigne({ routeur: router, lireUneCopie: (adresse) => magasin.lireUneCopie(adresse) })
            ecriture = installerLEcritureDesCopies({ routeur: router, magasin })
        },
    }))
})

afterEach(async () => {
    await pages.demonterToutes()
    ecriture.desinstaller()
    await magasin.fermer()
    document.cookie = 'gt_copies=; path=/; max-age=0'
    await fermerLaListeDesSeances()
})

it('servie de sa copie puis rechargée au retour du réseau, la séance reprend les réglages et la bibliothèque du serveur, et nourrit de nouveau sa copie', async () => {
    const RequeteDuBanc = globalThis.XMLHttpRequest
    const { noterQueLeServeurRepond } = await import('@/composables/useEtatDuReseau')
    let pageCourante = null

    for (const evenement of ['navigate', 'clientVisit', 'success']) {
        routeur.on(evenement, (detail) => (pageCourante = detail.detail.page))
    }

    // La séance ouverte en ligne laisse sa copie, puis la personne passe à la liste.
    await visiter('/workouts/5', '[dusk="exercise-card-0"]')
    await ecriture.ecrituresFinies()
    await visiter('/workouts', '#liste-des-seances')

    // Ailleurs, la séance est renommée et annotée ; un exercice est créé, un autre supprimé.
    reseau.serveur.reglagesDeLaSeance = { name: 'Séance du matin', notes: 'Genou gauche à surveiller' }
    reseau.serveur.bibliotheque = [SQUAT, TIRAGE]

    // Sans réseau, le lien vers la séance ouvre sa copie, avec les réglages d'avant.
    vi.stubGlobal('XMLHttpRequest', RequeteSansReseau)
    routeur.visit('/workouts/5')
    await attendre(() => pageCourante?.props?.horsLigne !== undefined && present('[dusk="weight-input-0-0"]'))
    const champMonteSurLaCopie = document.querySelector('[dusk="weight-input-0-0"]')

    expect(reglages.formulaire()).toMatchObject({ name: 'Séance du soir', notes: '' })
    expect(bibliotheque.exercices().map((exercice) => exercice.name)).toEqual(['Squat', 'Développé couché'])

    // Le réseau revient : la copie se recharge en entier, sans que la page soit remontée.
    vi.stubGlobal('XMLHttpRequest', RequeteDuBanc)
    noterQueLeServeurRepond()
    await attendre(() => pageCourante?.props?.horsLigne === undefined)
    await flushPromises()

    expect(document.querySelector('[dusk="weight-input-0-0"]')).toBe(champMonteSurLaCopie)
    expect(reglages.formulaire()).toMatchObject({ name: 'Séance du matin', notes: 'Genou gauche à surveiller' })
    expect(bibliotheque.exercices().map((exercice) => exercice.name)).toEqual(['Squat', 'Tirage horizontal'])

    // Une série modifiée en ligne et acceptée passe dans la copie, une seconde plus tard.
    await ecriture.ecrituresFinies()
    await saisir('[dusk="weight-input-0-0"]', '90')
    await attendre(() => reseau.serveur.series.get(3).weight === 90 && sync.ecrituresEnCours() === 0)
    await attendre(async () => {
        await ecriture.ecrituresFinies()

        return (await poidsDansLaCopie()) === 90
    })

    await historiqueAuRepos()
}, 30_000)
