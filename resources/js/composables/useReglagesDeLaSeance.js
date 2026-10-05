import { ref } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import { formatToLocalISO, formatToUTC } from '@/Utils/date'
import SyncService from '@/Utils/SyncService'
import { triggerHaptic } from '@/composables/useHaptics'

/**
 * Les reglages de la seance, sa fin et son enregistrement comme modele :
 * trois allers-retours Inertia qui laissent la page en place.
 *
 * @param {{ localWorkout: import('vue').Ref<object>, viderLesEcritures: () => Promise<unknown> }} page
 */
export const useReglagesDeLaSeance = ({ localWorkout, viderLesEcritures }) => {
    const savingTemplate = ref(false)
    const saveAsTemplate = () => {
        savingTemplate.value = true
        router.post(
            route('templates.save-from-workout', { workout: localWorkout.value.id }),
            {},
            {
                preserveScroll: true,
                onFinish: () => (savingTemplate.value = false),
            },
        )
    }

    const showFinishModal = ref(false)

    /** Les écritures restées en file quand on a voulu terminer : la modale le dit. */
    const ecrituresEnAttente = ref(0)

    const finishWorkout = () => {
        ecrituresEnAttente.value = 0
        showFinishModal.value = true
    }
    const confirmFinishWorkout = async () => {
        /**
         * Awaited, not merely started. Closing the session revokes the right to
         * write to its sets, so the last value typed has to be accepted before the
         * workout is finished — otherwise it arrives at a closed session, is refused
         * 403, and is reverted on a page that has already navigated away.
         */
        await viderLesEcritures()

        /*
         * La file hors ligne aussi, et pour la même raison. Elle n'était ni
         * vidée ni attendue : quand le réseau revenait sans évènement `online`,
         * ou pendant un vidage en cours, les dernières séries partaient après la
         * clôture et revenaient refusées (#1961). `processQueue` s'enchaîne
         * derrière un vidage en cours. Si la file ne se vide pas — réseau
         * absent, serveur qui redémarre, session à renouveler —, la séance
         * reste ouverte et la modale dit combien de modifications attendent.
         */
        await SyncService.processQueue()

        ecrituresEnAttente.value = SyncService.enAttente()

        if (ecrituresEnAttente.value > 0) {
            return
        }

        router.patch(
            route('workouts.update', { workout: localWorkout.value.id }),
            { is_finished: true },
            {
                onStart: () => {
                    showFinishModal.value = false
                },
                onSuccess: () => {
                    triggerHaptic('success')
                },
            },
        )
    }

    const showSettingsModal = ref(false)

    const settingsForm = useForm({
        name: localWorkout.value.name,
        started_at: formatToLocalISO(localWorkout.value.started_at),
        notes: localWorkout.value.notes || '',
    })

    const updateSettings = () => {
        settingsForm
            .transform((data) => ({ ...data, started_at: formatToUTC(data.started_at) }))
            .patch(route('workouts.update', { workout: localWorkout.value.id }), {
                preserveScroll: true,
                onSuccess: () => {
                    showSettingsModal.value = false
                },
            })
    }

    return {
        savingTemplate,
        saveAsTemplate,
        showFinishModal,
        ecrituresEnAttente,
        finishWorkout,
        confirmFinishWorkout,
        showSettingsModal,
        settingsForm,
        updateSettings,
    }
}
