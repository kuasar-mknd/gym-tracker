import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { defineComponent, h, nextTick, reactive, ref } from 'vue'

import { DELAI_DE_L_INSTANTANE_MS, useCopieDeLaSeance } from '@/composables/useCopieDeLaSeance'

/*
 * La copie hors ligne de la séance (#2048, partie D), seule : une écriture
 * des copies simulée, pour jouer sous des minuteries simulées ce qui ne
 * verse jamais rien. Le reste se joue sur la vraie page de séance, avec le
 * vrai client d'Inertia (`tests/js/pages/copieDeLaSeance*.test.js`).
 */

const sync = vi.hoisted(() => ({ enAttente: 0, enCours: 0, suivie: [] }))

vi.mock('@/Utils/SyncService', () => ({
    default: {
        enAttente: () => sync.enAttente,
        ecrituresEnCours: () => sync.enCours,
        suivreLesEcrituresDeLaSeance: (seance) => sync.suivie.push(seance),
    },
}))

/** La séance 5, enregistrée. */
const seance = () => ({
    id: 5,
    ended_at: null,
    workout_lines: [{ id: 1, sets: [{ id: 3, weight: 80, is_completed: true }] }],
})

/** La page de séance d'Inertia. */
const pageDeLaSeance = (props = {}) => ({
    component: 'Workouts/Show',
    url: '/workouts/5',
    version: 'v1',
    encryptHistory: true,
    props: { auth: { user: { id: 7 } }, ...props },
})

let montees
let ecriture

/**
 * Monte la séance avec le composable, et rend sa copie locale.
 *
 * @param {{
 *   page?: object,
 *   depart?: object,
 *   origine?: string,
 *   ordreEnVol?: import('vue').Ref<number>,
 *   seanceDesProps?: import('vue').Ref<object>,
 * }} [options] la séance des props, que le test remplace comme Inertia, est
 *   celle du départ quand il n'en donne pas
 */
const monter = ({
    page = pageDeLaSeance(),
    depart = seance(),
    origine = 'reseau',
    ordreEnVol = ref(0),
    seanceDesProps = ref(depart),
} = {}) => {
    const localWorkout = ref(JSON.parse(JSON.stringify(depart)))

    ecriture.origineDeLaPage.mockReturnValue(origine)
    montees.push(
        mount(
            defineComponent({
                setup() {
                    useCopieDeLaSeance({
                        localWorkout,
                        seanceDeDepart: depart,
                        seance: () => seanceDesProps.value,
                        page,
                        valeursDeRepli: () => [],
                        unsyncedSetIds: ref(new Set()),
                        ordreEnVol,
                        ecriture,
                    })

                    return () => h('div')
                },
            }),
        ),
    )

    return localWorkout
}

beforeEach(() => {
    vi.useFakeTimers()
    montees = []
    sync.enAttente = 0
    sync.enCours = 0
    sync.suivie = []
    ecriture = {
        completer: vi.fn(),
        presser: vi.fn(),
        origineDeLaPage: vi.fn(() => 'reseau'),
        lireLaCopie: vi.fn(async () => null),
    }
})

afterEach(() => {
    montees.forEach((montee) => montee.unmount())
    vi.useRealTimers()
})

describe('la copie de la séance, seule', () => {
    it('dit au service de synchronisation quelle séance la page montre, même sans écriture des copies', async () => {
        monter({ page: { component: 'Workouts/Show', props: {} } })

        expect(sync.suivie).toEqual([5])
    })

    it('verse la séance une seconde après le dernier changement, la prop workout seule', async () => {
        const locale = monter()

        locale.value.workout_lines[0].sets[0].weight = 85
        await vi.advanceTimersByTimeAsync(DELAI_DE_L_INSTANTANE_MS - 1)
        expect(ecriture.completer).not.toHaveBeenCalled()

        await vi.advanceTimersByTimeAsync(1)
        expect(ecriture.completer).toHaveBeenCalledTimes(1)

        const [pageVersee, noms, options] = ecriture.completer.mock.calls[0]
        expect(noms).toEqual(['workout'])
        // Datée de la décision, où tout est enregistré, et non de son écriture au repos (#2048, partie E).
        expect(options).toEqual({ valableAu: Date.now() })
        expect(pageVersee).toMatchObject({ component: 'Workouts/Show', url: '/workouts/5', encryptHistory: true })
        expect(pageVersee.props.auth).toEqual({ user: { id: 7 } })
        expect(pageVersee.props.workout.workout_lines[0].sets[0].weight).toBe(85)
    })

    it.each([
        ['une page qui porte la marque d’une copie', { page: pageDeLaSeance({ horsLigne: { copieDu: 1 } }) }],
        ['une visite côté client', { origine: 'client' }],
        ['une page qui n’est pas la séance', { page: { ...pageDeLaSeance(), component: 'Dashboard' } }],
    ])('ne verse rien depuis %s', async (_cas, options) => {
        const locale = monter(options)

        locale.value.workout_lines[0].sets[0].weight = 85
        await vi.advanceTimersByTimeAsync(DELAI_DE_L_INSTANTANE_MS * 3)
        window.dispatchEvent(new Event('pagehide'))

        expect(ecriture.completer).not.toHaveBeenCalled()
        expect(ecriture.lireLaCopie).not.toHaveBeenCalled()
    })

    it('rendue par l’historique, ne verse que si elle est exactement celle de la copie, quel que soit l’ordre des clés', async () => {
        const differente = { ...seance(), workout_lines: [] }
        ecriture.lireLaCopie.mockResolvedValueOnce({ page: { props: { workout: differente } } })
        const ancienne = monter({ origine: 'historique' })

        ancienne.value.workout_lines[0].sets[0].weight = 85
        await vi.advanceTimersByTimeAsync(DELAI_DE_L_INSTANTANE_MS * 3)

        expect(ecriture.completer).not.toHaveBeenCalled()

        // La même séance, ses clés dans un autre ordre.
        const meme = seance()
        const reordonnee = Object.fromEntries(Object.entries(meme).reverse())
        ecriture.lireLaCopie.mockResolvedValueOnce({ page: { props: { workout: reordonnee } } })
        const derniere = monter({ origine: 'historique' })
        await vi.advanceTimersByTimeAsync(0)

        derniere.value.workout_lines[0].sets[0].weight = 90
        await vi.advanceTimersByTimeAsync(DELAI_DE_L_INSTANTANE_MS)

        expect(ecriture.completer).toHaveBeenCalledTimes(1)
        expect(ecriture.completer.mock.calls[0][0].props.workout.workout_lines[0].sets[0].weight).toBe(90)
    })

    /*
     * Un document rouvert par Retour : Inertia monte la page du document,
     * fraîche, puis remplace ses props par l'entrée d'historique, plus
     * ancienne, sans remonter la page (`InitialVisit.handleBackForward`). La
     * lecture de la copie, partie au montage, revient après : la copie est la
     * séance fraîche du départ, et la page montre déjà l'ancienne.
     */
    it('rendue par l’historique, ne verse rien quand ses props sont remplacées avant que la copie soit relue', async () => {
        const fraiche = {
            ...seance(),
            workout_lines: [
                { id: 1, sets: [...seance().workout_lines[0].sets, { id: 4, weight: 90, is_completed: true }] },
            ],
        }
        let rendreLaCopie
        ecriture.lireLaCopie.mockReturnValueOnce(new Promise((resoudre) => (rendreLaCopie = resoudre)))
        const seanceDesProps = ref(fraiche)
        const locale = monter({ origine: 'historique', depart: fraiche, seanceDesProps })

        seanceDesProps.value = seance()
        locale.value = seance()
        await vi.advanceTimersByTimeAsync(0)
        rendreLaCopie({ page: { props: { workout: JSON.parse(JSON.stringify(fraiche)) } } })
        await vi.advanceTimersByTimeAsync(DELAI_DE_L_INSTANTANE_MS * 3)
        window.dispatchEvent(new Event('pagehide'))

        expect(ecriture.completer).not.toHaveBeenCalled()
    })

    it('ne verse plus rien quand l’historique remplace la séance, et reprend à la réponse suivante du serveur', async () => {
        const seanceDesProps = ref(seance())
        const locale = monter({ seanceDesProps })
        const ancienne = { ...seance(), workout_lines: [{ id: 1, sets: [] }] }

        ecriture.origineDeLaPage.mockReturnValue('historique')
        seanceDesProps.value = ancienne
        locale.value = JSON.parse(JSON.stringify(ancienne))
        await vi.advanceTimersByTimeAsync(DELAI_DE_L_INSTANTANE_MS * 3)
        window.dispatchEvent(new Event('pagehide'))

        expect(ecriture.completer).not.toHaveBeenCalled()

        ecriture.origineDeLaPage.mockReturnValue('reseau')
        seanceDesProps.value = seance()
        locale.value = seance()
        await vi.advanceTimersByTimeAsync(0)
        locale.value.workout_lines[0].sets[0].weight = 85
        await vi.advanceTimersByTimeAsync(DELAI_DE_L_INSTANTANE_MS)

        expect(ecriture.completer).toHaveBeenCalledTimes(1)
        expect(ecriture.completer.mock.calls[0][0].props.workout.workout_lines[0].sets[0].weight).toBe(85)
    })

    it('au démontage, verse l’instantané qui attendait, et plus rien ensuite', async () => {
        const locale = monter()

        locale.value.workout_lines[0].sets[0].weight = 85
        await vi.advanceTimersByTimeAsync(10)
        montees.pop().unmount()

        expect(ecriture.completer).toHaveBeenCalledTimes(1)

        await vi.advanceTimersByTimeAsync(DELAI_DE_L_INSTANTANE_MS * 3)
        window.dispatchEvent(new Event('pagehide'))

        expect(ecriture.completer).toHaveBeenCalledTimes(1)
    })

    /*
     * Sur la vraie page, un ordre en vol et une rangée provisoire vont avec
     * une écriture en vol ou en file, que le service compte aussi ; ici, le
     * service ne compte rien, et chaque condition se vérifie seule.
     */
    it('n’en prend aucun tant qu’un ordre des exercices vole, même quand rien d’autre n’attend', async () => {
        const ordreEnVol = ref(1)
        const locale = monter({ ordreEnVol })

        locale.value.workout_lines[0].sets[0].weight = 85
        await vi.advanceTimersByTimeAsync(DELAI_DE_L_INSTANTANE_MS * 3)
        window.dispatchEvent(new Event('pagehide'))

        expect(ecriture.completer).not.toHaveBeenCalled()

        ordreEnVol.value = 0
        await vi.advanceTimersByTimeAsync(DELAI_DE_L_INSTANTANE_MS)

        expect(ecriture.completer).toHaveBeenCalledTimes(1)
    })

    it('n’en prend aucun tant qu’une rangée attend l’identifiant du serveur, même quand rien d’autre n’attend', async () => {
        const locale = monter()

        locale.value.workout_lines[0].sets.push({ id: 'temp-4', weight: 90, is_completed: false })
        await vi.advanceTimersByTimeAsync(DELAI_DE_L_INSTANTANE_MS * 3)
        window.dispatchEvent(new Event('pagehide'))

        expect(ecriture.completer).not.toHaveBeenCalled()

        locale.value.workout_lines.push({ id: 'temp-2', sets: [] })
        locale.value.workout_lines[0].sets[1].id = 4
        await vi.advanceTimersByTimeAsync(DELAI_DE_L_INSTANTANE_MS * 3)

        expect(ecriture.completer).not.toHaveBeenCalled()

        locale.value.workout_lines[1].id = 2
        await vi.advanceTimersByTimeAsync(DELAI_DE_L_INSTANTANE_MS)

        expect(ecriture.completer).toHaveBeenCalledTimes(1)
        expect(ecriture.completer.mock.calls[0][0].props.workout.workout_lines.map((ligne) => ligne.id)).toEqual([1, 2])
    })

    it('montée depuis sa copie, ne verse rien tant que la marque reste, réponse partielle comprise, puis verse une fois la marque partie', async () => {
        // Comme `usePage()` : la page d'Inertia, qu'une réponse partielle complète sans remonter la séance.
        const page = reactive(pageDeLaSeance({ horsLigne: { copieDu: 1, raison: 'reseau' } }))
        const seanceDesProps = ref(seance())
        const locale = monter({ page, origine: 'client', seanceDesProps })

        locale.value.workout_lines[0].sets[0].weight = 85
        await vi.advanceTimersByTimeAsync(DELAI_DE_L_INSTANTANE_MS * 3)
        window.dispatchEvent(new Event('pagehide'))

        // La séance redemandée seule (`only: ['workout']`) : la réponse garde la marque, la page reste une copie.
        ecriture.origineDeLaPage.mockReturnValue('reseau')
        seanceDesProps.value = seance()
        await nextTick()
        locale.value.workout_lines[0].sets[0].weight = 86
        await vi.advanceTimersByTimeAsync(DELAI_DE_L_INSTANTANE_MS * 3)
        window.dispatchEvent(new Event('pagehide'))

        expect(ecriture.completer).not.toHaveBeenCalled()
        expect(ecriture.lireLaCopie).not.toHaveBeenCalled()

        // La réponse complète qui suit emporte la marque : la séance est de nouveau celle du serveur.
        delete page.props.horsLigne
        await nextTick()
        await vi.advanceTimersByTimeAsync(DELAI_DE_L_INSTANTANE_MS)

        expect(ecriture.completer).toHaveBeenCalledTimes(1)
        expect(ecriture.completer.mock.calls[0][0].props.workout.workout_lines[0].sets[0].weight).toBe(86)
    })

    it('montée depuis sa copie, verse dès qu’une réponse complète emporte la marque, sa séance restée égale', async () => {
        const page = reactive(pageDeLaSeance({ horsLigne: { copieDu: 1, raison: 'reseau' } }))
        const locale = monter({ page, origine: 'client' })

        locale.value.workout_lines[0].sets[0].weight = 85
        await vi.advanceTimersByTimeAsync(DELAI_DE_L_INSTANTANE_MS * 3)

        expect(ecriture.completer).not.toHaveBeenCalled()

        // Inertia garde telle quelle une prop égale à celle qu'il tient : la marque seule change.
        ecriture.origineDeLaPage.mockReturnValue('reseau')
        delete page.props.horsLigne
        await nextTick()
        await vi.advanceTimersByTimeAsync(DELAI_DE_L_INSTANTANE_MS)

        expect(ecriture.completer).toHaveBeenCalledTimes(1)
        expect(ecriture.completer.mock.calls[0][0].props.workout.workout_lines[0].sets[0].weight).toBe(85)
    })

    it('ne reverse pas une séance qui n’a pas changé', async () => {
        const locale = monter()

        window.dispatchEvent(new Event('pagehide'))
        expect(ecriture.completer).not.toHaveBeenCalled()
        expect(ecriture.presser).toHaveBeenCalledTimes(1)

        locale.value.workout_lines[0].sets[0].weight = 85
        await vi.advanceTimersByTimeAsync(DELAI_DE_L_INSTANTANE_MS)
        locale.value.workout_lines[0].sets[0].weight = 85
        await vi.advanceTimersByTimeAsync(DELAI_DE_L_INSTANTANE_MS)

        expect(ecriture.completer).toHaveBeenCalledTimes(1)
    })
})
