import { computed, onMounted, ref, watch } from 'vue'
import SyncService from '@/Utils/SyncService'
import { triggerHaptic } from '@/composables/useHaptics'
import { useListeReordonnable, useSousListesReordonnables } from '@/composables/useListeReordonnable'

/**
 * L'ordre des exercices d'une seance et celui des series de chaque exercice :
 * au doigt par la bibliotheque, aux fleches par le clavier, et l'ecriture au
 * serveur avec son repli.
 *
 * L'ordre part en ENTIER, pas par echange : deux lignes peuvent partager un
 * rang, et echanger deux rangs egaux n'ecrirait rien. Le repli d'un echec
 * revient a l'ordre CONFIRME par le serveur, jamais a un instantane pris a
 * l'appel, qui effacerait un second deplacement sans un mot.
 *
 * @param {{
 *   localWorkout: import('vue').Ref<object>,
 *   isFinished: import('vue').Ref<boolean>,
 *   pendingIds: { resolve: (id: unknown) => Promise<unknown> },
 *   nextWrite: (cle: string) => number,
 *   isLatestWrite: (cle: string, seq: number) => boolean,
 *   fieldWrites: { queue: (cle: string, envoyer: () => Promise<unknown>) => Promise<unknown> },
 *   reportSyncFailure: (message: string) => void,
 * }} page
 */
export const useOrdreDeLaSeance = ({
    localWorkout,
    isFinished,
    pendingIds,
    nextWrite,
    isLatestWrite,
    fieldWrites,
    reportSyncFailure,
}) => {
    /** Les ecritures d'ordre parties sans reponse : une fusion des props n'annule pas un ordre en vol. */
    const ordreEnVol = ref(0)

    const ordreConfirme = ref([])

    /** Le meme, par exercice, pour ses series. */
    const ordreDesSeriesConfirme = new Map()

    const listeDesExercices = ref(null)

    /** Ce qu'un lecteur d'ecran entend apres un deplacement. */
    const annonceReorganisation = ref('')

    /*
     * La bibliotheque mute CE tableau elle-meme : elle est donnee-d'abord, ce
     * qui laisse Vue proprietaire du DOM.
     */
    const lignesReordonnables = computed({
        get: () => localWorkout.value.workout_lines,
        set: (valeur) => {
            localWorkout.value.workout_lines = valeur
        },
    })

    const { rafraichir: rafraichirLeDeplacement } = useListeReordonnable(listeDesExercices, {
        valeurs: lignesReordonnables,
        handle: '[data-poignee-exercice]',
        estActif: () => !isFinished.value && localWorkout.value.workout_lines.length > 1,
        auDebut: () => triggerHaptic('tap'),
        aLaFin: () => persisterLOrdre(),
    })

    const memoriserLOrdreDesSeries = () => {
        localWorkout.value.workout_lines.forEach((ligne) => {
            if (!ordreDesSeriesConfirme.has(ligne.id) && Array.isArray(ligne.sets)) {
                ordreDesSeriesConfirme.set(
                    ligne.id,
                    ligne.sets.map((serie) => serie.id),
                )
            }
        })
    }

    onMounted(() => {
        ordreConfirme.value = localWorkout.value.workout_lines.map((ligne) => ligne.id)
        memoriserLOrdreDesSeries()
        void rafraichirLesSeries()
        rafraichirLeDeplacement()
    })

    watch(
        () => [isFinished.value, localWorkout.value.workout_lines.length],
        () => rafraichirLeDeplacement(),
    )

    /*
     * Les series vivent dans UNE liste par exercice : chaque conteneur se lie
     * separement, au fur et a mesure qu'il apparait.
     */
    const conteneursDeSeries = new Map()

    const poserLeConteneurDeSeries = (lineId) => (element) => {
        if (element === null) {
            conteneursDeSeries.delete(lineId)
            oublierLesSeries(lineId)

            return
        }

        conteneursDeSeries.set(lineId, element)
    }

    /**
     * L'exercice dont une serie est en vol. Sa rangee cesse alors d'ecouter le
     * glissement lateral : les deux gestes partent du meme endroit.
     */
    const serieEnDeplacement = ref(null)

    const generationDesSeries = ref(new Map())

    /**
     * La bibliotheque deplace le nœud elle-meme ; Vue, restee sur l'ancien
     * arrangement, ecrirait les numeros dans les mauvaises rangees. Changer la
     * generation les reconstruit dans l'ordre du tableau.
     */
    const reconstruireLesSeries = (lineId) => {
        const generations = new Map(generationDesSeries.value)

        generations.set(lineId, (generations.get(lineId) ?? 0) + 1)
        generationDesSeries.value = generations
    }

    const { rafraichir: rafraichirLesSeries, oublier: oublierLesSeries } = useSousListesReordonnables(
        () =>
            localWorkout.value.workout_lines.map((ligne) => ({
                cle: ligne.id,
                element: conteneursDeSeries.get(ligne.id) ?? null,
                valeurs: computed({
                    get: () => ligne.sets,
                    set: (valeur) => {
                        ligne.sets = valeur
                    },
                }),
            })),
        {
            handle: '[data-poignee-serie]',
            estActif: () => !isFinished.value,
            appuiLong: true,
            auDebut: (lineId) => {
                serieEnDeplacement.value = lineId
                triggerHaptic('tap')
            },
            aLaFin: (lineId) => {
                serieEnDeplacement.value = null
                reconstruireLesSeries(lineId)
                persisterLOrdreDesSeries(lineId)
            },
        },
    )

    watch(
        () => localWorkout.value.workout_lines.map((ligne) => `${ligne.id}:${ligne.sets?.length ?? 0}`).join(),
        () => rafraichirLesSeries(),
        { flush: 'post' },
    )

    const deplacerExercice = (ancien, nouveau) => {
        const lignes = localWorkout.value.workout_lines

        // Au doigt, la bibliotheque ne rend jamais un rang hors liste ; au
        // clavier, le premier exercice recoit « monter » comme les autres.
        if (nouveau < 0 || nouveau >= lignes.length || nouveau === ancien) {
            return
        }

        lignes.splice(nouveau, 0, ...lignes.splice(ancien, 1))

        annonceReorganisation.value = `${lignes[nouveau].exercise.name} déplacé en position ${nouveau + 1} sur ${lignes.length}`

        persisterLOrdre()
    }

    /**
     * Ecrire l'ordre courant, sans y toucher : au doigt, la bibliotheque a
     * DEJA reordonne le tableau ; aux fleches, `deplacerExercice` mute avant.
     */
    const persisterLOrdre = () => {
        const lignes = localWorkout.value.workout_lines

        ordreEnVol.value += 1

        const seq = nextWrite('line-order')

        const envoyer = () => {
            // La charge est une permutation COMPLETE : un deplacement plus recent
            // remplace exactement celui-ci, l'abandonner evite de faire retenir
            // au serveur un ordre perime.
            if (!isLatestWrite('line-order', seq)) {
                return Promise.resolve()
            }

            return Promise.all(lignes.map((ligne) => pendingIds.resolve(ligne.id)))
                .then((realLineIds) => {
                    if (realLineIds.some((id) => id === null)) {
                        throw Object.assign(new Error('exercice sans identifiant serveur'), { isOffline: false })
                    }

                    return SyncService.patch(route('api.v1.workouts.line-order', { workout: localWorkout.value.id }), {
                        lines: realLineIds,
                    }).then(() => {
                        ordreConfirme.value = realLineIds
                    })
                })
                .catch((err) => {
                    if (err.isOffline) {
                        return
                    }

                    const parId = new Map(localWorkout.value.workout_lines.map((ligne) => [ligne.id, ligne]))

                    localWorkout.value.workout_lines = ordreConfirme.value
                        .map((id) => parId.get(id))
                        .filter((ligne) => ligne !== undefined)

                    reportSyncFailure('L’ordre des exercices n’a pas pu être enregistré. Réessaie.')
                })
        }

        fieldWrites.queue('line-order', envoyer).finally(() => {
            ordreEnVol.value = Math.max(0, ordreEnVol.value - 1)
        })
    }

    /**
     * Une commande qui prend le doigt pour elle ne demarre pas un deplacement.
     * La pastille du numero fait exception : c'est un bouton pour porter les
     * fleches du clavier, et l'ecarter du geste rendait la rangee insaisissable
     * a l'endroit le plus naturel.
     */
    const ecarterLesCommandes = (evenement) => {
        const commande = evenement.target.closest('button, input, select, textarea, a')

        if (commande !== null && !commande.hasAttribute('data-poignee-clavier')) {
            evenement.stopPropagation()
        }
    }

    /** Une seule serie ne se reordonne pas, et une seance close ne bouge plus. */
    const peutReordonner = (ligne) => !isFinished.value && ligne.sets.length > 1

    const deplacerSerie = (ligne, ancien, nouveau) => {
        if (nouveau < 0 || nouveau >= ligne.sets.length || nouveau === ancien) {
            return
        }

        ligne.sets.splice(nouveau, 0, ...ligne.sets.splice(ancien, 1))

        persisterLOrdreDesSeries(ligne.id)
    }

    const persisterLOrdreDesSeries = (lineId) => {
        const ligne = localWorkout.value.workout_lines.find((candidate) => candidate.id === lineId)

        if (ligne === undefined) {
            return
        }

        const avantEcriture = ordreDesSeriesConfirme.get(lineId) ?? ligne.sets.map((serie) => serie.id)
        const cle = `set-order:${lineId}`
        const seq = nextWrite(cle)

        const envoyer = () => {
            if (!isLatestWrite(cle, seq)) {
                return Promise.resolve()
            }

            return Promise.all([pendingIds.resolve(lineId), ...ligne.sets.map((serie) => pendingIds.resolve(serie.id))])
                .then(([realLineId, ...realSetIds]) => {
                    if (realLineId === null || realSetIds.some((id) => id === null)) {
                        throw Object.assign(new Error('série sans identifiant serveur'), { isOffline: false })
                    }

                    return SyncService.patch(route('api.v1.workout-lines.set-order', { workoutLine: realLineId }), {
                        sets: realSetIds,
                    }).then(() => {
                        ordreDesSeriesConfirme.set(lineId, realSetIds)
                    })
                })
                .catch((err) => {
                    if (err.isOffline) {
                        return
                    }

                    const parId = new Map(ligne.sets.map((serie) => [serie.id, serie]))

                    ligne.sets = avantEcriture.map((id) => parId.get(id)).filter((serie) => serie !== undefined)

                    reportSyncFailure('L’ordre des séries n’a pas pu être enregistré. Réessaie.')
                })
        }

        fieldWrites.queue(cle, envoyer)
    }

    return {
        ordreEnVol,
        ordreConfirme,
        memoriserLOrdreDesSeries,
        listeDesExercices,
        annonceReorganisation,
        poserLeConteneurDeSeries,
        serieEnDeplacement,
        generationDesSeries,
        deplacerExercice,
        deplacerSerie,
        ecarterLesCommandes,
        peutReordonner,
    }
}
