import { ref } from 'vue'
import { router, useForm } from '@inertiajs/vue3'
import { formatToLocalISO, formatToUTC } from '@/Utils/date'
import SyncService from '@/Utils/SyncService'
import { triggerHaptic } from '@/composables/useHaptics'

/**
 * Ce que « Terminer » attend la file hors ligne, au plus. Un vidage n'a pas de
 * délai maximum : une requête par entrée, sur un réseau qui peut être lent.
 * Au-delà, l'écran rend la main et dit combien de modifications attendent ;
 * le vidage, lui, continue (#1961).
 */
export const ATTENTE_MAX_DE_LA_FILE_MS = 8000

/** Attend `promesse`, mais pas plus de `delai` millisecondes. */
const attendreAuPlus = (promesse, delai) =>
    new Promise((resoudre) => {
        const echeance = setTimeout(resoudre, delai)
        const fin = () => {
            clearTimeout(echeance)
            resoudre()
        }

        promesse.then(fin, fin)
    })

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

    /**
     * Une clôture attend les écritures, puis la réponse du serveur. Pendant ce
     * temps, la modale le montre, et un nouvel appui sur « Confirmer » ne fait
     * rien : chacun envoyait sa propre clôture une fois le vidage fini.
     */
    const clotureEnCours = ref(false)

    const finishWorkout = () => {
        ecrituresEnAttente.value = 0
        showFinishModal.value = true
    }
    const confirmFinishWorkout = async () => {
        if (clotureEnCours.value) {
            return
        }

        clotureEnCours.value = true

        /** Partie, la clôture se libère à la fin de sa visite, pas avant. */
        let cloturePartie = false

        try {
            /**
             * Awaited, not merely started. Closing the session revokes the right to
             * write to its sets, so the last value typed has to be accepted before the
             * workout is finished — otherwise it arrives at a closed session, is refused
             * 403, and is reverted on a page that has already navigated away.
             */
            await viderLesEcritures()

            /*
             * La file hors ligne aussi, et pour la même raison. Elle n'était ni
             * vidée ni attendue : quand le réseau revenait sans évènement
             * `online`, ou pendant un vidage en cours, les dernières séries
             * partaient après la clôture et revenaient refusées (#1961).
             * `processQueue` s'enchaîne derrière un vidage en cours. Si la file
             * ne se vide pas — réseau absent, serveur qui redémarre, session à
             * renouveler, ou réseau trop lent pour qu'on l'attende —, la séance
             * reste ouverte et la modale dit combien de modifications attendent.
             */
            await attendreAuPlus(SyncService.processQueue(), ATTENTE_MAX_DE_LA_FILE_MS)

            ecrituresEnAttente.value = SyncService.enAttente()

            if (ecrituresEnAttente.value > 0) {
                return
            }

            cloturePartie = true

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
                    onFinish: () => {
                        clotureEnCours.value = false
                    },
                },
            )
        } finally {
            if (!cloturePartie) {
                clotureEnCours.value = false
            }
        }
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
        clotureEnCours,
        finishWorkout,
        confirmFinishWorkout,
        showSettingsModal,
        settingsForm,
        updateSettings,
    }
}
