import { ref } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import { formatToLocalISO, formatToUTC } from '@/Utils/date'
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
    const finishWorkout = () => {
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

    /**
     * Le début ne part que si l'utilisateur l'a changé.
     *
     * Le champ n'affiche que la minute : renvoyer une heure intacte écrivait
     * quand même une autre valeur, tronquée de ses secondes, et faisait passer un
     * simple renommage pour un déplacement de la séance (#1952). Une heure
     * changée part en UTC : le navigateur n'est pas forcément dans le fuseau de
     * l'application, et le serveur ramène l'instant au sien.
     */
    const updateSettings = () => {
        settingsForm
            .transform(({ started_at, ...reglages }) =>
                started_at === formatToLocalISO(localWorkout.value.started_at)
                    ? reglages
                    : { ...reglages, started_at: formatToUTC(started_at) },
            )
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
        finishWorkout,
        confirmFinishWorkout,
        showSettingsModal,
        settingsForm,
        updateSettings,
    }
}
