import { router } from '@inertiajs/vue3'
import { detacherLAppareil } from '@/composables/useAbonnementPush'

/** La déconnexion en cours, pour qu'un double clic ne la fasse pas deux fois. */
let deconnexionEnCours = null

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
            router.post(route('logout'), {}, { onFinish: liberer })
        })
        .catch((erreur) => {
            liberer()

            throw erreur
        })

    return deconnexionEnCours
}
