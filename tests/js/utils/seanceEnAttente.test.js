import { describe, it, expect, afterEach } from 'vitest'
import { CHAMPS_DE_LA_PAGE_SERVIE, MARQUE_D_UNE_COPIE } from '@/Utils/copiesServies'
import {
    CHEMIN_D_UNE_SEANCE_EN_ATTENTE,
    CLE_DE_LA_FILE,
    adresseDUneSeanceEnAttente,
    bibliothequeHorsLigne,
    cleDeLAdresseEnAttente,
    FIN_NON_ENREGISTREE,
    creationDeLaCle,
    emporteesParLeRefus,
    estCloseParLaFile,
    estUneCreationDeSeance,
    lireLaFileDuStockage,
    messageDeLaFinEnAttente,
    messageDuRefusDeLaSeance,
    pageDeLaSeanceEnAttente,
    seanceDeLEntree,
    seanceEnCoursAvecLaFile,
} from '@/Utils/seanceEnAttente'

/*
 * Une séance démarrée sans réseau (#2049) : la page que l'appareil construit
 * depuis l'entrée de sa création, son adresse, et la séance en cours de
 * l'appareil, la file comprise. Tout ici est pur.
 */

const CLE = '7d3f8c1e-2b4a-4f6e-9a1d-0c5b8e7f6a21'

/** La création d'une séance telle que `SyncService.mettreEnFile` la range. */
const creation = (extra = {}) => ({
    method: 'post',
    url: '/api/v1/workouts',
    data: { started_at: '2026-10-19T08:15:00.000Z' },
    headers: { 'Idempotency-Key': CLE },
    id: '1791833275396f3qnek8gw',
    timestamp: '2026-10-19T08:15:00.100Z',
    compte: '1',
    ...extra,
})

const ligneDe = (queueId, extra = {}) => ({
    method: 'post',
    url: '/api/v1/workout-lines',
    data: { workout_id: { enAttenteDe: queueId }, exercise_id: 7 },
    headers: { 'Idempotency-Key': 'cle-de-la-ligne' },
    id: `ligne-${queueId}`,
    timestamp: '2026-10-19T08:16:00.000Z',
    compte: '1',
    ...extra,
})

/** La page affichée quand la personne démarre : l'accueil, avec ce qu'une page d'Inertia porte. */
const accueil = () => ({
    component: 'Dashboard',
    url: '/dashboard',
    version: 'version-qui-tourne',
    props: { auth: { user: { id: 1 } }, analyticalStats: { a: 1 } },
    flash: { proposerLesNotifications: true },
    deferredProps: { default: ['analyticalStats'] },
    initialDeferredProps: { default: ['analyticalStats'] },
    rescuedProps: ['analyticalStats'],
    mergeProps: ['recentWorkouts'],
    prependProps: ['a'],
    deepMergeProps: ['b'],
    matchPropsOn: ['c'],
    onceProps: { d: { prop: 'd' } },
    scrollProps: { e: {} },
    sharedProps: ['auth', 'flash'],
    rememberedState: { formulaire: { poids: 82 } },
    clearHistory: true,
    encryptHistory: false,
    preserveFragment: true,
    optimisticUpdatedAt: { a: 1 },
})

afterEach(() => {
    localStorage.clear()
})

describe('l’adresse d’une séance en attente', () => {
    it('se forme de la clé, et rend la clé, à l’octet près', () => {
        const adresse = adresseDUneSeanceEnAttente(CLE)

        expect(adresse).toBe(`/workouts/en-attente/${CLE}`)
        expect(CHEMIN_D_UNE_SEANCE_EN_ATTENTE).toBe('/workouts/en-attente/')
        expect(cleDeLAdresseEnAttente(adresse)).toBe(CLE)
        expect(cleDeLAdresseEnAttente(`${adresse}?onglet=1#haut`)).toBe(CLE)
    })

    it('refuse un chemin voisin, une clé de trop ou de travers', () => {
        expect(cleDeLAdresseEnAttente('/workouts/42')).toBeNull()
        expect(cleDeLAdresseEnAttente('/workouts/en-attente')).toBeNull()
        expect(cleDeLAdresseEnAttente('/workouts/en-attente/')).toBeNull()
        expect(cleDeLAdresseEnAttente(`/workouts/en-attente/${CLE}/autre`)).toBeNull()
        expect(cleDeLAdresseEnAttente(`/workouts/en-attentes/${CLE}`)).toBeNull()
        expect(cleDeLAdresseEnAttente(`/x/workouts/en-attente/${CLE}`)).toBeNull()
        expect(cleDeLAdresseEnAttente(`/workouts/en-attente/${'a'.repeat(65)}`)).toBeNull()
        expect(cleDeLAdresseEnAttente('/workouts/en-attente/cl%C3%A9')).toBeNull()
        expect(cleDeLAdresseEnAttente(undefined)).toBeNull()
        expect(adresseDUneSeanceEnAttente('une clé')).toBeNull()
        expect(adresseDUneSeanceEnAttente(null)).toBeNull()
    })
})

describe('la séance d’une création en file', () => {
    it('porte chaque champ de la séance que le serveur rend, sous l’identifiant de son entrée', () => {
        const seance = seanceDeLEntree(creation())

        expect(estUneCreationDeSeance(creation())).toBe(true)
        expect(seance).toEqual({
            id: 'temp-q1791833275396f3qnek8gw',
            user_id: 1,
            name: new Date('2026-10-19T08:15:00.000Z').toLocaleDateString('fr-FR'),
            started_at: '2026-10-19T08:15:00.000Z',
            ended_at: null,
            notes: null,
            idempotency_key: CLE,
            created_at: '2026-10-19T08:15:00.000Z',
            updated_at: '2026-10-19T08:15:00.000Z',
            workout_volume: 0,
            workout_lines: [],
        })
        expect(seanceDeLEntree(creation({ data: { started_at: 'x', name: 'Jambes' } })).name).toBe('Jambes')
    })

    it('se retrouve par sa clé, jamais une création retirée', () => {
        const entrees = [ligneDe('autre'), creation()]

        expect(creationDeLaCle(entrees, CLE)).toBe(entrees[1])
        expect(creationDeLaCle(entrees, 'autre-cle')).toBeUndefined()
        expect(creationDeLaCle([creation({ aAnnuler: '/api/v1/workouts/__produit__' })], CLE)).toBeUndefined()
        expect(creationDeLaCle(entrees, null)).toBeUndefined()
    })
})

describe('la séance en cours de l’appareil', () => {
    it('est la création en file, avec les exercices qui l’attendent', () => {
        const enFile = creation()

        expect(seanceEnCoursAvecLaFile(null, [enFile, ligneDe(enFile.id), ligneDe('autre')])).toEqual({
            id: `temp-q${enFile.id}`,
            name: seanceDeLEntree(enFile).name,
            started_at: '2026-10-19T08:15:00.000Z',
            idempotency_key: CLE,
            workout_lines_count: 1,
            enAttenteDe: enFile.id,
        })
    })

    it('est celle du serveur quand elle porte la clé de la création : une seule séance', () => {
        const duServeur = { id: 42, name: '19/10/2026', idempotency_key: CLE, workout_lines_count: 2 }

        expect(seanceEnCoursAvecLaFile(duServeur, [creation()])).toBe(duServeur)
    })

    it('est la création en file plutôt qu’une autre séance du serveur, et celle du serveur sans création', () => {
        const autre = { id: 9, idempotency_key: 'autre-cle' }

        expect(seanceEnCoursAvecLaFile(autre, [creation()])).toMatchObject({ enAttenteDe: creation().id })
        expect(seanceEnCoursAvecLaFile(autre, [ligneDe('x')])).toBe(autre)
        expect(seanceEnCoursAvecLaFile(undefined, [])).toBeNull()
        expect(seanceEnCoursAvecLaFile(autre, [creation({ aAnnuler: '/x/__produit__' })])).toBe(autre)
    })
})

describe('la page de la séance en attente', () => {
    const page = () =>
        pageDeLaSeanceEnAttente({
            entree: creation(),
            auth: { user: { id: 1, latest_achievement: { id: 'badge' } } },
            exercices: [{ id: 7, name: 'Squat' }],
            bornes: { weight: 500 },
            pageCourante: accueil(),
        })

    it('écrit chaque champ d’une page servie, et rien de la page quittée ne survit', () => {
        const poussee = page()

        CHAMPS_DE_LA_PAGE_SERVIE.forEach((champ) => expect(Object.hasOwn(poussee, champ), champ).toBe(true))

        // Ce que la page quittée portait hors des champs écrits : remis à undefined, pour `router.push`.
        expect(Object.hasOwn(poussee, 'preserveFragment')).toBe(true)
        expect(poussee.preserveFragment).toBeUndefined()
        expect(poussee.optimisticUpdatedAt).toBeUndefined()
        expect(poussee).toMatchObject({
            component: 'Workouts/Show',
            url: `/workouts/en-attente/${CLE}`,
            version: 'version-qui-tourne',
            flash: {},
            rescuedProps: [],
            mergeProps: [],
            prependProps: [],
            deepMergeProps: [],
            matchPropsOn: [],
            onceProps: {},
            rememberedState: {},
            clearHistory: false,
        })
        expect(poussee.deferredProps).toBeUndefined()
        expect(poussee.initialDeferredProps).toBeUndefined()
        expect(poussee.scrollProps).toBeUndefined()
        expect(Object.keys(poussee.props).sort()).toEqual(['auth', 'bornesDUneSerie', 'errors', 'exercises', 'workout'])
    })

    it('chiffre son entrée d’historique, quoi que dise la page quittée', () => {
        expect(page().encryptHistory).toBe(true)
    })

    it('montre auth sans badge à célébrer, la séance de l’entrée, la bibliothèque et les plafonds', () => {
        const { props } = page()

        expect(props.auth.user).toEqual({ id: 1, latest_achievement: null })
        expect(props.workout).toEqual(seanceDeLEntree(creation()))
        expect(props.exercises).toEqual([{ id: 7, name: 'Squat' }])
        expect(props.bornesDUneSerie).toEqual({ weight: 500 })
        expect(props.errors).toEqual({})
    })

    it('ne porte pas la marque d’une copie : le retour du réseau ne la rechargerait pas', () => {
        expect(page().props).not.toHaveProperty(MARQUE_D_UNE_COPIE)
    })

    it('se construit aussi d’une séance déjà connue, sous l’adresse de sa clé', () => {
        const recue = { id: 42, name: 'Séance', idempotency_key: CLE, workout_lines: [] }
        const poussee = pageDeLaSeanceEnAttente({ seance: recue, auth: null })

        expect(poussee.props.workout).toBe(recue)
        expect(poussee.url).toBe(`/workouts/en-attente/${CLE}`)
        expect(poussee.props.exercises).toEqual([])
        expect(poussee.props.bornesDUneSerie).toBeNull()
        expect(poussee.version).toBeNull()
    })
})

describe('ce que la coquille lit, sans la file', () => {
    it('lit la file du stockage sous les identifiants que la file leur donne', () => {
        localStorage.setItem(CLE_DE_LA_FILE, JSON.stringify([creation(), creation(), 'abîmée', null]))

        expect(lireLaFileDuStockage().map((entree) => entree.id)).toEqual([
            '1791833275396f3qnek8gw',
            '1791833275396f3qnek8gw~2',
            // Un texte dans la liste n'est pas une entrée.
        ])
        expect(CLE_DE_LA_FILE).toBe('offline_sync_queue')

        localStorage.setItem(CLE_DE_LA_FILE, '{illisible')

        expect(lireLaFileDuStockage()).toEqual([])
    })

    it('prend la bibliothèque de la page, sinon de sa copie, sinon de la copie de la séance en cours', async () => {
        const copies = {
            '/exercises': { page: { props: { exercises: [{ id: 7, name: 'Squat' }] } } },
            '/workouts/42': {
                page: { props: { exercises: [{ id: 8, name: 'Course' }], bornesDUneSerie: { weight: 500 } } },
            },
        }
        const lireUneCopie = async (adresse) => copies[adresse] ?? null
        const auth = { user: { id: 1, active_workout: { id: 42 } } }

        expect(
            await bibliothequeHorsLigne({
                props: { exercises: [{ id: 9 }], bornesDUneSerie: { reps: 100 } },
                lireUneCopie,
            }),
        ).toEqual({ exercices: [{ id: 9 }], bornes: { reps: 100 } })
        expect(await bibliothequeHorsLigne({ props: { auth }, lireUneCopie })).toEqual({
            exercices: [{ id: 7, name: 'Squat' }],
            bornes: { weight: 500 },
        })

        delete copies['/exercises']

        expect(await bibliothequeHorsLigne({ props: { auth }, lireUneCopie })).toEqual({
            exercices: [{ id: 8, name: 'Course' }],
            bornes: { weight: 500 },
        })
        expect(
            await bibliothequeHorsLigne({
                props: { auth },
                lireUneCopie: async () => {
                    throw new Error('magasin fermé')
                },
            }),
        ).toEqual({ exercices: [], bornes: null })
    })
})

describe('le refus d’une séance démarrée sans réseau', () => {
    it('compte ce qu’il emporte : ce qui la nomme, et ce qui nomme ce qu’elle emporte', () => {
        const refus = [
            { id: 'qS' },
            { id: 'qL', parentRefuse: true, parent: 'qS' },
            { id: 'qSerie', parentRefuse: true, parent: 'qL' },
            // Un refus d'une autre séance, et un refus qui n'emporte rien.
            { id: 'qAutre', parentRefuse: true, parent: 'qX' },
            { id: 'qSeul' },
        ]

        expect(emporteesParLeRefus(refus, 'qS')).toBe(2)
        expect(emporteesParLeRefus(refus, 'qSeul')).toBe(0)
        expect(emporteesParLeRefus(null, 'qS')).toBe(0)
    })

    it('dit pourquoi, et accorde ce qu’il emporte', () => {
        expect(messageDuRefusDeLaSeance('seance-deja-en-cours')).toBe(
            "La séance démarrée sans réseau n'a pas été enregistrée : une autre séance était déjà en cours.",
        )
        expect(messageDuRefusDeLaSeance(null, 1)).toBe(
            "La séance démarrée sans réseau n'a pas été enregistrée. 1 modification de cette séance ne partira pas.",
        )
        expect(messageDuRefusDeLaSeance('seance-deja-en-cours', 3)).toBe(
            "La séance démarrée sans réseau n'a pas été enregistrée : une autre séance était déjà en cours. 3 modifications de cette séance ne partiront pas.",
        )
    })
})

/*
 * « Terminer » sans réseau (#2049) : la fin attend la file, et la séance
 * qu'elle nomme n'est plus en cours, ni pour le bandeau, ni pour « Démarrer ».
 */
describe('une séance terminée sur l’appareil', () => {
    const finDe = (workoutId, extra = {}) => ({
        method: 'patch',
        url: '/api/v1/workouts/finish',
        data: { workout_id: workoutId, ended_at: '2026-10-19T18:42:00.000Z' },
        id: `fin-${JSON.stringify(workoutId)}`,
        timestamp: '2026-10-19T18:42:00.100Z',
        compte: '1',
        ...extra,
    })

    it('n’est plus la séance en cours, celle du serveur comme celle d’une création', () => {
        const duServeur = { id: 42, idempotency_key: 'autre-cle' }
        const enFile = creation()

        expect(seanceEnCoursAvecLaFile(duServeur, [finDe(42)])).toBeNull()
        expect(seanceEnCoursAvecLaFile(null, [enFile, finDe({ enAttenteDe: enFile.id })])).toBeNull()
        // Arrivée au serveur sans réponse, la séance de la clé est la même : close aussi.
        expect(
            seanceEnCoursAvecLaFile({ id: 43, idempotency_key: CLE }, [enFile, finDe({ enAttenteDe: enFile.id })]),
        ).toBeNull()
        expect(estCloseParLaFile(duServeur, [finDe(9)])).toBe(false)
    })

    it('laisse en cours la séance démarrée après elle, derrière sa fin', () => {
        const premiere = creation()
        const seconde = creation({ id: 'seconde', headers: { 'Idempotency-Key': 'cle-2' } })

        expect(
            seanceEnCoursAvecLaFile({ id: 42, idempotency_key: 'autre' }, [
                finDe(42),
                premiere,
                finDe({ enAttenteDe: premiere.id }),
                seconde,
            ]),
        ).toMatchObject({ enAttenteDe: 'seconde', idempotency_key: 'cle-2' })
    })

    it('dit l’heure du geste, et que le serveur l’enregistrera au retour du réseau', () => {
        const heure = new Date('2026-10-19T18:42:00.000Z').toLocaleTimeString('fr-FR', {
            hour: '2-digit',
            minute: '2-digit',
        })

        expect(messageDeLaFinEnAttente('2026-10-19T18:42:00.000Z')).toBe(
            `Séance terminée sur cet appareil à ${heure} : elle sera enregistrée au retour du réseau.`,
        )
        expect(messageDeLaFinEnAttente(null)).toBe(
            'Séance terminée sur cet appareil : elle sera enregistrée au retour du réseau.',
        )
        expect(FIN_NON_ENREGISTREE).toBe("La fin de la séance n'a pas été enregistrée : elle reste ouverte.")
    })
})
