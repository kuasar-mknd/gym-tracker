import { computed, getCurrentInstance, onMounted, onUnmounted, ref } from 'vue'
import { router, usePage } from '@inertiajs/vue3'
import SyncService from '@/Utils/SyncService'
import { estUneCopie } from '@/Utils/copiesServies'
import {
    adresseDUneSeanceEnAttente,
    cleDeLAdresseEnAttente,
    emporteesParLeRefus,
    estUneCreationDeSeance,
    messageDuRefusDeLaSeance,
    seanceEnCoursAvecLaFile,
} from '@/Utils/seanceEnAttente'
import { ouvrirLaSeanceEnAttente } from '@/composables/useDemarrageDUneSeance'
import { triggerHaptic } from '@/composables/useHaptics'

/** Les annonces de la file après lesquelles la séance en cours se relit. */
const ANNONCES_DE_LA_FILE = ['sync:queued', 'sync:replayed', 'sync:failed', 'sync:retired']

/**
 * La séance en cours sur l'appareil, pour le layout (#2049).
 *
 * `auth.user.active_workout` ne connaît que les séances du serveur : une
 * séance démarrée sans réseau, dont la création attend la file, n'y est pas,
 * et le bandeau de la séance en cours ne la montrait pas. La séance en cours
 * est donc celle du serveur ou celle qu'une création en file fait naître
 * (`seanceEnCoursAvecLaFile`), relue au montage et à chaque annonce de la
 * file. Celle-ci s'ouvre côté client (`ouvrir`), depuis l'entrée de sa
 * création, comme « Démarrer » l'ouvre.
 *
 * - **Création rejouée.** Le serveur a créé la séance : la page redemande
 *   `auth`, qui la porte désormais, sauf sur une copie hors ligne (elle se
 *   recharge en entier au retour du réseau) et sur la séance en attente
 *   elle-même, qui passe d'elle-même à la séance réelle
 *   (`useSeanceEnAttente`) : un rechargement mènerait ailleurs, pendant un
 *   repos peut-être.
 * - **Création refusée.** Le refus s'annonce une fois, par le toast d'erreur
 *   du layout, avec le nombre d'écritures de la séance qu'il emporte (les
 *   refus `parentRefuse` qui la nomment) ; au montage aussi, quand il est
 *   arrivé sur un autre écran. Une tâche plus tard : le vidage refuse les
 *   écritures emportées juste après l'annonce de la séance. Le refus reste
 *   parmi les refus, noté comme dit (`noterLesRefusAnnonces`).
 *
 * @param {{ page?: object }} options la page d'Inertia (`usePage()`)
 * @returns {{
 *   seanceEnCours: import('vue').ComputedRef<object|null>,
 *   ouvrir: (seance: object) => Promise<void>,
 * }}
 */
export const useSeanceEnCoursDeLAppareil = ({ page = usePage() } = {}) => {
    const entrees = ref(SyncService.entreesDuCompte())
    const seanceEnCours = computed(() =>
        seanceEnCoursAvecLaFile(page.props?.auth?.user?.active_workout ?? null, entrees.value),
    )
    let annonceDuRefus = null

    const relire = () => {
        entrees.value = SyncService.entreesDuCompte()
    }

    /** Le refus d'une séance démarrée sans réseau, pas encore dit : le plus récent, une fois. */
    const annoncerLeRefus = () => {
        annonceDuRefus = null

        const refus = SyncService.failedRequests()
        const aDire = refus.filter(
            (refusee) => estUneCreationDeSeance(refusee) && refusee.parentRefuse !== true && refusee.annonce !== true,
        )

        if (aDire.length === 0) {
            return
        }

        const dernier = aDire.at(-1)
        const flash = page.props.flash ?? (page.props.flash = {})

        flash.error = messageDuRefusDeLaSeance(dernier.raison, emporteesParLeRefus(refus, dernier.id))
        triggerHaptic('error')
        SyncService.noterLesRefusAnnonces(aDire.map((refusee) => refusee.id))
    }

    const planifierLAnnonce = () => {
        annonceDuRefus ??= setTimeout(annoncerLeRefus, 0)
    }

    const rechargerLeCompte = () => {
        if (estUneCopie(page) || cleDeLAdresseEnAttente(page.url) !== null) {
            return
        }

        router.reload({ only: ['auth'] })
    }

    const surUneAnnonce = (event) => {
        const detail = event.detail ?? {}

        relire()

        if (event.type === 'sync:replayed' && estUneCreationDeSeance({ method: 'post', url: detail.url })) {
            rechargerLeCompte()
        }

        if (event.type === 'sync:failed' && detail.parentRefuse !== true && estUneCreationDeSeance(detail)) {
            planifierLAnnonce()
        }
    }

    /**
     * Ouvre la séance en cours que le bandeau montre, quand elle attend la
     * file : depuis l'entrée de sa création, et sinon par l'adresse de sa clé,
     * que le serveur résout.
     *
     * @param {object} seance
     */
    const ouvrir = async (seance) => {
        const entree = SyncService.entreeStockee(seance?.enAttenteDe)

        if (entree !== undefined) {
            await ouvrirLaSeanceEnAttente({ entree, page })

            return
        }

        const adresse = adresseDUneSeanceEnAttente(seance?.idempotency_key)

        if (adresse !== null) {
            router.visit(adresse)
        }
    }

    if (getCurrentInstance()) {
        onMounted(() => {
            relire()
            ANNONCES_DE_LA_FILE.forEach((annonce) => window.addEventListener(annonce, surUneAnnonce))
            planifierLAnnonce()
        })

        onUnmounted(() => {
            clearTimeout(annonceDuRefus)
            annonceDuRefus = null
            ANNONCES_DE_LA_FILE.forEach((annonce) => window.removeEventListener(annonce, surUneAnnonce))
        })
    }

    return { seanceEnCours, ouvrir }
}
