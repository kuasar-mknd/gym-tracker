import { router } from '@inertiajs/vue3'
import { detacherLAppareil } from '@/composables/useAbonnementPush'
import { effacerLesBrouillons } from '@/composables/useBrouillonsDeSeries'
import SyncService from '@/Utils/SyncService'
import { noterLesEcrituresGardees } from '@/Utils/ecrituresGardees'

/** La déconnexion en cours, pour qu'un double clic ne la fasse pas deux fois. */
let deconnexionEnCours = null

/**
 * Ce que le compte qui part laisse sur l'appareil (#1964).
 *
 * Ses écritures encore en file restent à lui : la file ne les envoie que sous
 * sa propre session, à sa prochaine connexion, et l'écran qui suit la
 * déconnexion dit combien il en reste. Ses refus et ses brouillons de séries,
 * eux, n'ont plus de lecteur et sont effacés. Rien de tout cela ne retient la
 * déconnexion : chacune de ces écritures laisse les choses en l'état quand le
 * stockage refuse.
 */
const laisserLAppareil = () => {
    noterLesEcrituresGardees(SyncService.enAttente())
    SyncService.clearFailedRequests()
    effacerLesBrouillons()
}

/**
 * La seule façon de se déconnecter depuis l'application (#1926).
 *
 * Le menu du layout, le profil et la page de vérification de l'adresse passent
 * tous par ici : l'appareil est d'abord détaché du compte, pour que ses
 * notifications cessent d'arriver ici, puis la déconnexion part en POST. Le
 * détachement ne la retient jamais plus de `DELAI_DE_DETACHEMENT_MS`, et ne
 * l'empêche jamais. Un lien Inertia vers `logout` ne saurait pas attendre le
 * détachement : une garde de tests/js/conventions/deconnexion.test.js refuse
 * qu'on en écrive un.
 *
 * @returns {Promise<void>} Réglée quand la requête de déconnexion est envoyée.
 */
export const seDeconnecter = () => {
    // Une déconnexion qui échoue, à l'envoi ou en route (réseau coupé), doit
    // pouvoir être relancée : sinon chaque clic suivant rendrait le même échec.
    const liberer = () => {
        deconnexionEnCours = null
    }

    deconnexionEnCours ??= detacherLAppareil()
        .then(() => {
            laisserLAppareil()
            router.post(route('logout'), {}, { onFinish: liberer })
        })
        .catch((erreur) => {
            liberer()

            throw erreur
        })

    return deconnexionEnCours
}
