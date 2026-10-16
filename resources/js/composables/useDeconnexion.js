import { ref } from 'vue'
import { router } from '@inertiajs/vue3'
import { detacherLAppareil } from '@/composables/useAbonnementPush'
import { effacerLesBrouillons } from '@/composables/useBrouillonsDeSeries'
import SyncService from '@/Utils/SyncService'
import { noterLesEcrituresEffaceesALaDeconnexion } from '@/Utils/ecrituresEffaceesALaDeconnexion'
import { effacerLaRecherche } from '@/Utils/rechercheDExercice'
import { direSansReseau } from '@/Utils/visitesHorsLigne'

/**
 * Ce que dit une déconnexion restée sans réponse (#2048). La réponse a pu se
 * perdre seule, mais le plus prudent est de se croire encore connecté : la
 * refaire ne coûte rien.
 */
export const DECONNEXION_SANS_RESEAU = "Pas de réseau : la déconnexion n'a pas eu lieu."

/**
 * La question posée avant une déconnexion qui effacerait des écritures pas
 * encore envoyées (#2050) : `{ nombre }` tant qu'elle attend sa réponse, null
 * sinon. `Components/UI/QuestionDeDeconnexion.vue`, que les deux layouts
 * montent, la pose à l'écran.
 *
 * @type {import('vue').Ref<{nombre: number}|null>}
 */
export const questionDeDeconnexion = ref(null)

/** La déconnexion en cours, pour qu'un double clic ne la fasse pas deux fois. */
let deconnexionEnCours = null

/**
 * Ce que dit la question : combien d'écritures, et qu'elles seront perdues.
 *
 * @param {number} nombre
 * @returns {string}
 */
export const messageDeLaQuestionDeDeconnexion = (nombre) =>
    nombre > 1
        ? `${nombre} modifications faites hors ligne n'ont pas encore été envoyées. Si tu te déconnectes maintenant, elles seront effacées de cet appareil et perdues. Reste connecté pour qu'elles partent dès que le serveur répondra.`
        : "Une modification faite hors ligne n'a pas encore été envoyée. Si tu te déconnectes maintenant, elle sera effacée de cet appareil et perdue. Reste connecté pour qu'elle parte dès que le serveur répondra."

/**
 * Ce que le compte qui part laisse sur l'appareil, effacé une fois la
 * déconnexion acceptée (#1964, #2050).
 *
 * Ses écritures encore en file et ses refus, sur tout l'appareil, autres
 * onglets compris (`SyncService.oublierLeCompte`) : depuis #2050, rien ne
 * reste en clair pour une connexion future, la personne ayant accepté de les
 * perdre. Ses brouillons de séries et sa dernière recherche d'exercice aussi.
 * Pas avant la réponse : une déconnexion partie sans réseau laisse la personne
 * connectée, et elle aurait perdu ses écritures en attente, ses refus pas
 * encore montrés et ses brouillons, seul endroit où survit une valeur dont
 * l'écriture a échoué. Rien de tout cela ne retient la déconnexion : chacune de
 * ces écritures laisse les choses en l'état quand le stockage refuse.
 *
 * @param {string|null} compte le compte qui s'est déconnecté, lu avant l'envoi
 */
const laisserLAppareil = (compte) => {
    SyncService.oublierLeCompte(compte)
    effacerLesBrouillons()
    effacerLaRecherche()
}

/**
 * Détache l'appareil, puis poste la déconnexion. Voir `seDeconnecter`.
 *
 * @returns {Promise<void>}
 */
const partir = () => {
    // Une déconnexion qui échoue, à l'envoi ou en route (réseau coupé), doit
    // pouvoir être relancée : sinon chaque clic suivant rendrait le même échec.
    const liberer = () => {
        deconnexionEnCours = null
    }

    deconnexionEnCours ??= detacherLAppareil()
        .then(() => {
            /*
             * Lu avant l'envoi : la réponse mène à une page sans compte, que le
             * service a déjà prise en compte quand `onSuccess` arrive.
             */
            const compte = SyncService.compte
            let acceptee = false

            /** La visite a démarré : Inertia appellera `onFinish`, qui libère la déconnexion. */
            let partie = false

            // Noté avant l'envoi : l'écran d'arrivée le lit à son montage, avant `onSuccess`.
            noterLesEcrituresEffaceesALaDeconnexion(SyncService.enAttenteSurLAppareil(compte))

            router.post(
                route('logout'),
                {},
                {
                    onStart: () => {
                        partie = true
                    },
                    onSuccess: () => {
                        acceptee = true
                        laisserLAppareil(compte)
                    },
                    // Sans réseau, le dire : la personne croyait être sortie de son compte.
                    onNetworkError: direSansReseau(DECONNEXION_SANS_RESEAU),
                    /*
                     * Refusée, coupée en route ou annulée (Inertia appelle
                     * `onCancel`, puis `onFinish`) : la personne reste connectée,
                     * rien n'est effacé, et l'avis n'a pas lieu d'être.
                     */
                    onFinish: () => {
                        if (!acceptee) {
                            noterLesEcrituresEffaceesALaDeconnexion(0)
                        }

                        liberer()
                    },
                },
            )

            /*
             * Un écouteur `before` qui annule la visite l'arrête avant son
             * départ, et Inertia n'appelle alors aucun rappel, `onFinish`
             * compris : la déconnexion restait « en cours », et chaque clic
             * suivant rendait la même promesse, sans rien envoyer. `onStart`
             * part dans l'appel même de `router.post` quand la visite part.
             */
            if (!partie) {
                noterLesEcrituresEffaceesALaDeconnexion(0)
                liberer()
            }
        })
        .catch((erreur) => {
            liberer()

            throw erreur
        })

    return deconnexionEnCours
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
 * Une déconnexion acceptée efface ce que la file hors ligne garde pour le
 * compte (#2050). Quand des écritures du compte attendent encore, sur cet
 * onglet ou un autre, rien ne part : la question est posée d'abord (combien,
 * et qu'elles seront perdues), et seul « Se déconnecter quand même »
 * (`seDeconnecterQuandMeme`) détache l'appareil et déconnecte. Avant le
 * détachement, et non après : « Rester connecté » laisse l'appareil abonné.
 * File vide, la déconnexion part sans question. Les gestes l'appellent par
 * `@click` : un premier argument, l'évènement du clic, n'y change rien.
 *
 * @returns {Promise<void>} Réglée quand la requête de déconnexion est envoyée,
 *   ou tout de suite quand la question est posée.
 */
export const seDeconnecter = () => {
    if (deconnexionEnCours !== null) {
        return deconnexionEnCours
    }

    const nombre = SyncService.enAttenteSurLAppareil()

    if (nombre > 0) {
        questionDeDeconnexion.value = { nombre }

        return Promise.resolve()
    }

    return partir()
}

/** « Rester connecté » : la question se ferme, rien ne part et rien n'est effacé. */
export const resterConnecte = () => {
    questionDeDeconnexion.value = null
}

/**
 * « Se déconnecter quand même » : la personne accepte de perdre ses écritures
 * en attente. La déconnexion part ; si elle aboutit, elles sont effacées.
 *
 * @returns {Promise<void>}
 */
export const seDeconnecterQuandMeme = () => {
    questionDeDeconnexion.value = null

    return partir()
}
