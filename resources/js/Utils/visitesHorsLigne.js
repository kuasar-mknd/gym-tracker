import { readonly, ref } from 'vue'
import { magasinDesCopies } from '@/Utils/copiesHorsLigne'
import {
    RAISON_RESEAU_LENT,
    RAISON_SANS_RESEAU,
    adressePeutAvoirUneCopie,
    estUneCopie,
    pageServie,
    propAbsente,
    propsDiffereesAnnoncees,
} from '@/Utils/copiesServies'
import { visiteQuiQuitteLaPage } from '@/Utils/miseAJourDuWorker'
import { COMPOSANT_DE_LA_SEANCE, cleDeLaCopie, pageGardee } from '@/Utils/pagesGardees'
import {
    noterQueLeServeurNeRepondPas,
    noterQueLeServeurRepond,
    surUneReponseDuServeur,
    useEtatDuReseau,
} from '@/composables/useEtatDuReseau'

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
 *   la requête a pu arriver, et seule sa réponse se perdre. Une suppression
 *   (DELETE) dit que la suppression n'est pas confirmée, les autres méthodes
 *   que l'enregistrement ne l'est pas ;
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
 *
 * **Les copies hors ligne** (#2048, partie E, décisions 8 et 9 du
 * propriétaire du 2026-10-12). Une visite qui quitte la page vers une page de
 * la liste blanche (`pagesGardees.js`) reçoit sa copie, quand le magasin
 * chiffré en a une lisible pour le compte (`copiesHorsLigne.js` vérifie la
 * clé du témoin, le titulaire de l'appareil et le contrat), dans deux cas :
 * sans réponse (`networkError`), et au bout de cinq secondes sans réponse,
 * la visite annulée alors (`router.cancelAll({ async: false, prefetch: false
 * })`). Une réponse arrivée avant, dont la page se pose encore (son morceau,
 * l'historique chiffré), passe devant la copie : Inertia émet `beforeUpdate`
 * avant de la poser, et `finish` seulement après. Le réseau d'abord,
 * toujours : un rechargement partiel, une prop
 * différée, un préchargement ou une écriture ne reçoivent jamais de copie.
 * Le morceau du composant s'importe d'abord : s'il manque (une mise à jour
 * pas encore précachée), « Mise à jour à terminer avec le réseau », et la
 * page reste. La copie se pousse ensuite par `router.push`, une visite côté
 * client, champ par champ (`copiesServies.js`) : elle n'émet ni `success`
 * ni `beforeUpdate`, et ni la file hors ligne ni la garde de l'historique ne
 * la prennent pour une réponse du serveur. La séance ouverte fige d'abord ce
 * qu'elle garde dans l'historique (`EVENEMENT_AVANT_UNE_COPIE`), qu'un
 * `router.remember` n'écrive pas dans l'entrée de la copie. Une copie montrée
 * rend `joignable` faux : la sonde demande au serveur s'il répond.
 *
 * **Les props différées en échec.** Une page du réseau demande ses props
 * différées juste après son affichage, un groupe par requête. Sans réseau,
 * chaque groupe échoue, et `<Deferred>` montrait son squelette sans fin. Le
 * groupe en échec passe donc dans `rescuedProps` par `router.replace`, avec
 * `deferredProps: undefined` (sans quoi `page.set` relancerait leur
 * chargement, et chaque échec une nouvelle requête), `initialDeferredProps`
 * et le `flash` gardés : `<Deferred>` montre son créneau `#rescue`,
 * « Indisponible hors ligne » (`IndisponibleHorsLigne.vue`). Une requête par
 * groupe, pas une de plus. Au retour du réseau, à la première réponse
 * réussie du serveur (`surUneReponseDuServeur`), la page les redemande une
 * fois (`router.reload({ only })`), sans un mot si cela échoue.
 *
 * **Le retour du réseau sur une copie.** Une copie montrée se recharge en
 * entier (`router.reload()`, sans `only`) à la première réponse réussie du
 * serveur, visite d'Inertia, `http()`, rejeu de la file ou sonde, et quand
 * l'historique ramène à une copie alors que le serveur répond (`navigate`) :
 * une réponse complète remplace ses props, marque comprise. La séance attend
 * d'abord que la file soit vide et qu'aucune écriture ne vole, comme
 * `useRechargementApresLeVidage` : rechargée plus tôt, elle reprendrait au
 * serveur une séance d'avant les écritures qui partent. Un rechargement qui
 * échoue ne dit rien, et le suivant attend la réponse d'après. « Réessayer »,
 * dans le bandeau d'une copie (`BandeauHorsLigne.vue`), lance le même
 * rechargement à la demande (`reessayerLaCopie`), et lui le dit : sans réseau,
 * la page n'a pas été rafraîchie ; sur la séance, ses écritures attendent
 * encore ; sans réponse au bout de huit secondes, le réseau ne répond pas,
 * avec « Annuler ».
 *
 * **Aucune écriture depuis une copie.** Une visite qui n'est pas un GET,
 * partie d'une page marquée, est arrêtée avant son départ (`before`), le
 * dit (`ECRITURE_DEPUIS_UNE_COPIE`) et fait recharger la page : la personne
 * refait son geste sur des valeurs fraîches, et une valeur tirée d'une copie
 * ne repart jamais au serveur. La déconnexion en est exemptée
 * (`exempterDeLaGardeDesCopies`, que `useDeconnexion` appelle) : arrêtée sur
 * une copie montrée pour lenteur, elle laissait la session ouverte à la
 * personne suivante.
 */

/** L'évènement de fenêtre que les toasts des layouts écoutent (`ToastsDuLayout.vue`). */
export const EVENEMENT_SANS_RESEAU = 'gym:sans-reseau'

/** Ce qu'une visite qui quitte la page attend avant de dire que le réseau ne répond pas. */
export const DELAI_DU_RESEAU_LENT_MS = 8000

/**
 * Ce qu'une visite qui quitte la page vers une page gardée attend avant que sa
 * copie soit servie (décision 8 du propriétaire) : plus tôt que le toast de la
 * lenteur, qui ne sert plus qu'aux pages sans copie.
 */
export const DELAI_AVANT_UNE_COPIE_MS = 5000

/**
 * L'évènement de fenêtre émis juste avant qu'une copie soit poussée, avec la
 * page qui arrive (`detail.page`) : une visite côté client n'émet pas
 * `beforeUpdate`, sur lequel la séance fige ce qu'elle garde dans
 * l'historique (`useSeanceEtSaFile`).
 */
export const EVENEMENT_AVANT_UNE_COPIE = 'gym:avant-une-copie'

/**
 * Ce que dit une visite sans réseau dont la copie existe, mais pas le morceau
 * de son composant : une mise à jour de l'application l'a remplacé, et le
 * nouveau ne s'obtient qu'avec le réseau.
 */
export const MISE_A_JOUR_A_TERMINER = 'Mise à jour à terminer avec le réseau.'

/**
 * Ce que dit une écriture tentée depuis une copie hors ligne : arrêtée avant
 * son départ, elle n'a rien envoyé, et la page se recharge dès que le serveur
 * répond.
 */
export const ECRITURE_DEPUIS_UNE_COPIE =
    "Cette page est une copie hors ligne : rien n'a été envoyé. Elle se recharge dès que le serveur répond ; refais ton geste ensuite."

/** Les visites que la garde des écritures laisse partir d'une copie : la déconnexion. */
const visitesExemptees = new WeakSet()

/**
 * Laisse partir cette visite d'une copie hors ligne : le `onBefore` de la
 * déconnexion (`useDeconnexion`), qu'Inertia appelle avec la visite avant
 * l'évènement `before`.
 *
 * @param {object} visite
 */
export const exempterDeLaGardeDesCopies = (visite) => {
    if (visite !== null && typeof visite === 'object') {
        visitesExemptees.add(visite)
    }
}

export const PAGE_INDISPONIBLE = "Cette page n'est pas disponible sans réseau."
export const ECRITURE_NON_CONFIRMEE = "Pas de réponse du serveur : l'enregistrement n'est pas confirmé."

/**
 * Une suppression (DELETE) sans réponse, où « l'enregistrement » ne disait pas
 * le geste : l'élément a pu être effacé, ou non (décision du propriétaire,
 * 2026-10-15).
 */
export const SUPPRESSION_NON_CONFIRMEE = "Pas de réponse du serveur : la suppression n'est pas confirmée."
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

/**
 * Ce que dit « Réessayer » sur la copie d'une séance dont des écritures
 * attendent encore le serveur : la séance ne se recharge qu'une fois sa file
 * vide, sans quoi elle reprendrait au serveur une séance d'avant elles.
 */
export const SEANCE_APRES_SES_ECRITURES = 'La séance se recharge dès que ses écritures sont enregistrées.'

/** Si la copie affichée se recharge, ou attend de le faire : « Réessayer » le montre. */
const copieEnRechargement = ref(false)

/** Si la copie affichée se recharge, ou attend de le faire (lecture seule). */
export const rechargementDeLaCopie = readonly(copieEnRechargement)

/** Ce que « Réessayer » appelle : `installerLesVisitesHorsLigne` le branche. */
let reessayer = () => Promise.resolve()

/**
 * « Réessayer », du bandeau d'une copie hors ligne : recharge la page affichée
 * en entier, comme au retour du réseau, et dit ce qui l'en empêche. Sans
 * effet quand la page n'est pas une copie, ou qu'un rechargement est déjà en
 * cours.
 *
 * @returns {Promise<void>}
 */
export const reessayerLaCopie = () => reessayer()

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

    const methode = String(visite.method).toLowerCase()

    if (methode === 'delete') {
        return SUPPRESSION_NON_CONFIRMEE
    }

    if (methode !== 'get') {
        return ECRITURE_NON_CONFIRMEE
    }

    return visite.async ? null : PAGE_INDISPONIBLE
}

/**
 * L'adresse d'une visite telle que sa copie se cherche : chemin et requête,
 * sans origine ni fragment.
 *
 * @param {{ url: URL | string }} visite
 * @param {Window} fenetre
 */
const adresseDeLaVisite = (visite, fenetre) => {
    const url = new URL(String(visite.url), fenetre.location.href)

    return url.origin === fenetre.location.origin ? `${url.pathname}${url.search}` : null
}

/**
 * L'identifiant du compte qu'une page déclare, ou `null`.
 *
 * @param {{ props?: { auth?: { user?: { id?: unknown } | null } } } | null | undefined} page
 */
const compteDe = (page) => {
    const identifiant = page?.props?.auth?.user?.id

    return identifiant === undefined || identifiant === null ? null : String(identifiant)
}

/**
 * Si la séance n'a plus rien qui parte : la file hors ligne vidée, ou
 * arrêtée, puis relue vide, et aucune écriture directe en vol. Ce que la
 * copie de la séance attend avant de se recharger. `SyncService` se charge à
 * la demande : la page de séance, seule à en avoir besoin ici, l'a déjà
 * chargé.
 *
 * @returns {Promise<boolean>}
 */
export const seanceEnregistreeDansLaFile = async () => {
    const { default: file } = await import('@/Utils/SyncService')

    await file.attendreLesEcritures()

    return file.enAttente() === 0 && file.ecrituresEnCours() === 0
}

/**
 * Branche le filet sur le routeur d'Inertia. Une fois par page.
 *
 * @param {{
 *   routeur: {
 *     on: (evenement: string, rappel: (evenement: CustomEvent) => unknown) => unknown,
 *     cancelAll: (options?: { async?: boolean, prefetch?: boolean, sync?: boolean }) => void,
 *     push?: (page: object) => void,
 *     resolveComponent?: (composant: string) => Promise<unknown>,
 *   },
 *   fenetre?: Window,
 *   delaiDuReseauLent?: number,
 *   delaiAvantUneCopie?: number,
 *   lireUneCopie?: (adresse: string) => Promise<{ page: object, copieDu: number } | null>,
 *   importerLeComposant?: (composant: string) => Promise<unknown>,
 *   laSeanceEstEnregistree?: () => Promise<boolean>,
 *   horloge?: () => number,
 * }} options `lireUneCopie` : le magasin de la page par défaut ;
 *   `importerLeComposant` : le résolveur des pages d'Inertia par défaut ;
 *   `laSeanceEstEnregistree` : la file hors ligne vidée, rien en vol
 *   (`seanceEnregistreeDansLaFile`, par défaut).
 */
export const installerLesVisitesHorsLigne = ({
    routeur,
    fenetre = window,
    delaiDuReseauLent = DELAI_DU_RESEAU_LENT_MS,
    delaiAvantUneCopie = DELAI_AVANT_UNE_COPIE_MS,
    lireUneCopie = (adresse) => magasinDesCopies().lireUneCopie(adresse),
    importerLeComposant = (composant) => routeur.resolveComponent(composant),
    laSeanceEstEnregistree = seanceEnregistreeDansLaFile,
    horloge = () => Date.now(),
}) => {
    const { joignable } = useEtatDuReseau()

    /** Les visites parties et pas encore finies, chacune avec ses minuteurs. */
    const enVol = new Map()

    /** Si la visite de chaque identifiant demandait une partie des props : sa réponse ne dit pas tout `auth`. */
    const partielles = new Map()

    /** La page qu'Inertia montre, suivie sur ses évènements. */
    let pageCourante = null

    /**
     * Le `auth` de la dernière réponse complète du serveur, et l'instant où
     * elle est arrivée : une copie plus récente porte le sien.
     *
     * @type {{ auth: unknown, compte: string | null, recuLe: number } | null}
     */
    let dernierAuth = null

    /**
     * Monte à chaque visite au premier plan et à chaque retour dans
     * l'historique : une copie décidée avant un geste plus récent ne passe
     * plus devant lui.
     */
    let gestes = 0

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
        const visites = [...enVol.keys()].filter((visite) => !enVol.get(visite).repondue)
        const adresse = erreur?.url ? adresseSansFragment(erreur.url) : null
        const deLAdresse = visites.filter((visite) => adresseSansFragment(visite.url) === adresse)
        const candidates = deLAdresse.length > 0 ? deLAdresse : visites

        return candidates.find((visite) => !visite.async && !visite.prefetch) ?? deLAdresse[0] ?? null
    }

    const arreterLesMinuteurs = (visite) => {
        const suivi = enVol.get(visite)

        if (!suivi) {
            return
        }

        clearTimeout(suivi.minuteur)
        clearTimeout(suivi.minuteurDeLaCopie)

        if (suivi.lenteurAnnoncee) {
            suivi.lenteurAnnoncee = false
            retirerLAnnonce(RESEAU_LENT, fenetre)
        }
    }

    /**
     * Le `auth` le plus récent du compte de la copie : celui de la dernière
     * réponse complète du serveur, sauf quand la copie est plus récente
     * qu'elle. `null` quand la page affichée n'est pas celle de ce compte :
     * aucune copie ne se sert alors.
     *
     * @param {{ page: { props?: { auth?: unknown } }, copieDu: number }} copie
     */
    const authLePlusRecent = (copie) => {
        const compte = compteDe(copie.page)

        if (compte === null || compteDe(pageCourante) !== compte) {
            return null
        }

        if (dernierAuth !== null && dernierAuth.compte === compte && dernierAuth.recuLe >= copie.copieDu) {
            return dernierAuth.auth
        }

        return copie.page.props.auth
    }

    /**
     * Sert la copie d'une adresse, si le magasin en a une lisible et si
     * `toujoursVoulue()` le permet encore au moment de la pousser.
     *
     * @param {{ adresse: string, raison: string, toujoursVoulue: () => boolean, avantDePousser?: () => void }} demande
     * @returns {Promise<'servie' | 'sans-copie' | 'morceau-manquant' | 'abandonnee'>}
     */
    const servirUneCopie = async ({ adresse, raison, toujoursVoulue, avantDePousser = () => {} }) => {
        const cle = cleDeLaCopie(adresse)

        if (!adressePeutAvoirUneCopie(adresse) || cle === cleDeLaCopie(pageCourante?.url)) {
            return 'sans-copie'
        }

        let copie

        try {
            copie = await lireUneCopie(cle)
        } catch {
            copie = null
        }

        // Le magasin vérifie déjà clé, titulaire, contrat et liste : la page lue doit encore être celle de sa clé.
        if (
            copie?.page === undefined ||
            copie.page === null ||
            cleDeLaCopie(copie.page.url) !== cle ||
            pageGardee(cle, copie.page.component) === null
        ) {
            return 'sans-copie'
        }

        const auth = authLePlusRecent(copie)

        if (auth === null) {
            return 'sans-copie'
        }

        if (!toujoursVoulue()) {
            return 'abandonnee'
        }

        try {
            await importerLeComposant(copie.page.component)
        } catch {
            return 'morceau-manquant'
        }

        if (!toujoursVoulue()) {
            return 'abandonnee'
        }

        const page = pageServie({ copie, pageCourante, auth, raison })

        fenetre.dispatchEvent(new CustomEvent(EVENEMENT_AVANT_UNE_COPIE, { detail: { page } }))
        noterQueLeServeurNeRepondPas()
        avantDePousser()
        routeur.push(page)

        return 'servie'
    }

    /** @param {CustomEvent} evenement */
    const suivreLaPage = (evenement) => {
        pageCourante = evenement.detail?.page ?? pageCourante
    }

    /**
     * Une réponse du serveur posée : la page, et son `auth` quand la visite
     * demandait toutes les props.
     *
     * @param {CustomEvent} evenement
     */
    const suivreUneReponse = (evenement) => {
        suivreLaPage(evenement)

        const page = evenement.detail?.page

        if (partielles.get(evenement.detail?.visitId) === false && page?.props?.auth !== undefined) {
            dernierAuth = { auth: page.props.auth, compte: compteDe(page), recuLe: horloge() }
        }
    }

    /**
     * Les props différées que la page affichée a secourues faute de réseau, à
     * redemander au retour du réseau : celles de cette page seulement, et
     * le nombre de réponses du serveur vues à ce moment (`apres`) : seule une
     * réponse arrivée ensuite dit que le réseau est revenu.
     *
     * @type {{ url: string, component: string, noms: Set<string>, apres: number } | null}
     */
    let secourues = null

    /** Les réponses réussies du serveur vues jusqu'ici (`surUneReponseDuServeur`). */
    let reponsesDuServeur = 0

    /** Un rechargement que la page a lancé seule, au retour du réseau, et qui n'a pas fini. */
    let rechargementEnVol = false

    /** La copie de la séance attend que sa file soit vide avant de se recharger. */
    let attenteDeLaSeance = false

    /**
     * Le rechargement que « Réessayer » a demandé reste sans réponse : le
     * toast de la lenteur, avec « Annuler », qui l'arrête lui seul.
     *
     * @param {{ cancel: () => void } | null} jeton
     */
    const annoncerUnRechargementLent = (jeton) => {
        annoncerSansReseau(RESEAU_LENT, { annuler: () => jeton?.cancel(), fenetre })
    }

    /**
     * Recharge en entier la copie affichée : une réponse complète remplace ses
     * props, marque comprise. La séance attend d'abord que la file soit vide
     * et qu'aucune écriture ne vole ; sinon, rien, et la réponse suivante, ou
     * l'annonce suivante de la file, redemande. Un échec ne dit rien : c'est
     * un rechargement en arrière-plan, sauf quand « Réessayer » le demande
     * (`demande`) : il dit alors ce qui l'arrête, et la lenteur au bout de son
     * délai.
     *
     * @param {{ demande?: boolean }} [options]
     */
    const rechargerLaCopie = async ({ demande = false } = {}) => {
        const page = pageCourante

        if (rechargementEnVol || attenteDeLaSeance || !estUneCopie(page)) {
            return
        }

        if (page.component === COMPOSANT_DE_LA_SEANCE) {
            attenteDeLaSeance = true
            copieEnRechargement.value = true

            const enregistree = await laSeanceEstEnregistree()
                .catch(() => false)
                .finally(() => {
                    attenteDeLaSeance = false
                    copieEnRechargement.value = rechargementEnVol
                })

            const toujoursLaMeme = estUneCopie(pageCourante) && pageCourante.url === page.url

            if (!enregistree && demande && toujoursLaMeme) {
                annoncerSansReseau(joignable.value ? SEANCE_APRES_SES_ECRITURES : RAFRAICHISSEMENT_SANS_RESEAU, {
                    fenetre,
                })
            }

            if (!enregistree || !toujoursLaMeme || rechargementEnVol) {
                return
            }
        }

        let jeton = null
        let lenteur = null
        let lenteurAnnoncee = false

        if (demande) {
            lenteur = setTimeout(() => {
                lenteurAnnoncee = true
                annoncerUnRechargementLent(jeton)
            }, delaiDuReseauLent)
        }

        rechargementEnVol = true
        copieEnRechargement.value = true
        routeur.reload({
            ...(demande
                ? {
                      onCancelToken: (annulation) => {
                          jeton = annulation
                      },
                      onNetworkError: direSansReseau(RAFRAICHISSEMENT_SANS_RESEAU),
                  }
                : {}),
            onFinish: () => {
                clearTimeout(lenteur)

                if (lenteurAnnoncee) {
                    retirerLAnnonce(RESEAU_LENT, fenetre)
                }

                rechargementEnVol = false
                copieEnRechargement.value = false
            },
        })
    }

    reessayer = () => rechargerLaCopie({ demande: true })

    /**
     * Les props secourues, si ce sont celles de la page affichée.
     *
     * @param {object | null} page
     */
    const secouruesDe = (page) =>
        secourues !== null && page !== null && secourues.url === page.url && secourues.component === page.component
            ? secourues
            : null

    /**
     * Une visite de props différées finie sans ses props, après un échec du
     * réseau : ses props passent dans `rescuedProps`, et leur chargement ne
     * se relance pas.
     *
     * @param {{ url: URL | string, only?: string[] }} visite
     */
    const secourirLesPropsDifferees = (visite) => {
        const page = pageCourante

        if (
            page === null ||
            estUneCopie(page) ||
            adresseDeLaVisite(visite, fenetre) !== adresseDeLaVisite(page, fenetre)
        ) {
            return
        }

        const annoncees = new Set(propsDiffereesAnnoncees(page))
        const absentes = (visite.only ?? []).filter((nom) => annoncees.has(nom) && propAbsente(page.props ?? {}, nom))

        if (absentes.length === 0) {
            return
        }

        const dejaSecourues = secouruesDe(page)?.noms ?? new Set()

        secourues = {
            url: page.url,
            component: page.component,
            noms: new Set([...dejaSecourues, ...absentes]),
            apres: reponsesDuServeur,
        }
        routeur.replace({
            preserveScroll: true,
            preserveState: true,
            rescuedProps: [...new Set([...(page.rescuedProps ?? []), ...secourues.noms])],
            deferredProps: undefined,
            flash: (actuel) => actuel,
        })
    }

    /**
     * Ce qui attendait le retour du réseau, à la première réponse réussie
     * arrivée après le secours : les props différées secourues de la page
     * affichée et toujours absentes, redemandées une fois. Un échec ne dit
     * rien : c'est un rechargement en arrière-plan.
     *
     * @param {number} reponse le rang de la réponse qui l'appelle
     */
    const rechargerCeQuiAttend = (reponse) => {
        const page = pageCourante

        if (estUneCopie(page)) {
            rechargerLaCopie()

            return
        }

        const aRedemander = secouruesDe(page)

        if (rechargementEnVol || aRedemander === null || reponse <= aRedemander.apres) {
            return
        }

        const noms = [...aRedemander.noms].filter((nom) => propAbsente(page.props ?? {}, nom))

        if (noms.length === 0) {
            secourues = null

            return
        }

        rechargementEnVol = true
        routeur.reload({
            only: noms,
            onSuccess: () => {
                // Une seule fois : un serveur qui ne sait pas les rendre ne fait pas boucler la page.
                secourues = null
            },
            onFinish: () => {
                rechargementEnVol = false
            },
        })
    }

    surUneReponseDuServeur(() => {
        reponsesDuServeur += 1

        const reponse = reponsesDuServeur

        // Après la réponse, pas pendant : Inertia finit d'abord de la poser.
        setTimeout(() => rechargerCeQuiAttend(reponse), 0)
    })

    routeur.on('start', (evenement) => {
        const visite = evenement.detail.visit
        const suivi = {
            minuteur: null,
            minuteurDeLaCopie: null,
            lenteurAnnoncee: false,
            echecDuReseau: false,
            repondue: false,
        }

        /*
         * Les props différées d'une page ne partent qu'une fois la page posée :
         * une visite au premier plan vers la même adresse, encore en vol, a
         * donc reçu sa réponse. Leur échec n'est pas le sien.
         */
        if (visite.deferredProps === true) {
            const adresse = adresseSansFragment(visite.url)

            for (const [enCours, suiviEnCours] of enVol) {
                if (!enCours.async && !enCours.prefetch && adresseSansFragment(enCours.url) === adresse) {
                    suiviEnCours.repondue = true
                }
            }
        }

        partielles.set(
            visite.id,
            (visite.only ?? []).length > 0 || (visite.except ?? []).length > 0 || (visite.reset ?? []).length > 0,
        )

        if (!visite.async && !visite.prefetch) {
            gestes += 1
        }

        if (visiteQuiQuitteLaPage(visite)) {
            suivi.minuteur = setTimeout(() => {
                suivi.lenteurAnnoncee = true
                annoncerSansReseau(RESEAU_LENT, {
                    // La visite au premier plan seulement : un rechargement en arrière-plan suit son cours.
                    annuler: () => routeur.cancelAll({ async: false }),
                    fenetre,
                })
            }, delaiDuReseauLent)

            const adresse = adresseDeLaVisite(visite, fenetre)

            if (adresse !== null && adressePeutAvoirUneCopie(adresse)) {
                const gesteDeLaVisite = gestes

                suivi.minuteurDeLaCopie = setTimeout(() => {
                    servirUneCopie({
                        adresse,
                        raison: RAISON_RESEAU_LENT,
                        // Une réponse arrivée se pose encore (morceau, historique) : elle passe avant la copie.
                        toujoursVoulue: () => enVol.has(visite) && !suivi.repondue && gestes === gesteDeLaVisite,
                        // La visite au premier plan seulement, comme « Annuler ».
                        avantDePousser: () => routeur.cancelAll({ async: false, prefetch: false }),
                    })
                }, delaiAvantUneCopie)
            }
        }

        enVol.set(visite, suivi)
    })

    routeur.on('finish', (evenement) => {
        const visite = evenement.detail.visit
        const suivi = enVol.get(visite)

        arreterLesMinuteurs(visite)
        enVol.delete(visite)
        partielles.delete(visite?.id)

        if (suivi?.echecDuReseau && visite.deferredProps === true && !visite.cancelled && !visite.interrupted) {
            secourirLesPropsDifferees(visite)
        }
    })

    routeur.on('networkError', (evenement) => {
        const erreur = evenement.detail.error

        if (!estUnEchecDuReseau(erreur)) {
            return undefined
        }

        noterQueLeServeurNeRepondPas()

        // L'erreur ne dit que l'adresse : chaque visite en vol vers elle a pu échouer, sa fin le dira.
        const adresseEnEchec = erreur?.url ? adresseSansFragment(erreur.url) : null

        for (const [enCours, suivi] of enVol) {
            if (!suivi.repondue && adresseSansFragment(enCours.url) === adresseEnEchec) {
                suivi.echecDuReseau = true
            }
        }

        const visite = visiteEnEchec(erreur)

        if (visite !== null) {
            // Le message de l'échec, ou la copie, prend la place de la lenteur.
            const suivi = enVol.get(visite)
            clearTimeout(suivi.minuteur)
            clearTimeout(suivi.minuteurDeLaCopie)
            suivi.lenteurAnnoncee = false
        }

        const adresse = visite === null ? null : adresseDeLaVisite(visite, fenetre)

        if (visite !== null && visiteQuiQuitteLaPage(visite) && adresse !== null && adressePeutAvoirUneCopie(adresse)) {
            const gesteDeLEchec = gestes

            servirUneCopie({
                adresse,
                raison: RAISON_SANS_RESEAU,
                toujoursVoulue: () => gestes === gesteDeLEchec,
            }).then((issue) => {
                if (issue === 'sans-copie') {
                    annoncerSansReseau(PAGE_INDISPONIBLE, { fenetre })
                } else if (issue === 'morceau-manquant') {
                    annoncerSansReseau(MISE_A_JOUR_A_TERMINER, { fenetre })
                }
            })

            return false
        }

        const message = messageDeLEchec(visite)

        if (message !== null) {
            annoncerSansReseau(message, { fenetre })
        }

        return false
    })

    // `success` comme `error` : une page qui porte des erreurs de validation est une réponse du serveur.
    routeur.on('success', (evenement) => {
        suivreUneReponse(evenement)
        noterQueLeServeurRepond()
    })
    routeur.on('error', (evenement) => {
        suivreUneReponse(evenement)
        noterQueLeServeurRepond()
    })

    routeur.on('navigate', (evenement) => {
        suivreLaPage(evenement)

        if (evenement.detail?.type === 'history') {
            gestes += 1
        }

        // Une copie qui revient quand le serveur répond (Retour, Avance, ou une réponse arrivée avant elle).
        if (estUneCopie(pageCourante) && joignable.value) {
            setTimeout(rechargerLaCopie, 0)
        }

        if (evenement.detail?.type === 'initial' && evenement.detail?.page?.props?.auth !== undefined) {
            dernierAuth = {
                auth: evenement.detail.page.props.auth,
                compte: compteDe(evenement.detail.page),
                recuLe: horloge(),
            }
        }
    })
    routeur.on('clientVisit', suivreLaPage)

    /*
     * Une réponse posée : Inertia émet `beforeUpdate` avant d'importer le
     * morceau de sa page et de l'écrire dans l'historique, et `finish` après.
     * La visite au premier plan qui la reçoit est répondue dès maintenant :
     * sa copie pour lenteur ne passe plus. C'est la sienne quand elle va à son
     * adresse, ou quitte la page affichée (une redirection) : une réponse
     * asynchrone ne pose que la page qu'elle rafraîchit.
     */
    routeur.on('beforeUpdate', (evenement) => {
        const arrivee = evenement.detail?.page?.url

        if (arrivee === undefined) {
            return
        }

        const adresse = adresseSansFragment(arrivee)
        const quitteLaPage = pageCourante === null || adresse !== adresseSansFragment(pageCourante.url)

        for (const [enCours, suivi] of enVol) {
            if (!enCours.async && !enCours.prefetch && (quitteLaPage || adresseSansFragment(enCours.url) === adresse)) {
                suivi.repondue = true
                clearTimeout(suivi.minuteurDeLaCopie)
            }
        }
    })

    // Aucune écriture ne part d'une copie, sauf la déconnexion : la page se recharge d'abord.
    routeur.on('before', (evenement) => {
        const visite = evenement.detail.visit

        if (
            String(visite.method).toLowerCase() === 'get' ||
            !estUneCopie(pageCourante) ||
            visitesExemptees.has(visite)
        ) {
            return undefined
        }

        annoncerSansReseau(ECRITURE_DEPUIS_UNE_COPIE, { fenetre })
        setTimeout(rechargerLaCopie, 0)

        return false
    })

    /*
     * La file qui avance sans réponse réussie (un refus, une écriture retirée)
     * peut laisser la séance enregistrée : sa copie attendait ce moment.
     */
    const surLaFile = () => {
        if (joignable.value) {
            setTimeout(rechargerLaCopie, 0)
        }
    }

    fenetre.addEventListener('sync:replayed', surLaFile)
    fenetre.addEventListener('sync:failed', surLaFile)
    fenetre.addEventListener('sync:retired', surLaFile)
    fenetre.addEventListener('offline', noterQueLeServeurNeRepondPas)
}
