import { reactive } from 'vue'
import { adresseDUneSeanceEnAttente } from '@/Utils/seanceEnAttente'

/**
 * Le repos d'une séance que la page de séance a laissé derrière elle (#2434).
 *
 * Le minuteur appartient à la page : il se démonte avec elle, et son compte à
 * rebours en mémoire de page disparaîtrait à la première navigation. Ce que la
 * page dépose en partant est donc une ÉCHÉANCE (un horodatage), jamais un reste
 * de secondes : le temps qui passe ailleurs dans l'application se lit sur
 * l'horloge, et la page retrouve le repos là où il en est (`reprendreLeRepos`).
 *
 * Le dépôt vit en mémoire, par onglet : il survit à une navigation, pas à un
 * rechargement, et ne touche ni le `localStorage` ni la déconnexion. Il porte
 * l'identifiant du compte : un autre compte, sur le même onglet, ne le voit pas.
 *
 * Rien ici ne sonne : la sortie audio est celle de la page (`useSortieAudio`),
 * fermée avec elle. Un repos qui finit ailleurs se dit à l'écran, et par le
 * retour haptique.
 */

/**
 * @typedef {object} ReposDepose
 * @property {number} duree la durée du repos, en secondes
 * @property {number|null} echeance l'instant où il finit (ms), null en pause
 * @property {number|null} restantEnPause ce qu'il reste en pause, en secondes
 * @property {string} adresse l'adresse de la séance, où le repos se reprend
 * @property {number|string|null} [seance] l'identifiant que le serveur a donné à la séance, ou null sans lui
 * @property {boolean} [annonce] « Repos terminé » a déjà été dit : un bandeau remonté ne le redit pas
 * @property {string} intitule le nom de la séance
 * @property {number|string|null} compte le compte à qui le repos appartient
 */

/** @type {{ repos: ReposDepose|null }} */
const memoire = reactive({ repos: null })

/** Combien de temps « Repos terminé » reste dit avant que le dépôt s'efface, comme sur la page. */
export const DELAI_DE_L_ANNONCE_MS = 4000

/**
 * Dépose le repos en cours.
 *
 * @param {ReposDepose} repos
 */
export const deposerLeRepos = (repos) => {
    memoire.repos = { ...repos }
}

/** Efface le dépôt : le repos est fini, fermé, ou repris par la page. */
export const effacerLeRepos = () => {
    memoire.repos = null
}

/**
 * Ce que le dépôt tient pour ce compte, sans le retirer.
 *
 * @param {number|string|null|undefined} compte
 * @returns {ReposDepose|null}
 */
export const reposDuCompte = (compte) => {
    const repos = memoire.repos

    return repos !== null && String(repos.compte) === String(compte ?? null) ? repos : null
}

/**
 * Ce dépôt est-il celui de cette séance ? Son identifiant, ou, pour une séance
 * démarrée sans réseau, l'adresse de sa clé (`useSeanceEnAttente`).
 *
 * @param {ReposDepose} repos
 * @param {{ id?: number|string|null, idempotency_key?: string|null }|null|undefined} seance
 * @returns {boolean}
 */
export const estLeReposDeLaSeance = (repos, seance) => {
    if (!seance) {
        return false
    }

    if (repos.seance != null && seance.id != null && String(repos.seance) === String(seance.id)) {
        return true
    }

    const adresseEnAttente = adresseDUneSeanceEnAttente(seance.idempotency_key)

    return adresseEnAttente !== null && repos.adresse === adresseEnAttente
}

/**
 * Le repos que le bandeau de CETTE séance en cours peut montrer : celui du
 * compte, déposé par cette séance, et que le bandeau n'a pas déjà annoncé fini.
 * Un dépôt d'une autre séance (supprimée, ou quittée sans clôture avant qu'une
 * nouvelle ne démarre) ne se montre jamais, et ne s'annonce jamais.
 *
 * @param {number|string|null|undefined} compte
 * @param {object|null|undefined} seance la séance en cours que le bandeau montre
 * @returns {ReposDepose|null}
 */
export const reposAMontrer = (compte, seance) => {
    const repos = reposDuCompte(compte)

    if (repos === null || !estLeReposDeLaSeance(repos, seance)) {
        return null
    }

    return repos.annonce === true && secondesRestantes(repos) === 0 ? null : repos
}

/**
 * Efface le dépôt du compte qui n'est pas celui de la séance en cours (`null`
 * sans séance en cours) : il ne reviendrait jamais, et resterait là à
 * attendre qu'une autre séance l'annonce.
 *
 * @param {number|string|null|undefined} compte
 * @param {object|null|undefined} seance
 */
export const oublierLeReposDUneAutreSeance = (compte, seance) => {
    const repos = reposDuCompte(compte)

    if (repos !== null && !estLeReposDeLaSeance(repos, seance)) {
        effacerLeRepos()
    }
}

/**
 * Note que « Repos terminé » a été dit : un bandeau remonté sur une autre page
 * ne le redit pas. Un repos encore en cours n'est pas annoncé : un dépôt neuf,
 * arrivé après l'annonce, ne se note pas.
 */
export const noterLAnnonceDuRepos = () => {
    if (memoire.repos !== null && secondesRestantes(memoire.repos) === 0) {
        memoire.repos.annonce = true
    }
}

/**
 * Les secondes qu'il reste au repos déposé : le reste figé d'une pause, ou
 * l'écart à l'échéance, arrondi au-dessus comme le minuteur de la page. Zéro
 * une fois l'échéance passée.
 *
 * @param {ReposDepose} repos
 * @param {number} [maintenant]
 * @returns {number}
 */
export const secondesRestantes = (repos, maintenant = Date.now()) => {
    if (repos.echeance === null) {
        return Math.max(0, repos.restantEnPause ?? 0)
    }

    return Math.max(0, Math.ceil((repos.echeance - maintenant) / 1000))
}

/**
 * Reprend le repos que cette séance a laissé : le dépôt, rendu et effacé, s'il
 * est celui de ce compte, de cette adresse, et pas fini.
 *
 * @param {{ compte: number|string|null|undefined, adresse: string }} options
 * @returns {{ duree: number, restant: number, enPause: boolean, echeance: number|null }|null} `echeance`
 *   est celle du dépôt, rendue telle quelle : la page la reprend sans l'arrondir, pour qu'un aller-retour n'ajoute rien
 */
export const reprendreLeRepos = ({ compte, adresse }) => {
    const repos = reposDuCompte(compte)

    if (repos === null || repos.adresse !== adresse) {
        return null
    }

    const restant = secondesRestantes(repos)

    effacerLeRepos()

    if (restant === 0) {
        return null
    }

    return { duree: repos.duree, restant, enPause: repos.echeance === null, echeance: repos.echeance }
}

/**
 * Le dépôt réactif, pour qu'un composant le lise : `repos` vaut null sans repos.
 */
export const reposDeposes = memoire
