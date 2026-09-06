import { useTransportDeSerie } from '@/composables/useTransportDeSerie'
import { useSaisieDeSerie } from '@/composables/useSaisieDeSerie'
import { useValidationDeSerie } from '@/composables/useValidationDeSerie'
import { useAjoutEtRetraitDeSerie } from '@/composables/useAjoutEtRetraitDeSerie'

/**
 * Les écritures d'une série, en un seul appel pour la page : le transport, la
 * saisie, la validation, l'ajout et le retrait sont quatre composables qui se
 * prêtent ce qu'ils partagent (les deux appels au serveur, le vidage de la
 * saisie, l'oubli des rafales). La page ne garde que l'identité des rangées,
 * le minuteur de repos et la fusion des props.
 *
 * @param {{
 *   localWorkout: import('vue').Ref<object>,
 *   pendingIds: import('@/Utils/pendingIds').PendingIds,
 *   queuedLineIds: Set<string>,
 *   nouvelIdTemporaire: () => string,
 *   newRowKey: () => string,
 *   rowKey: (rangee: object) => string|number,
 *   nextWrite: (cle: string) => number,
 *   isLatestWrite: (cle: string, seq: number) => boolean,
 *   fieldWrites: { queue: (cle: string, envoyer: () => Promise<unknown>) => Promise<unknown>, forget: (cle: string) => void },
 *   lastConfirmed: (set: object, field: string, repli: unknown) => unknown,
 *   rememberConfirmed: (setId: unknown, field: string, valeur: unknown) => void,
 *   writeDraftField: (setId: unknown, field: string, valeur: unknown) => void,
 *   clearDraftField: (setId: unknown, field: string) => void,
 *   oublierLaSerie: (setId: unknown) => void,
 *   markUnsynced: (setId: unknown) => void,
 *   clearUnsynced: (setId: unknown, realId?: unknown) => void,
 *   reportSyncFailure: (message: string) => void,
 *   reportEditFailure: (message: string) => void,
 *   apresValidation: (exerciseRestTime: number|undefined) => void,
 * }} page
 */
export const useSeriesDeLaSeance = ({
    localWorkout,
    pendingIds,
    queuedLineIds,
    nouvelIdTemporaire,
    newRowKey,
    rowKey,
    nextWrite,
    isLatestWrite,
    fieldWrites,
    lastConfirmed,
    rememberConfirmed,
    writeDraftField,
    clearDraftField,
    oublierLaSerie,
    markUnsynced,
    clearUnsynced,
    reportSyncFailure,
    reportEditFailure,
    apresValidation,
}) => {
    const { patchSet, deleteSet } = useTransportDeSerie({ pendingIds, markUnsynced })

    const { flushAllPendingUpdates, flushPendingUpdates, updateSet, saisieEnCours, saisieTerminee, oublierLesRafales } =
        useSaisieDeSerie({
            patchSet,
            nextWrite,
            isLatestWrite,
            fieldWrites,
            lastConfirmed,
            rememberConfirmed,
            writeDraftField,
            clearDraftField,
            reportEditFailure,
        })

    const { completionsEnVol, toggleSetCompletion } = useValidationDeSerie({
        patchSet,
        nextWrite,
        isLatestWrite,
        flushPendingUpdates,
        reportSyncFailure,
        apresValidation,
    })

    const { addSet, removeSet, oublierLesEcrituresDeLaLigne } = useAjoutEtRetraitDeSerie({
        localWorkout,
        pendingIds,
        queuedLineIds,
        nouvelIdTemporaire,
        newRowKey,
        rowKey,
        fieldWrites,
        deleteSet,
        oublierLesRafales,
        oublierLaSerie,
        markUnsynced,
        clearUnsynced,
        reportSyncFailure,
    })

    return {
        patchSet,
        flushAllPendingUpdates,
        flushPendingUpdates,
        toggleSetCompletion,
        addSet,
        updateSet,
        removeSet,
        saisieEnCours,
        saisieTerminee,
        completionsEnVol,
        oublierLesEcrituresDeLaLigne,
    }
}
