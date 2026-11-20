import { ref } from 'vue'
import { router, usePage } from '@inertiajs/vue3'
import SyncService from '@/Utils/SyncService'
import { attendreAuPlus } from '@/Utils/attendreAuPlus'
import { revenirSiLaVisiteEchoue } from '@/Utils/miseAJourOptimiste'
import { useConfirmation } from '@/composables/useConfirmation'
import { ATTENTE_MAX_DE_LA_FILE_MS } from '@/composables/useReglagesDeLaSeance'
import { triggerHaptic } from '@/composables/useHaptics'

export const ECRITURES_ENCORE_EN_ROUTE = 'Des écritures sont encore en route : réessaie dans un instant.'

/**
 * Abandonner une séance vide : la question, puis `workouts.destroy`.
 *
 * Une visite d'Inertia, pas une écriture de la file : le serveur seul supprime,
 * l'appelant désactive le geste hors de sa portée. Les écritures de la séance
 * qui attendent encore (une ligne ajoutée puis retirée) partent d'abord : après
 * la suppression, la file les rejouerait contre une séance disparue.
 */
export const useAbandonDeLaSeance = () => {
    const page = usePage()
    const enCours = ref(false)

    const revenir = () => {
        enCours.value = false
        triggerHaptic('error')
    }

    const { ouvert, demander, annuler, confirmer } = useConfirmation(async (seance, termine) => {
        termine()
        enCours.value = true

        await attendreAuPlus(SyncService.attendreLesEcritures(), ATTENTE_MAX_DE_LA_FILE_MS)

        if (SyncService.ecrituresEnCours() > 0 || SyncService.enAttente() > 0) {
            const flash = page.props.flash ?? (page.props.flash = {})

            flash.error = ECRITURES_ENCORE_EN_ROUTE
            revenir()

            return
        }

        triggerHaptic('warning')
        router.delete(route('workouts.destroy', { workout: seance }), revenirSiLaVisiteEchoue(revenir))
    })

    return { enCours, ouvert, demander, annuler, confirmer }
}
