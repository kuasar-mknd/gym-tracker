import { visiteQuiQuitteLaPage } from '@/Utils/miseAJourDuWorker'
import { noterQueLeServeurNeRepondPas, noterQueLeServeurRepond } from '@/composables/useEtatDuReseau'

/**
 * Sans réseau, l'application le dit (#2048).
 *
 * Une visite d'Inertia sans réseau échouait sans un mot : rien n'écoutait
 * `networkError`, Inertia rejetait alors sa promesse, que personne n'attend, et
 * le rapporteur d'erreurs essayait en vain d'envoyer ce rejet au serveur.
 * Réseau coupé, l'échec arrive avant les 250 ms qu'attend la barre de
 * progression : un appui sur Accueil, Séances ou la flèche de l'en-tête ne
 * faisait littéralement rien. Sur un réseau qui ne répond plus, la visite
 * attendait sans fin, puisque le client d'Inertia n'a pas de délai.
 *
 * Ce module, branché une fois par `main.js`, écoute `networkError` et rend
 * `false` pour un échec du réseau : Inertia ne rejette plus rien, et le message
 * qui convient passe par le toast d'erreur des layouts, authentifié comme
 * invité (`ToastsDuLayout.vue`, évènement `gym:sans-reseau`) :
 * - une visite en GET que la personne a demandée : la page n'est pas disponible ;
 * - une écriture : sa confirmation manque. Jamais « rien n'a été enregistré » :
 *   la requête a pu arriver, et seule sa réponse se perdre ;
 * - un rechargement en arrière-plan (props différées, rechargement partiel,
 *   préchargement) : rien.
 * Une autre erreur que celle du réseau, levée pendant le traitement de la
 * réponse, n'est pas avalée : Inertia la fait aussi passer par `networkError`,
 * et le rapporteur doit la voir.
 *
 * Une visite qui quitte la page et n'a pas abouti au bout de huit secondes
 * l'annonce : « Le réseau ne répond pas », avec « Annuler », qui l'arrête.
 *
 * Une visite qui veut dire son échec autrement passe `onNetworkError:
 * direSansReseau(message)` : Inertia appelle ce rappel avant l'évènement, et
 * ne l'émet pas quand il rend `false`.
 *
 * L'évènement d'Inertia ne dit pas de quelle visite vient l'échec, seulement
 * l'adresse de la requête : chaque visite est suivie de `start` à `finish`,
 * qu'Inertia émet avec le même objet, et l'échec est rapproché d'une visite en
 * vol par cette adresse.
 */

/** L'évènement de fenêtre que les toasts des layouts écoutent (`ToastsDuLayout.vue`). */
export const EVENEMENT_SANS_RESEAU = 'gym:sans-reseau'

/** Ce qu'une visite qui quitte la page attend avant de dire que le réseau ne répond pas. */
export const DELAI_DU_RESEAU_LENT_MS = 8000

export const PAGE_INDISPONIBLE = "Cette page n'est pas disponible sans réseau."
export const ECRITURE_NON_CONFIRMEE = "Pas de réponse du serveur : l'enregistrement n'est pas confirmé."
export const RESEAU_LENT = 'Le réseau ne répond pas.'

/**
 * Démarrer une séance, à vide ou depuis un modèle, crée la séance sur le
 * serveur : sans lui, rien ne démarre, et ces gestes le disent. Les faire
 * sans réseau demanderait un identifiant tiré par l'appareil, hors du périmètre
 * de #2048.
 */
export const DEMARRAGE_SANS_RESEAU = 'Il faut le réseau pour démarrer une séance.'

/** Ce que dit un rafraîchissement tiré du doigt quand le serveur ne répond pas. */
export const RAFRAICHISSEMENT_SANS_RESEAU = "Pas de réseau : la page n'a pas été rafraîchie."

/*
 * Les formulaires d'invité et la suppression du compte n'enregistrent rien :
 * « l'enregistrement n'est pas confirmé » serait faux pour eux, et chacun dit
 * le sien par `direSansReseau`. Comme pour une écriture, aucun ne dit que rien
 * n'a changé, sauf la connexion : la session qu'elle ouvre n'arrive à
 * l'appareil que par sa réponse, et la refaire ne coûte rien, comme une
 * déconnexion.
 */

/** Se connecter, sans réseau. */
export const CONNEXION_SANS_RESEAU = "Pas de réseau : la connexion n'a pas eu lieu."

/** Créer son compte, sans réponse : le compte a pu être créé. */
export const INSCRIPTION_NON_CONFIRMEE = "Pas de réponse du serveur : l'inscription n'est pas confirmée."

/** Demander un lien, de réinitialisation ou de vérification de l'adresse, sans réponse. */
export const ENVOI_DU_LIEN_NON_CONFIRME = "Pas de réponse du serveur : l'envoi du lien n'est pas confirmé."

/** Confirmer son mot de passe avant un geste protégé, sans réponse. */
export const VERIFICATION_DU_MOT_DE_PASSE_NON_CONFIRMEE =
    "Pas de réponse du serveur : la vérification du mot de passe n'est pas confirmée."

/** Choisir un nouveau mot de passe depuis le lien reçu, sans réponse. */
export const NOUVEAU_MOT_DE_PASSE_NON_CONFIRME =
    "Pas de réponse du serveur : le nouveau mot de passe n'est pas confirmé."

/** Supprimer son compte, sans réponse : la fenêtre de confirmation reste ouverte et le dit. */
export const SUPPRESSION_DU_COMPTE_NON_CONFIRMEE =
    "Pas de réponse du serveur : la suppression du compte n'est pas confirmée."

/**
 * Si l'erreur vient du réseau : aucune réponse du serveur. Le client d'Inertia
 * et `http()` posent tous deux ce code.
 *
 * @param {unknown} erreur
 */
export const estUnEchecDuReseau = (erreur) => erreur?.code === 'ERR_NETWORK'

/**
 * Montre `message` dans le toast d'erreur du layout, authentifié ou invité.
 *
 * @param {string} message
 * @param {{ annuler?: (() => void) | null, fenetre?: Window }} options
 *   `annuler` ajoute au toast un bouton « Annuler » qui l'appelle.
 */
export const annoncerSansReseau = (message, { annuler = null, fenetre = window } = {}) => {
    fenetre.dispatchEvent(new CustomEvent(EVENEMENT_SANS_RESEAU, { detail: { message, annuler } }))
}

/**
 * Retire le toast qui montre `message`, s'il le montre encore.
 *
 * @param {string} message
 * @param {Window} fenetre
 */
export const retirerLAnnonce = (message, fenetre = window) => {
    fenetre.dispatchEvent(new CustomEvent(EVENEMENT_SANS_RESEAU, { detail: { message: null, retire: message } }))
}

/**
 * Le rappel `onNetworkError` d'une visite qui dit elle-même son échec.
 *
 * Il note que le serveur ne répond pas, appelle `apres` (remettre un
 * interrupteur, par exemple), puis annonce `message`. Une erreur qui ne vient
 * pas du réseau passe telle quelle.
 *
 * @param {string|null} message rien n'est annoncé quand il est nul
 * @param {(erreur: unknown) => void} apres
 * @returns {(erreur: unknown) => false|undefined}
 */
export const direSansReseau =
    (message, apres = () => {}) =>
    (erreur) => {
        if (!estUnEchecDuReseau(erreur)) {
            return undefined
        }

        noterQueLeServeurNeRepondPas()
        apres(erreur)

        if (message !== null) {
            annoncerSansReseau(message)
        }

        return false
    }

/**
 * Ce qu'un échec réseau de `visite` dit à la personne, ou `null` pour rien.
 *
 * @param {{ method: string, async?: boolean, prefetch?: boolean } | null} visite
 */
export const messageDeLEchec = (visite) => {
    if (visite === null || visite.prefetch) {
        return null
    }

    if (String(visite.method).toLowerCase() !== 'get') {
        return ECRITURE_NON_CONFIRMEE
    }

    return visite.async ? null : PAGE_INDISPONIBLE
}

/**
 * Branche le filet sur le routeur d'Inertia. Une fois par page.
 *
 * @param {{
 *   routeur: {
 *     on: (evenement: string, rappel: (evenement: CustomEvent) => unknown) => unknown,
 *     cancelAll: (options?: { async?: boolean, prefetch?: boolean, sync?: boolean }) => void,
 *   },
 *   fenetre?: Window,
 *   delaiDuReseauLent?: number,
 * }} options
 */
export const installerLesVisitesHorsLigne = ({
    routeur,
    fenetre = window,
    delaiDuReseauLent = DELAI_DU_RESEAU_LENT_MS,
}) => {
    /** Les visites parties et pas encore finies, chacune avec son minuteur de lenteur. */
    const enVol = new Map()

    /** L'adresse d'une requête telle que le client d'Inertia la demande : sans fragment. */
    const adresseSansFragment = (adresse) => {
        const url = new URL(String(adresse), fenetre.location.href)
        url.hash = ''

        return url.href
    }

    /**
     * La visite en vol d'où vient l'échec : celle de son adresse, et à défaut
     * la visite au premier plan, s'il y en a une.
     */
    const visiteEnEchec = (erreur) => {
        const visites = [...enVol.keys()]
        const adresse = erreur?.url ? adresseSansFragment(erreur.url) : null
        const deLAdresse = visites.filter((visite) => adresseSansFragment(visite.url) === adresse)
        const candidates = deLAdresse.length > 0 ? deLAdresse : visites

        return candidates.find((visite) => !visite.async && !visite.prefetch) ?? deLAdresse[0] ?? null
    }

    const arreterLeMinuteur = (visite) => {
        const suivi = enVol.get(visite)

        if (!suivi) {
            return
        }

        clearTimeout(suivi.minuteur)

        if (suivi.lenteurAnnoncee) {
            suivi.lenteurAnnoncee = false
            retirerLAnnonce(RESEAU_LENT, fenetre)
        }
    }

    routeur.on('start', (evenement) => {
        const visite = evenement.detail.visit
        const suivi = { minuteur: null, lenteurAnnoncee: false }

        if (visiteQuiQuitteLaPage(visite)) {
            suivi.minuteur = setTimeout(() => {
                suivi.lenteurAnnoncee = true
                annoncerSansReseau(RESEAU_LENT, {
                    // La visite au premier plan seulement : un rechargement en arrière-plan suit son cours.
                    annuler: () => routeur.cancelAll({ async: false }),
                    fenetre,
                })
            }, delaiDuReseauLent)
        }

        enVol.set(visite, suivi)
    })

    routeur.on('finish', (evenement) => {
        arreterLeMinuteur(evenement.detail.visit)
        enVol.delete(evenement.detail.visit)
    })

    routeur.on('networkError', (evenement) => {
        const erreur = evenement.detail.error

        if (!estUnEchecDuReseau(erreur)) {
            return undefined
        }

        noterQueLeServeurNeRepondPas()

        const visite = visiteEnEchec(erreur)

        if (visite !== null) {
            // Le message de l'échec prend la place de celui de la lenteur.
            const suivi = enVol.get(visite)
            clearTimeout(suivi.minuteur)
            suivi.lenteurAnnoncee = false
        }

        const message = messageDeLEchec(visite)

        if (message !== null) {
            annoncerSansReseau(message, { fenetre })
        }

        return false
    })

    // `success` comme `error` : une page qui porte des erreurs de validation est une réponse du serveur.
    routeur.on('success', noterQueLeServeurRepond)
    routeur.on('error', noterQueLeServeurRepond)

    fenetre.addEventListener('offline', noterQueLeServeurNeRepondPas)
}
