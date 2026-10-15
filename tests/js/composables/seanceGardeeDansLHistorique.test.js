import { describe, it, expect, vi, afterEach } from 'vitest'
import { mount } from '@vue/test-utils'
import { defineComponent, h, nextTick, reactive } from 'vue'

const inertia = vi.hoisted(() => ({ remember: vi.fn() }))

vi.mock('@inertiajs/vue3', () => ({ router: { remember: (...args) => inertia.remember(...args) } }))
vi.mock('@/Utils/SyncService', () => ({ default: { entreesDuCompte: () => [] } }))

import { useBrouillonsDeSeries } from '@/composables/useBrouillonsDeSeries'
import { SOUVENIR_DE_LA_SEANCE, useSeanceEtSaFile } from '@/composables/useSeanceEtSaFile'

/*
 * L'entrée d'historique de la séance, tenue à jour de ce que la page a fait
 * accepter (#2048). Les boutons Retour et Avance rendent la page de la visite,
 * sans le serveur : sans ce souvenir, ce qu'on avait enregistré depuis par
 * l'API en manquait. Et comme Inertia l'écrit dans l'entrée COURANTE, chiffrée
 * avec la clé de l'onglet, il ne doit plus rien écrire une fois qu'une autre
 * page la prend, ni quand un autre compte a pris l'onglet ou l'appareil.
 */

const seanceDuServeur = () => ({
    id: 5,
    name: 'Séance',
    workout_lines: [{ id: 1, exercise_id: 7, sets: [{ id: 11, weight: 80, reps: 5, is_completed: true }] }],
})

/** La page d'Inertia telle que `usePage()` la rend pour la séance 5, au compte 1. */
const pageDeLaSeance = (rememberedState = {}) =>
    reactive({
        component: 'Workouts/Show',
        url: '/workouts/5',
        props: { auth: { user: { id: 1 } } },
        rememberedState,
    })

const montees = []

/** Monte ce que la page de séance tire de `useSeanceEtSaFile`. */
const ouvrir = (page, seance = seanceDuServeur(), valeursDeRepli = undefined) => {
    let ouverture

    montees.push(
        mount(
            defineComponent({
                setup() {
                    ouverture = useSeanceEtSaFile({ seance, exercices: [], page, valeursDeRepli })

                    return () => h('div')
                },
            }),
        ),
    )

    return ouverture
}

/** La dernière séance gardée dans l'entrée d'historique. */
const derniereGardee = () => inertia.remember.mock.calls.at(-1)?.[0]

const avantLaPage = (page) => document.dispatchEvent(new CustomEvent('inertia:beforeUpdate', { detail: { page } }))

afterEach(() => {
    montees.splice(0).forEach((page) => page.unmount())
    inertia.remember.mockReset()
    localStorage.clear()
    sessionStorage.clear()
})

describe('la séance de départ', () => {
    it('est celle que l’entrée d’historique a gardée pour cette séance', () => {
        const gardee = {
            ...seanceDuServeur(),
            workout_lines: [
                {
                    id: 1,
                    exercise_id: 7,
                    sets: [
                        { id: 11, weight: 80, reps: 5, is_completed: true },
                        { id: 12, weight: 82.5, reps: 5, is_completed: true },
                    ],
                },
            ],
        }

        const { localWorkout, seanceDeDepart } = ouvrir(pageDeLaSeance({ [SOUVENIR_DE_LA_SEANCE]: gardee }))

        expect(seanceDeDepart).toEqual(gardee)
        expect(localWorkout.value.workout_lines[0].sets.map((serie) => serie.id)).toEqual([11, 12])
    })

    it('est celle du serveur quand l’entrée n’a rien gardé, ou a gardé une autre séance', () => {
        const autre = { id: 6, workout_lines: [] }

        expect(ouvrir(pageDeLaSeance()).seanceDeDepart).toEqual(seanceDuServeur())
        expect(ouvrir(pageDeLaSeance({ [SOUVENIR_DE_LA_SEANCE]: autre })).seanceDeDepart).toEqual(seanceDuServeur())
        expect(ouvrir(null).localWorkout.value).toEqual(seanceDuServeur())
    })
})

describe('l’entrée d’historique de la séance', () => {
    it('garde chaque changement de ce que le serveur connaît, et rien de ce qu’il ne connaît pas encore', async () => {
        const { localWorkout } = ouvrir(pageDeLaSeance())
        const series = localWorkout.value.workout_lines[0].sets

        // Rien n'a changé depuis l'ouverture : l'entrée a déjà cette séance.
        await nextTick()
        expect(inertia.remember).not.toHaveBeenCalled()

        // Une série dont la création vole : la file ou la réponse la rendront.
        series.push({ id: 'temp-1', _rowKey: 'row-1', weight: 80, reps: 5, is_completed: false })
        await nextTick()
        expect(inertia.remember).not.toHaveBeenCalled()

        // Le serveur l'a créée : l'entrée la garde, sans la clef de l'écran.
        series[1].id = 100
        await nextTick()
        expect(inertia.remember).toHaveBeenCalledTimes(1)
        expect(inertia.remember).toHaveBeenCalledWith(expect.any(Object), SOUVENIR_DE_LA_SEANCE)
        expect(derniereGardee().workout_lines[0].sets).toEqual([
            { id: 11, weight: 80, reps: 5, is_completed: true },
            { id: 100, weight: 80, reps: 5, is_completed: false },
        ])

        // Revenue au même état dans le même tour : la même séance ne s'écrit pas deux fois.
        series[1].weight = 82.5
        series[1].weight = 80
        await nextTick()
        expect(inertia.remember).toHaveBeenCalledTimes(1)
    })

    /*
     * Gardée telle qu'à l'écran, une valeur refusée après le départ de la page
     * revenait au bouton Avance comme enregistrée, et la page la relevait comme
     * confirmée (#2048).
     */
    it('garde la valeur de repli d’une écriture qui attend, et se réécrit quand le serveur la confirme', async () => {
        const brouillons = useBrouillonsDeSeries()
        const { localWorkout } = ouvrir(pageDeLaSeance(), seanceDuServeur(), brouillons.valeursDeRepli)
        const [serie] = localWorkout.value.workout_lines[0].sets

        // Une saisie, et sa coche, dont le serveur n'a rien dit : l'entrée garde ce qu'il connaît.
        serie.weight = 99999
        serie.is_completed = false
        brouillons.noterUneEcritureNonConfirmee('11_weight', serie, 'weight', 80)
        brouillons.noterUneEcritureNonConfirmee('completion:11', serie, 'is_completed', true)
        await nextTick()
        expect(inertia.remember).not.toHaveBeenCalled()

        // Le poids est refusé : l'écran le rétablit, l'entrée n'a rien à réécrire.
        serie.weight = 80
        brouillons.oublierLEcritureNonConfirmee('11_weight')
        await nextTick()
        expect(inertia.remember).not.toHaveBeenCalled()

        // La coche est acceptée : rien ne change à l'écran, l'entrée la prend.
        brouillons.oublierLEcritureNonConfirmee('completion:11')
        await nextTick()
        expect(inertia.remember).toHaveBeenCalledTimes(1)
        expect(derniereGardee().workout_lines[0].sets[0]).toEqual({ id: 11, weight: 80, reps: 5, is_completed: false })
    })

    it('ne s’écrit plus dès qu’une autre page arrive, pas même une dernière fois', async () => {
        const { localWorkout } = ouvrir(pageDeLaSeance())
        const [serie] = localWorkout.value.workout_lines[0].sets

        serie.reps = 6
        await nextTick()
        expect(inertia.remember).toHaveBeenCalledTimes(1)

        /*
         * Changée dans le même tour que la réponse de la visite. L'entrée
         * courante sera celle de la page suivante, et la clé de l'onglet vient
         * peut-être de partir avec le compte (`historiqueDuCompte` écoute le
         * même évènement, avant la page) : rien ne s'écrit plus.
         */
        serie.reps = 7
        avantLaPage({ component: 'Workouts/Index', url: '/workouts' })
        await nextTick()

        expect(inertia.remember).toHaveBeenCalledTimes(1)
        expect(derniereGardee().workout_lines[0].sets[0].reps).toBe(6)
    })

    it('reste tenue quand la séance elle-même revient du serveur', async () => {
        const { localWorkout } = ouvrir(pageDeLaSeance())

        avantLaPage({ component: 'Workouts/Show', url: '/workouts/5?rafraichie=1' })
        expect(inertia.remember).not.toHaveBeenCalled()

        localWorkout.value.workout_lines[0].sets[0].reps = 6
        await nextTick()
        expect(derniereGardee().workout_lines[0].sets[0].reps).toBe(6)
    })

    it('ne garde plus rien après un retour dans l’historique, mais une ancre ne change pas d’entrée', async () => {
        const { localWorkout } = ouvrir(pageDeLaSeance())
        const [serie] = localWorkout.value.workout_lines[0].sets

        window.dispatchEvent(new PopStateEvent('popstate', { state: null }))
        serie.reps = 6
        await nextTick()
        expect(inertia.remember).toHaveBeenCalledTimes(1)

        window.dispatchEvent(new PopStateEvent('popstate', { state: { page: {} } }))
        serie.reps = 7
        await nextTick()
        expect(inertia.remember).toHaveBeenCalledTimes(1)
    })

    it('ne garde plus rien quand un autre compte a pris l’appareil ou l’onglet, même revenu depuis', async () => {
        localStorage.setItem('gym-tracker:titulaire-de-l-appareil', 'compte:1')
        sessionStorage.setItem('gym-tracker:titulaire-de-l-onglet', 'compte:1')
        const { localWorkout } = ouvrir(pageDeLaSeance())
        const [serie] = localWorkout.value.workout_lines[0].sets

        serie.reps = 6
        await nextTick()
        expect(inertia.remember).toHaveBeenCalledTimes(1)

        // Un autre onglet a ouvert la session d'un autre compte : celui-ci a jeté sa clé.
        localStorage.setItem('gym-tracker:titulaire-de-l-appareil', 'compte:2')
        serie.reps = 7
        await nextTick()
        localStorage.setItem('gym-tracker:titulaire-de-l-appareil', 'compte:1')
        serie.reps = 8
        await nextTick()
        expect(inertia.remember).toHaveBeenCalledTimes(1)

        // Même chose quand l'onglet lui-même est passé à un autre compte.
        const autre = ouvrir(pageDeLaSeance())
        sessionStorage.setItem('gym-tracker:titulaire-de-l-onglet', 'invite')
        autre.localWorkout.value.workout_lines[0].sets[0].reps = 9
        await nextTick()
        expect(inertia.remember).toHaveBeenCalledTimes(1)
    })

    it('ne garde rien quand la page d’Inertia n’est plus la séance', async () => {
        const page = pageDeLaSeance()
        const { localWorkout } = ouvrir(page)

        page.component = 'Workouts/Index'
        page.url = '/workouts'
        localWorkout.value.workout_lines[0].sets[0].reps = 6
        await nextTick()

        expect(inertia.remember).not.toHaveBeenCalled()
    })

    it('ne garde rien sans page d’Inertia, ni sans compte dans la page', async () => {
        const sansComposant = ouvrir(reactive({ props: {} }))
        const sansPage = ouvrir(null)
        const sansCompte = ouvrir(reactive({ component: 'Workouts/Show', url: '/workouts/5', props: {} }))

        for (const { localWorkout } of [sansComposant, sansPage, sansCompte]) {
            localWorkout.value.workout_lines[0].sets[0].reps = 6
        }
        await nextTick()

        expect(inertia.remember).not.toHaveBeenCalled()
    })
})
