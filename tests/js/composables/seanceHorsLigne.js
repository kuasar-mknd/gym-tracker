import { computed, defineComponent, h, ref } from 'vue'
import { mount, flushPromises } from '@vue/test-utils'

import { chargerSyncService } from '../utils/fileHorsLigne'

/**
 * Une séance montée comme `Pages/Workouts/Show.vue` la monte — les mêmes
 * composables, câblés de la même façon —, avec le vrai `SyncService` et un faux
 * serveur derrière `Utils/http`. Seuls le réseau et le serveur sont simulés :
 * c'est ce qui permet de rejouer une coupure, un rechargement et un vidage
 * comme ils se passent dans une salle sans réseau.
 *
 * Le fichier de test déclare lui-même ses `vi.mock` (ils ne valent que là) :
 * `@/Utils/http` vers `serveur.repondre`, `@inertiajs/vue3`,
 * `@/composables/useHaptics` et `@formkit/drag-and-drop/vue`.
 */

/** Les adresses de Ziggy dont la séance se sert. */
export const routeDeTest = (nom, parametres = {}) => {
    const valeur = Object.values(parametres)[0]

    return {
        'api.v1.sets.store': '/api/v1/sets',
        'api.v1.sets.update': `/api/v1/sets/${valeur}`,
        'api.v1.sets.destroy': `/api/v1/sets/${valeur}`,
        'api.v1.workout-lines.store': '/api/v1/workout-lines',
        'api.v1.workout-lines.destroy': `/api/v1/workout-lines/${valeur}`,
        'api.v1.workout-lines.set-order': `/api/v1/workout-lines/${valeur}/set-order`,
        'api.v1.workouts.line-order': `/api/v1/workouts/${valeur}/line-order`,
        'workouts.update': `/workouts/${valeur}`,
        'templates.save-from-workout': `/templates/${valeur}`,
    }[nom]
}

/**
 * Un serveur qui retient ce qu'il a créé, et un réseau qu'on coupe.
 *
 * @param {{ series?: Array<object> }} depart les séries que le serveur détient déjà
 */
export const creerUnFauxServeur = ({ series = [] } = {}) => {
    const serveur = {
        enLigne: true,
        /** Les requêtes qui ont atteint le serveur, dans l'ordre. */
        requetes: [],
        /** Les séries en base, par identifiant. */
        series: new Map(series.map((serie) => [serie.id, { ...serie }])),
        lignes: new Map(),
        prochaineSerie: 100,
        prochaineLigne: 70,
        /** Une réponse imposée à la prochaine requête qui atteint le serveur. */
        imposer: [],

        repondre: async (config) => {
            if (!serveur.enLigne) {
                throw { code: 'ERR_NETWORK', request: {} }
            }

            const { method, url, data } = config
            serveur.requetes.push({
                method,
                url,
                data: data === undefined ? undefined : JSON.parse(JSON.stringify(data)),
            })

            const imposee = serveur.imposer.shift()

            if (imposee) {
                return imposee(config)
            }

            const serie = /^\/api\/v1\/sets\/(\d+)$/.exec(url)

            if (method === 'post' && url === '/api/v1/workout-lines') {
                const ligne = { id: serveur.prochaineLigne++, ...data, sets: [], recommended_values: null }
                serveur.lignes.set(ligne.id, ligne)

                return { data: { data: ligne } }
            }

            if (method === 'post' && url === '/api/v1/sets') {
                if (!serveur.lignes.has(data.workout_line_id) && typeof data.workout_line_id !== 'number') {
                    throw { response: { status: 422, data: { message: 'workout_line_id' } } }
                }

                const creee = {
                    id: serveur.prochaineSerie++,
                    ...data,
                    created_at: 'c',
                    updated_at: 'u',
                    personal_record: null,
                }
                serveur.series.set(creee.id, creee)

                return { data: { data: creee } }
            }

            if (method === 'patch' && serie) {
                const modifiee = { ...serveur.series.get(Number(serie[1])), ...data, updated_at: 'u2' }
                serveur.series.set(modifiee.id, modifiee)

                return { data: { data: modifiee } }
            }

            if (method === 'delete' && serie) {
                serveur.series.delete(Number(serie[1]))

                return { data: null }
            }

            return { data: {} }
        },

        /** Les requêtes, en une ligne chacune, pour comparer d'un coup d'œil. */
        resume: () =>
            serveur.requetes.map(
                ({ method, url, data }) => `${method} ${url}${data === undefined ? '' : ` ${JSON.stringify(data)}`}`,
            ),
    }

    return serveur
}

/**
 * Monte la séance et rend ce que la page tient : la copie locale, les
 * composables, la fusion des props et le service de synchronisation.
 *
 * @param {object} seance la séance telle que le serveur la rend
 * @param {{ exercices?: Array<object> }} options
 */
export const monterLaSeance = async (seance, { exercices = [{ id: 7, name: 'Squat', type: 'strength' }] } = {}) => {
    const sync = await chargerSyncService({ compte: 1 })
    await sync.pending

    const { useIdentiteDesRangees } = await import('@/composables/useIdentiteDesRangees')
    const { useRapportDeSynchronisation } = await import('@/composables/useRapportDeSynchronisation')
    const { useBrouillonsDeSeries } = await import('@/composables/useBrouillonsDeSeries')
    const { useOrdreDeLaSeance } = await import('@/composables/useOrdreDeLaSeance')
    const { useSeriesDeLaSeance } = await import('@/composables/useSeriesDeLaSeance')
    const { useLignesDeLaSeance } = await import('@/composables/useLignesDeLaSeance')
    const { useReglagesDeLaSeance } = await import('@/composables/useReglagesDeLaSeance')
    const { createWriteQueue, createWriteSequencer } = await import('@/Utils/writeOrdering')
    const { fusionnerLaSeance } = await import('@/Utils/fusionDeSeance')

    let page

    const Seance = defineComponent({
        setup() {
            const localWorkout = ref(JSON.parse(JSON.stringify(seance)))
            const identite = useIdentiteDesRangees()
            const rapport = useRapportDeSynchronisation({
                page: { props: {} },
                exercices: () => exercices,
                lignes: () => localWorkout.value.workout_lines,
            })
            const { next: nextWrite, isLatest: isLatestWrite } = createWriteSequencer()
            const fieldWrites = createWriteQueue()
            const brouillons = useBrouillonsDeSeries()

            const ordre = useOrdreDeLaSeance({
                localWorkout,
                isFinished: computed(() => false),
                pendingIds: identite.pendingIds,
                nextWrite,
                isLatestWrite,
                fieldWrites,
                reportSyncFailure: rapport.reportSyncFailure,
            })

            const series = useSeriesDeLaSeance({
                localWorkout,
                pendingIds: identite.pendingIds,
                queuedLineIds: identite.queuedLineIds,
                nouvelIdTemporaire: identite.nouvelIdTemporaire,
                newRowKey: identite.newRowKey,
                rowKey: identite.rowKey,
                nextWrite,
                isLatestWrite,
                fieldWrites,
                lastConfirmed: brouillons.lastConfirmed,
                rememberConfirmed: brouillons.rememberConfirmed,
                writeDraftField: brouillons.writeDraftField,
                clearDraftField: brouillons.clearDraftField,
                oublierLaSerie: brouillons.oublierLaSerie,
                markUnsynced: rapport.markUnsynced,
                clearUnsynced: rapport.clearUnsynced,
                reportSyncFailure: rapport.reportSyncFailure,
                reportEditFailure: rapport.reportEditFailure,
                apresValidation: () => {},
            })

            const lignes = useLignesDeLaSeance({
                localWorkout,
                pendingIds: identite.pendingIds,
                queuedLineIds: identite.queuedLineIds,
                nouvelIdTemporaire: identite.nouvelIdTemporaire,
                newRowKey: identite.newRowKey,
                localExercises: ref(exercices),
                showAddExercise: ref(false),
                oublierLesEcrituresDeLaLigne: series.oublierLesEcrituresDeLaLigne,
                reportSyncFailure: rapport.reportSyncFailure,
            })

            const reglages = useReglagesDeLaSeance({
                localWorkout,
                viderLesEcritures: () => series.flushAllPendingUpdates(),
            })

            /** Ce que fait l'observateur des props de la page. */
            const rafraichir = (copieDuServeur) => {
                localWorkout.value = fusionnerLaSeance(copieDuServeur, localWorkout.value, {
                    estNonSynchronisee: (set) => rapport.unsyncedSetIds.value.has(String(set.id)),
                    validationEnVol: (set) => series.completionsEnVol.has(`completion:${identite.rowKey(set)}`),
                    ordreLocalPrime: ordre.ordreEnVol.value > 0,
                })
            }

            page = {
                localWorkout,
                ligne: (index = 0) => localWorkout.value.workout_lines[index],
                ...identite,
                ...rapport,
                ...ordre,
                ...series,
                ...lignes,
                ...reglages,
                rafraichir,
            }

            return { listeDesExercices: ordre.listeDesExercices }
        },
        render() {
            return h('div', { ref: 'listeDesExercices' })
        },
    })

    const wrapper = mount(Seance)
    await flushPromises()

    return { wrapper, sync, ...page }
}

/** Les entrées de la file telles qu'un rechargement les relirait. */
export const fileDurable = () => JSON.parse(localStorage.getItem('offline_sync_queue') ?? '[]')
