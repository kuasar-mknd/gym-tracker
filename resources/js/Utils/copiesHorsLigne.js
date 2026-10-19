import { oublierLeDernierPrechauffage } from '@/Utils/dernierPrechauffage'
import { oublierLesEcrituresAcceptees } from '@/Utils/ecrituresAcceptees'
import { CLEF_DU_TITULAIRE_DE_L_APPAREIL } from '@/Utils/historiqueDuCompte'
import {
    AGE_MAXIMAL_D_UNE_COPIE_MS,
    COMPOSANT_DE_LA_SEANCE,
    PLAFOND_DES_COPIES_EN_OCTETS,
    SEANCES_CLOSES_GARDEES,
    VERSION_DU_CONTRAT,
    cleDeLaCopie,
    cleDeLaSeanceEnCours,
    pageGardee,
    propJamaisGardee,
    propsGardables,
} from '@/Utils/pagesGardees'

/**
 * Le magasin des copies hors ligne (#2048, décisions du propriétaire du
 * 2026-10-12) : des copies chiffrées des objets de page d'Inertia des pages
 * gardées pour la salle (`pagesGardees.js`), d'un seul compte, que seule la
 * page écrit et lit. Le worker n'y touche jamais.
 *
 * **Le chiffrement.** AES-GCM 256, par la clé que porte le témoin
 * `__Host-gt_copies` (`gt_copies` hors HTTPS), que le serveur pose et efface
 * avec la session (`CleDesCopiesHorsLigne`, `.ai/rules/middleware.md`). Elle
 * est importée du cookie à chaque usage, non extractible, et n'est écrite
 * nulle part ailleurs. Chaque enregistrement a son vecteur d'initialisation,
 * 96 bits tirés au hasard, et ses données authentifiées sont
 * `compte|chemin|contrat` : une copie déplacée sous un autre chemin, rangée
 * pour un autre compte ou relue sous un autre contrat ne se déchiffre pas.
 * Tout ce qui vient de la page est chiffré, props, `auth` et table de Ziggy
 * comprises ; restent en clair le chemin, le composant, deux dates et
 * l'empreinte de la clé (SHA-256 tronqué), qui ne permet pas de la
 * retrouver. Quand la session finit, le cookie part, et les copies restent
 * illisibles sans qu'aucun code ait besoin de tourner.
 *
 * **Ce qui n'y entre jamais**, même chiffré : les props d'une page gardée que
 * nomme `PROPS_JAMAIS_GARDEES` (le dernier poids et les objectifs de
 * l'accueil), retirées avant le chiffrement de toute écriture ; ni un message
 * d'un instant, que la page ne montre qu'après une écriture : les champs que
 * nomme `CHAMPS_D_UN_INSTANT` (`flash.success`, `flash.error`) sont ramenés à
 * nul, et le `flash` de la page vidé.
 *
 * **L'état** (`etat`) dit à quelle clé le magasin est ouvert : le compte,
 * l'empreinte de la clé, la version du contrat, `ouvert`, les empreintes des
 * vingt dernières clés fermées, et `dernierRejeu`, l'instant du dernier rejeu
 * d'une écriture de séance par la file hors ligne (`noterUnRejeu`) : une copie
 * de séance écrite avant lui ne l'a pas, et le préchauffage la redemande.
 *
 * **La purge est une barrière.** `oublierLesCopies` vide les copies et ferme
 * l'état en une seule transaction, l'empreinte de la clé courante ajoutée aux
 * fermées. Avant toute attente, elle incrémente le compteur de génération de
 * l'onglet et s'annonce aux autres onglets (`BroadcastChannel`, sinon
 * l'évènement `storage`), qui incrémentent le leur. Une écriture n'aboutit
 * que dans un état ouvert pour SA clé, et seule une réponse complète d'un
 * compte l'ouvre ; dans sa transaction même, elle relit le cookie, le
 * titulaire de l'appareil (`gym-tracker:titulaire-de-l-appareil`) et la
 * génération notée à son départ, sans rien attendre d'autre qu'IndexedDB :
 * une transaction se referme dès que la main revient à la boucle, et le
 * chiffrement se fait donc avant. Une clé fermée ne fait plus rien écrire ni
 * lire, même reposée par la réponse en vol d'un autre onglet.
 *
 * Elle efface aussi, avant toute attente, la note des écritures de séance
 * acceptées (`Utils/ecrituresAcceptees.js`) et celle du dernier préchauffage
 * (`Utils/dernierPrechauffage.js`) : une purge qui laisse la clé ouverte ne
 * fait pas attendre une demi-heure les copies que le préchauffage refait.
 * Une clé fermée par prudence avant un envoi que le serveur refuse ensuite,
 * la session vivante (la suppression du compte, mot de passe refusé), se
 * rouvre par `rouvrirLaCle`.
 *
 * **Les autres purges.** Un magasin ouvert pour un autre compte que le
 * titulaire de l'appareil se vide quand ce titulaire change
 * (`oublierLesCopiesDUnAutreTitulaire`), et l'hygiène du chargement
 * (`entretenir`) efface les copies de plus de sept jours et un magasin d'un
 * autre contrat. Une copie de séance qui la montre encore en cours se retire
 * quand ce n'est pas la séance que le compte a en cours
 * (`retirerLesSeancesQuiNeSontPlusEnCours`).
 *
 * **Le volume** est borné (`PLAFOND_DES_COPIES_EN_OCTETS`) : la copie la plus
 * anciennement écrite sort la première, jamais la séance en cours ; trois
 * séances closes au plus restent à côté d'elle ; une copie de plus de sept
 * jours s'efface.
 *
 * Sans `crypto.subtle` (un contexte qui n'est pas sûr) ou sans IndexedDB
 * (jsdom, une navigation privée qui le refuse), aucune copie, et aucune
 * erreur. Une connexion que le navigateur ferme de lui-même (stockage effacé
 * ou évincé, serveur d'IndexedDB perdu après une suspension, comme on le
 * rapporte d'iOS) se rouvre à la demande suivante (`base`, `transactionSur`) :
 * une PWA reste ouverte des jours, et la purge d'avant la déconnexion doit
 * encore agir. Ce module est le seul à ouvrir IndexedDB, par une fonction injectée
 * (`ouvrirLaBase`), qu'une base en mémoire remplace sous Vitest
 * (`tests/js/utils/baseIndexeeEnMemoire.js`).
 */

/** La base, que ce module seul ouvre. */
export const NOM_DE_LA_BASE = 'gym-tracker-copies'

/** La version du schéma de la base, pas celle du contrat. */
export const VERSION_DE_LA_BASE = 1

/** Les copies, rangées par leur clé (`chemin`). */
export const MAGASIN_DES_PAGES = 'pages'

/** L'état du magasin, un seul enregistrement. */
export const MAGASIN_DE_L_ETAT = 'etat'

/** La clé de l'unique enregistrement du magasin de l'état. */
const CLE_DE_L_ETAT = 'etat'

/** Le témoin quand le cookie de session n'est pas réservé à HTTPS (`CleDesCopiesHorsLigne::TEMOIN`). */
export const TEMOIN = 'gt_copies'

/** Le témoin quand le cookie de session est réservé à HTTPS (`CleDesCopiesHorsLigne::TEMOIN_EN_HTTPS`). */
export const TEMOIN_EN_HTTPS = '__Host-gt_copies'

/** Le canal par lequel une purge s'annonce aux autres onglets. */
export const CANAL_DES_COPIES = 'gym-tracker:copies-hors-ligne'

/** La clé du `localStorage`, écrite puis retirée, qui annonce une purge sans `BroadcastChannel`. */
export const CLEF_DE_L_ANNONCE = 'gym-tracker:copies-oubliees'

/** Les empreintes de clés fermées que l'état retient. */
export const EMPREINTES_FERMEES_GARDEES = 20

/** Le type du message qui annonce une purge. */
const ANNONCE_D_UNE_PURGE = 'copies-oubliees'

/** Une clé AES de 256 bits. */
const OCTETS_DE_LA_CLE = 32

/** Le vecteur d'initialisation recommandé pour AES-GCM : 96 bits. */
const OCTETS_DE_L_IV = 12

/** L'empreinte de la clé : les 128 premiers bits de son SHA-256. */
const OCTETS_DE_L_EMPREINTE = 16

/** Ce qui précède la clé dans le calcul de son empreinte, pour qu'elle ne serve à rien d'autre. */
const ETIQUETTE_DE_L_EMPREINTE = 'gym-tracker:empreinte-de-la-cle-des-copies:'

/** La clé du témoin : 32 octets en base64url sans remplissage. */
const FORME_DU_TEMOIN = /^[A-Za-z0-9_-]{43}$/

/**
 * @typedef {{
 *   chemin: string,
 *   composant: string,
 *   copieDu: number,
 *   ecriteLe: number,
 *   empreinte: string,
 *   iv: Uint8Array,
 *   chiffre: ArrayBuffer,
 * }} EnregistrementDUneCopie
 *   `copieDu` : l'instant de la dernière réponse complète ; `ecriteLe` : celui
 *   de la dernière écriture, réponse partielle comprise.
 */

/**
 * @typedef {{
 *   ouvert: boolean,
 *   compte: string | null,
 *   empreinte: string | null,
 *   contrat: number,
 *   empreintesFermees: string[],
 *   dernierRejeu: number | null,
 * }} EtatDuMagasin
 */

/**
 * @typedef {{
 *   page: object,
 *   ziggy: object | null,
 *   copieDu: number,
 *   ecriteLe: number,
 *   datesDesProps: Record<string, number>,
 * }} CopieLue
 */

/**
 * Le résultat d'une demande à IndexedDB.
 *
 * @param {IDBRequest} demande
 * @returns {Promise<any>}
 */
const resultatDe = (demande) =>
    new Promise((resoudre, rejeter) => {
        demande.onsuccess = () => resoudre(demande.result)
        demande.onerror = () => rejeter(demande.error)
    })

/**
 * La fin d'une transaction : résolue quand elle est validée, rejetée quand
 * elle est annulée ou échoue.
 *
 * @param {IDBTransaction} transaction
 * @returns {Promise<void>}
 */
const finDe = (transaction) =>
    new Promise((resoudre, rejeter) => {
        transaction.oncomplete = () => resoudre()
        transaction.onabort = () => rejeter(transaction.error ?? new Error('transaction annulée'))
        transaction.onerror = () => rejeter(transaction.error ?? new Error('transaction en échec'))
    })

/**
 * Ouvre la base par la fabrique IndexedDB du navigateur, et crée ses magasins
 * à la première ouverture. Rejette sans IndexedDB, ou quand il refuse.
 *
 * @param {IDBFactory | undefined} fabrique
 * @returns {Promise<IDBDatabase>}
 */
export const ouvrirLaBaseDuNavigateur = (fabrique) =>
    new Promise((resoudre, rejeter) => {
        if (!fabrique || typeof fabrique.open !== 'function') {
            rejeter(new Error('IndexedDB absent'))

            return
        }

        let demande

        try {
            demande = fabrique.open(NOM_DE_LA_BASE, VERSION_DE_LA_BASE)
        } catch (erreur) {
            rejeter(erreur)

            return
        }

        demande.onupgradeneeded = () => {
            const base = demande.result

            if (!base.objectStoreNames.contains(MAGASIN_DES_PAGES)) {
                base.createObjectStore(MAGASIN_DES_PAGES, { keyPath: 'chemin' })
            }

            if (!base.objectStoreNames.contains(MAGASIN_DE_L_ETAT)) {
                base.createObjectStore(MAGASIN_DE_L_ETAT)
            }
        }
        demande.onsuccess = () => resoudre(demande.result)
        demande.onerror = () => rejeter(demande.error)
        demande.onblocked = () => rejeter(new Error('IndexedDB bloqué par un autre onglet'))
    })

/**
 * Le texte d'octets en base64url sans remplissage.
 *
 * @param {Uint8Array} octets
 */
const enBase64url = (octets) =>
    btoa(String.fromCharCode(...octets))
        .replace(/\+/g, '-')
        .replace(/\//g, '_')
        .replace(/=+$/, '')

/**
 * Les 32 octets d'une clé écrite en base64url, ou `null` si ce n'en est pas une.
 *
 * @param {string | null} texte
 * @returns {Uint8Array | null}
 */
const octetsDeLaCle = (texte) => {
    if (typeof texte !== 'string' || !FORME_DU_TEMOIN.test(texte)) {
        return null
    }

    const binaire = atob(`${texte.replace(/-/g, '+').replace(/_/g, '/')}=`)

    return binaire.length === OCTETS_DE_LA_CLE ? Uint8Array.from(binaire, (lettre) => lettre.charCodeAt(0)) : null
}

/**
 * L'identifiant du compte qu'une page déclare, en texte, ou `null`.
 *
 * @param {object | null | undefined} page
 * @returns {string | null}
 */
const compteDeLaPage = (page) => {
    const identifiant = page?.props?.auth?.user?.id

    return (Number.isInteger(identifiant) && identifiant > 0) || (typeof identifiant === 'string' && identifiant !== '')
        ? String(identifiant)
        : null
}

/**
 * Le volume d'une copie, chiffrée.
 *
 * @param {EnregistrementDUneCopie} copie
 */
const volumeDe = (copie) => (copie.chiffre?.byteLength ?? 0) + (copie.iv?.byteLength ?? 0)

/**
 * Les copies à retirer après une écriture : celles de plus de sept jours, les
 * séances closes au-delà des trois plus récentes, puis, tant que le plafond
 * est dépassé, la plus anciennement écrite, jamais la séance en cours.
 *
 * @param {EnregistrementDUneCopie[]} copies
 * @param {{ maintenant: number, protegee: string | null }} options
 * @returns {Set<string>} les chemins à retirer
 */
const copiesARetirer = (copies, { maintenant, protegee }) => {
    const retirees = new Set(
        copies.filter((copie) => maintenant - copie.copieDu > AGE_MAXIMAL_D_UNE_COPIE_MS).map((copie) => copie.chemin),
    )
    const restantes = () => copies.filter((copie) => !retirees.has(copie.chemin))
    const plusAnciennesDAbord = (premiere, seconde) =>
        premiere.ecriteLe - seconde.ecriteLe || (premiere.chemin < seconde.chemin ? -1 : 1)

    restantes()
        .filter((copie) => copie.composant === COMPOSANT_DE_LA_SEANCE && copie.chemin !== protegee)
        .sort(plusAnciennesDAbord)
        .reverse()
        .slice(SEANCES_CLOSES_GARDEES)
        .forEach((copie) => retirees.add(copie.chemin))

    let volume = restantes().reduce((total, copie) => total + volumeDe(copie), 0)

    for (const copie of restantes()
        .filter((candidate) => candidate.chemin !== protegee)
        .sort(plusAnciennesDAbord)) {
        if (volume <= PLAFOND_DES_COPIES_EN_OCTETS) {
            break
        }

        retirees.add(copie.chemin)
        volume -= volumeDe(copie)
    }

    return retirees
}

/**
 * Les empreintes fermées, sans doublon, les plus récentes en dernier, vingt au plus.
 *
 * @param {string[]} anciennes
 * @param {(string | null)[]} nouvelles
 * @returns {string[]}
 */
const empreintesFermees = (anciennes, nouvelles) => {
    const ajoutees = nouvelles.filter((empreinte) => typeof empreinte === 'string')
    const gardees = anciennes.filter((empreinte) => !ajoutees.includes(empreinte))

    return [...new Set([...gardees, ...ajoutees])].slice(-EMPREINTES_FERMEES_GARDEES)
}

/**
 * Un magasin des copies, sur la fenêtre et les moyens qu'on lui donne. La
 * page en a un (`magasinDesCopies()`) ; un test en crée autant que d'onglets.
 *
 * @param {{
 *   ouvrirLaBase?: () => Promise<IDBDatabase>,
 *   fenetre?: Window | object,
 *   subtle?: SubtleCrypto,
 *   tirerAuHasard?: (octets: Uint8Array) => Uint8Array,
 *   horloge?: () => number,
 * }} [moyens]
 */
export const creerLeMagasinDesCopies = ({
    ouvrirLaBase = () => ouvrirLaBaseDuNavigateur(globalThis.indexedDB),
    fenetre = globalThis.window,
    subtle = globalThis.crypto?.subtle,
    tirerAuHasard = (octets) => globalThis.crypto.getRandomValues(octets),
    horloge = () => Date.now(),
} = {}) => {
    let generation = 0
    /** @type {Promise<IDBDatabase> | null} */
    let baseOuverte = null
    /** @type {BroadcastChannel | null} */
    let canal = null
    const rappelsDePurge = new Set()
    const encodeur = new TextEncoder()
    const decodeur = new TextDecoder()

    /** Prévient ce qui suit les purges : le préchauffage, les écritures différées. */
    const prevenirDUnePurge = () => {
        for (const rappel of rappelsDePurge) {
            try {
                rappel()
            } catch {
                // Un rappel qui échoue n'empêche pas les autres.
            }
        }
    }

    /** Une purge annoncée par un autre onglet : ce qui était en vol ici ne s'écrit plus. */
    const recevoirUneAnnonce = () => {
        generation += 1
        prevenirDUnePurge()
    }

    /** @param {StorageEvent} evenement */
    const surLeStockage = (evenement) => {
        if (evenement?.key === CLEF_DE_L_ANNONCE && evenement.newValue !== null) {
            recevoirUneAnnonce()
        }
    }

    if (typeof fenetre?.BroadcastChannel === 'function') {
        try {
            canal = new fenetre.BroadcastChannel(CANAL_DES_COPIES)
            canal.onmessage = (message) => {
                if (message?.data?.type === ANNONCE_D_UNE_PURGE) {
                    recevoirUneAnnonce()
                }
            }
        } catch {
            canal = null
        }
    }

    fenetre?.addEventListener?.('storage', surLeStockage)

    /** Annonce une purge aux autres onglets. */
    const annoncer = () => {
        if (canal !== null) {
            try {
                canal.postMessage({ type: ANNONCE_D_UNE_PURGE })

                return
            } catch {
                // Le canal fermé : l'évènement `storage` prend le relais.
            }
        }

        try {
            fenetre.localStorage.setItem(CLEF_DE_L_ANNONCE, `${horloge()}:${Math.random()}`)
            fenetre.localStorage.removeItem(CLEF_DE_L_ANNONCE)
        } catch {
            // Un stockage refusé : les autres onglets verront l'état fermé à leur écriture.
        }
    }

    /** @returns {string | null} la clé que porte le témoin, telle quelle */
    const lireLeTemoin = () => {
        let cookies

        try {
            cookies = String(fenetre?.document?.cookie ?? '')
        } catch {
            return null
        }

        const valeurs = new Map()

        for (const morceau of cookies.split(';')) {
            const egal = morceau.indexOf('=')
            const nom = morceau.slice(0, egal).trim()

            if (egal > 0 && !valeurs.has(nom)) {
                valeurs.set(nom, morceau.slice(egal + 1).trim())
            }
        }

        return valeurs.get(TEMOIN_EN_HTTPS) ?? valeurs.get(TEMOIN) ?? null
    }

    /** @returns {string | null} le titulaire de l'appareil, `compte:<id>` */
    const lireLeTitulaire = () => {
        try {
            return fenetre?.localStorage?.getItem(CLEF_DU_TITULAIRE_DE_L_APPAREIL) ?? null
        } catch {
            return null
        }
    }

    /** @returns {Storage | undefined} le `localStorage` de la fenêtre, ou rien quand il se refuse */
    const stockageDeLAppareil = () => {
        try {
            return fenetre?.localStorage ?? undefined
        } catch {
            return undefined
        }
    }

    /**
     * La connexion à la base, ouverte à la première demande et gardée pour
     * les suivantes. Le navigateur peut la fermer lui-même (`close` : le
     * stockage de l'origine effacé ou évincé, le serveur d'IndexedDB perdu
     * après une suspension) : elle s'oublie alors, et la demande suivante en
     * rouvre une. Seule la promesse de cette connexion s'oublie, jamais celle
     * qui l'a déjà remplacée.
     *
     * @returns {Promise<IDBDatabase>}
     */
    const base = () => {
        if (baseOuverte !== null) {
            return baseOuverte
        }

        const promesse = Promise.resolve()
            .then(ouvrirLaBase)
            .then((ouverte) => {
                ouverte.onversionchange = () => {
                    ouverte.close()
                    oublierLaConnexion(promesse)
                }
                ouverte.onclose = () => oublierLaConnexion(promesse)

                return ouverte
            })
            .catch((erreur) => {
                oublierLaConnexion(promesse)

                throw erreur
            })

        baseOuverte = promesse

        return promesse
    }

    /** @param {Promise<IDBDatabase>} promesse la connexion à oublier, si c'est encore celle du magasin */
    const oublierLaConnexion = (promesse) => {
        if (baseOuverte === promesse) {
            baseOuverte = null
        }
    }

    /**
     * Une transaction sur la base. Une connexion fermée sans que le magasin
     * l'ait su (`InvalidStateError` à la création de la transaction, sans
     * `close` reçu) s'oublie, et la transaction se demande une seconde fois,
     * à une connexion rouverte : sans cela, chaque écriture, chaque oubli et
     * chaque lecture échouaient en silence jusqu'au rechargement de la page,
     * une purge d'avant la déconnexion comprise.
     *
     * @param {string[]} magasins
     * @param {IDBTransactionMode} mode
     * @returns {Promise<IDBTransaction>}
     */
    const transactionSur = async (magasins, mode) => {
        const promesse = base()
        const ouverte = await promesse

        try {
            return ouverte.transaction(magasins, mode)
        } catch (erreur) {
            if (erreur?.name !== 'InvalidStateError') {
                throw erreur
            }

            oublierLaConnexion(promesse)

            return (await base()).transaction(magasins, mode)
        }
    }

    /** @param {Uint8Array} octets */
    const empreinteDe = async (octets) => {
        const etiquette = encodeur.encode(ETIQUETTE_DE_L_EMPREINTE)
        const donnees = new Uint8Array(etiquette.length + octets.length)

        donnees.set(etiquette)
        donnees.set(octets, etiquette.length)

        return enBase64url(new Uint8Array(await subtle.digest('SHA-256', donnees)).slice(0, OCTETS_DE_L_EMPREINTE))
    }

    /** @param {Uint8Array} octets */
    const importer = (octets) => subtle.importKey('raw', octets, { name: 'AES-GCM' }, false, ['encrypt', 'decrypt'])

    /**
     * @param {string} compte
     * @param {string} chemin
     */
    const donneesAuthentifiees = (compte, chemin) => encodeur.encode(`${compte}|${chemin}|${VERSION_DU_CONTRAT}`)

    /**
     * @param {CryptoKey} cle
     * @param {Uint8Array} authentifiees
     * @param {object} contenu
     */
    const chiffrer = async (cle, authentifiees, contenu) => {
        const iv = tirerAuHasard(new Uint8Array(OCTETS_DE_L_IV))
        const chiffre = await subtle.encrypt(
            { name: 'AES-GCM', iv, additionalData: authentifiees },
            cle,
            encodeur.encode(JSON.stringify(contenu)),
        )

        return { iv, chiffre }
    }

    /**
     * @param {CryptoKey} cle
     * @param {Uint8Array} authentifiees
     * @param {EnregistrementDUneCopie} copie
     */
    const dechiffrer = async (cle, authentifiees, copie) =>
        JSON.parse(
            decodeur.decode(
                await subtle.decrypt(
                    { name: 'AES-GCM', iv: copie.iv, additionalData: authentifiees },
                    cle,
                    copie.chiffre,
                ),
            ),
        )

    /**
     * L'état et la copie d'un chemin, lus ensemble.
     *
     * @param {string} chemin
     * @returns {Promise<{ etat: EtatDuMagasin | undefined, copie: EnregistrementDUneCopie | undefined }>}
     */
    const lireLEtatEtLaCopie = async (chemin) => {
        const transaction = await transactionSur([MAGASIN_DES_PAGES, MAGASIN_DE_L_ETAT], 'readonly')
        const [etat, copie] = await Promise.all([
            resultatDe(transaction.objectStore(MAGASIN_DE_L_ETAT).get(CLE_DE_L_ETAT)),
            resultatDe(transaction.objectStore(MAGASIN_DES_PAGES).get(chemin)),
        ])

        return { etat, copie }
    }

    /**
     * Si l'état est ouvert pour cette clé, ce compte et ce contrat.
     *
     * @param {EtatDuMagasin | undefined} etat
     * @param {string} compte
     * @param {string} empreinte
     */
    const ouvertPour = (etat, compte, empreinte) =>
        etat?.ouvert === true &&
        etat.compte === compte &&
        etat.empreinte === empreinte &&
        etat.contrat === VERSION_DU_CONTRAT

    /**
     * Range une copie chiffrée, dans une transaction qui revérifie tout ce dont
     * l'écriture dépend, sans rien attendre d'autre qu'IndexedDB.
     *
     * @param {EnregistrementDUneCopie} copie
     * @param {{
     *   compte: string,
     *   temoin: string,
     *   generationDeDepart: number,
     *   ouvrir: boolean,
     *   ecriteLeAttendue?: number,
     *   pasApres?: number,
     *   protegee: string | null,
     * }} conditions `pasApres` : la copie du magasin, écrite après cet
     *   instant, est plus récente que celle-ci, qui ne la remplace pas
     * @returns {Promise<string>} `ecrite`, ou la raison du refus
     */
    const ranger = async (
        copie,
        { compte, temoin, generationDeDepart, ouvrir, ecriteLeAttendue, pasApres, protegee },
    ) => {
        const transaction = await transactionSur([MAGASIN_DES_PAGES, MAGASIN_DE_L_ETAT], 'readwrite')
        const fin = finDe(transaction)
        const pages = transaction.objectStore(MAGASIN_DES_PAGES)
        const magasinDeLEtat = transaction.objectStore(MAGASIN_DE_L_ETAT)
        const demandeDeLEtat = magasinDeLEtat.get(CLE_DE_L_ETAT)
        const demandeDeLaCopie = pages.get(copie.chemin)
        let issue = 'ecrite'
        let basculee = false

        const refuser = (raison) => {
            issue = raison
            transaction.abort()
        }

        demandeDeLaCopie.onsuccess = () => {
            /** @type {EtatDuMagasin | undefined} */
            const etat = demandeDeLEtat.result

            if (generation !== generationDeDepart) {
                return refuser('perimee')
            }

            if (lireLeTemoin() !== temoin) {
                return refuser('cle-changee')
            }

            if (lireLeTitulaire() !== `compte:${compte}`) {
                return refuser('autre-titulaire')
            }

            if (etat?.empreintesFermees?.includes(copie.empreinte)) {
                return refuser('cle-fermee')
            }

            if (
                pasApres !== undefined &&
                ouvertPour(etat, compte, copie.empreinte) &&
                demandeDeLaCopie.result?.empreinte === copie.empreinte &&
                demandeDeLaCopie.result.ecriteLe > pasApres
            ) {
                return refuser('plus-recente')
            }

            if (!ouvertPour(etat, compte, copie.empreinte)) {
                if (!ouvrir) {
                    return refuser('etat-ferme')
                }

                basculee = etat?.ouvert === true
                pages.clear()
                magasinDeLEtat.put(
                    {
                        ouvert: true,
                        compte,
                        empreinte: copie.empreinte,
                        contrat: VERSION_DU_CONTRAT,
                        empreintesFermees: empreintesFermees(etat?.empreintesFermees ?? [], [
                            basculee && etat.empreinte !== copie.empreinte ? etat.empreinte : null,
                        ]),
                        dernierRejeu: null,
                    },
                    CLE_DE_L_ETAT,
                )
            }

            if (ecriteLeAttendue !== undefined && demandeDeLaCopie.result?.ecriteLe !== ecriteLeAttendue) {
                return refuser('concurrente')
            }

            pages.put(copie)

            const demandeDesCopies = pages.getAll()

            demandeDesCopies.onsuccess = () => {
                for (const chemin of copiesARetirer(demandeDesCopies.result, { maintenant: horloge(), protegee })) {
                    pages.delete(chemin)
                }
            }
        }

        try {
            await fin
        } catch {
            return issue === 'ecrite' ? 'erreur' : issue
        }

        if (basculee) {
            annoncer()
        }

        return issue
    }

    /**
     * Ce qui précède toute écriture : la page gardée, son compte, la clé du
     * témoin, le titulaire et la base. Rien n'est écrit quand l'un manque.
     *
     * @param {object} page
     */
    const preparer = async (page) => {
        if (subtle === undefined || subtle === null) {
            return { refus: 'indisponible' }
        }

        const chemin = cleDeLaCopie(page?.url)

        if (pageGardee(chemin, page?.component) === null) {
            return { refus: 'non-gardee' }
        }

        if (page.encryptHistory !== true) {
            return { refus: 'historique-en-clair' }
        }

        const compte = compteDeLaPage(page)

        if (compte === null) {
            return { refus: 'sans-compte' }
        }

        const temoin = lireLeTemoin()
        const octets = octetsDeLaCle(temoin)

        if (octets === null) {
            return { refus: 'sans-cle' }
        }

        if (lireLeTitulaire() !== `compte:${compte}`) {
            return { refus: 'autre-titulaire' }
        }

        try {
            await base()

            const [empreinte, cle] = await Promise.all([empreinteDe(octets), importer(octets)])

            return { chemin, compte, temoin, empreinte, cle }
        } catch {
            return { refus: 'indisponible' }
        }
    }

    /**
     * Écrit la copie d'une page reçue en entier du réseau, et ouvre l'état à
     * sa clé s'il ne l'était pas : une autre clé, un autre compte ou un autre
     * contrat vident d'abord le magasin, et l'ancienne clé, si elle n'est pas
     * celle-ci, rejoint les fermées. Les props différées que la
     * page n'a pas encore reçues et que la copie précédente avait sont
     * gardées, avec leur date. Les props qu'aucune copie ne garde
     * (`PROPS_JAMAIS_GARDEES`) sont retirées avant le chiffrement.
     *
     * La génération se note à l'appel : une écriture différée passe celle
     * qu'elle a notée quand elle a été décidée. Une page qu'une demande partie
     * à `pasApres` a reçue (le préchauffage) ne remplace pas une copie écrite
     * après cet instant (`plus-recente`), vérifié dans la transaction même :
     * sa réponse, revenue dans le désordre, est plus ancienne qu'elle.
     *
     * @param {object} page l'objet de page d'Inertia
     * @param {{ ziggy?: object | null, generation?: number, pasApres?: number }} [options]
     * @returns {Promise<string>} `ecrite`, ou la raison du refus
     */
    const ecrireUneCopie = async (
        page,
        { ziggy = null, generation: generationDeDepart = generation, pasApres } = {},
    ) => {
        const preparee = await preparer(page)

        if (preparee.refus !== undefined) {
            return preparee.refus
        }

        const { chemin, compte, temoin, empreinte, cle } = preparee

        try {
            const authentifiees = donneesAuthentifiees(compte, chemin)
            const { etat, copie: precedente } = await lireLEtatEtLaCopie(chemin)
            const ancienne =
                precedente !== undefined && ouvertPour(etat, compte, empreinte) && precedente.empreinte === empreinte
                    ? await dechiffrer(cle, authentifiees, precedente).catch(() => null)
                    : null
            const maintenant = horloge()
            const props = propsGardables(page.component, page.props)
            const datesDesProps = Object.fromEntries(Object.keys(props).map((nom) => [nom, maintenant]))

            for (const nom of Object.values(page.deferredProps ?? {}).flat()) {
                if (
                    !(nom in props) &&
                    !propJamaisGardee(page.component, nom) &&
                    ancienne !== null &&
                    Object.hasOwn(ancienne.page?.props ?? {}, nom)
                ) {
                    props[nom] = ancienne.page.props[nom]
                    datesDesProps[nom] = ancienne.datesDesProps?.[nom] ?? precedente.copieDu
                }
            }

            const { iv, chiffre } = await chiffrer(cle, authentifiees, {
                page: { ...page, props, flash: {} },
                ziggy,
                datesDesProps,
            })
            const copie = {
                chemin,
                composant: page.component,
                copieDu: maintenant,
                ecriteLe: maintenant,
                empreinte,
                iv,
                chiffre,
            }

            if (volumeDe(copie) > PLAFOND_DES_COPIES_EN_OCTETS) {
                return 'trop-grande'
            }

            return await ranger(copie, {
                compte,
                temoin,
                generationDeDepart,
                ouvrir: true,
                pasApres,
                protegee: cleDeLaSeanceEnCours(page),
            })
        } catch {
            return 'erreur'
        }
    }

    /**
     * Verse dans la copie existante les props qu'une réponse partielle a
     * reçues (un rechargement partiel, des props différées), chacune avec sa
     * date. Une réponse partielle ne crée jamais de copie, n'ouvre jamais
     * l'état, et ne remplace jamais `auth`, que seule une réponse complète
     * renouvelle. Une prop qu'aucune copie ne garde (`PROPS_JAMAIS_GARDEES`)
     * ne s'y verse pas, et sort de la copie qui la porterait encore. Une
     * réponse qu'une demande partie à `pasApres` a reçue ne remplace pas une
     * prop que la copie date d'après cet instant : celle-ci reste, et sans
     * aucune autre prop à verser, rien ne s'écrit (`plus-recente`).
     *
     * Chaque prop versée se date de l'écriture, ou de `valableAu` quand il
     * est plus ancien : l'instant où ce qu'on verse était vrai. L'instantané
     * de la séance se décide quand tout est enregistré, puis s'écrit au
     * repos ; une écriture acceptée entre les deux n'y est pas, et la séance
     * servie (partie E) ne le dirait pas si l'instantané se datait de son
     * écriture.
     *
     * @param {object} page l'objet de page d'Inertia après la réponse
     * @param {string[]} noms les props que la réponse a reçues
     * @param {{ generation?: number, pasApres?: number, valableAu?: number }} [options]
     * @returns {Promise<string>} `ecrite`, ou la raison du refus
     */
    const completerUneCopie = async (
        page,
        noms,
        { generation: generationDeDepart = generation, pasApres, valableAu } = {},
    ) => {
        const preparee = await preparer(page)

        if (preparee.refus !== undefined) {
            return preparee.refus
        }

        const { chemin, compte, temoin, empreinte, cle } = preparee
        const recues = noms.filter(
            (nom) => nom !== 'auth' && !propJamaisGardee(page.component, nom) && Object.hasOwn(page.props ?? {}, nom),
        )

        if (recues.length === 0) {
            return 'sans-props'
        }

        try {
            const authentifiees = donneesAuthentifiees(compte, chemin)
            const { etat, copie: precedente } = await lireLEtatEtLaCopie(chemin)

            if (
                precedente === undefined ||
                !ouvertPour(etat, compte, empreinte) ||
                precedente.empreinte !== empreinte
            ) {
                return 'sans-copie'
            }

            const ancienne = await dechiffrer(cle, authentifiees, precedente)
            const maintenant = horloge()
            const dateDesVersees = Number.isFinite(valableAu) ? Math.min(valableAu, maintenant) : maintenant

            ancienne.datesDesProps ??= {}
            ancienne.page.props = propsGardables(ancienne.page.component, ancienne.page.props)

            const plusRecente = (nom) =>
                pasApres !== undefined &&
                Object.hasOwn(ancienne.page.props, nom) &&
                (ancienne.datesDesProps[nom] ?? precedente.copieDu) > pasApres
            const versees = recues.filter((nom) => !plusRecente(nom))

            if (versees.length === 0) {
                return 'plus-recente'
            }

            const gardables = propsGardables(page.component, page.props)

            for (const nom of versees) {
                ancienne.page.props[nom] = gardables[nom]
                ancienne.datesDesProps[nom] = dateDesVersees
            }

            ancienne.page.flash = {}

            const { iv, chiffre } = await chiffrer(cle, authentifiees, ancienne)
            const copie = { ...precedente, ecriteLe: maintenant, iv, chiffre }

            if (volumeDe(copie) > PLAFOND_DES_COPIES_EN_OCTETS) {
                return 'trop-grande'
            }

            return await ranger(copie, {
                compte,
                temoin,
                generationDeDepart,
                ouvrir: false,
                ecriteLeAttendue: precedente.ecriteLe,
                protegee: cleDeLaSeanceEnCours(ancienne.page),
            })
        } catch {
            return 'erreur'
        }
    }

    /**
     * Oublie toutes les copies : une barrière, en une seule transaction qui
     * vide les copies et ferme l'état. La génération monte et la purge
     * s'annonce aux autres onglets avant toute attente : ce qui était en vol
     * ne s'écrit plus, ici comme ailleurs.
     *
     * La note des écritures de séance acceptées et celle du dernier
     * préchauffage s'effacent avec elles, aussitôt.
     *
     * L'empreinte de la clé à laquelle le magasin était ouvert rejoint les
     * fermées (`fermerLEtat`). Celle de la clé que porte encore le témoin
     * aussi quand la session finit (`fermerLeTemoin` : la déconnexion, la
     * suppression du compte, un signal de fin de session), même si le magasin
     * ne lui a jamais été ouvert : une réponse en vol qui la reposerait ne
     * rouvrirait rien. Une clé fermée reste fermée tant que l'état la retient.
     * Celle que le témoin portait avant une réponse qui l'a effacé (la
     * suppression du compte acceptée) se ferme par `fermerAussi`, l'empreinte
     * relevée avant l'envoi (`empreinteDuTemoin`).
     *
     * @param {{
     *   fermerLEtat?: boolean,
     *   fermerLeTemoin?: boolean,
     *   fermerAussi?: Promise<string | null> | string | null,
     * }} [options]
     * @returns {Promise<boolean>} vrai quand la transaction a abouti
     */
    const oublierLesCopies = async ({ fermerLEtat = true, fermerLeTemoin = false, fermerAussi = null } = {}) => {
        const releveeAvant = Promise.resolve(fermerAussi).catch(() => null)

        generation += 1
        annoncer()
        prevenirDUnePurge()
        oublierLesEcrituresAcceptees(stockageDeLAppareil())
        oublierLeDernierPrechauffage(stockageDeLAppareil())

        try {
            const octets = fermerLeTemoin && subtle ? octetsDeLaCle(lireLeTemoin()) : null
            const empreinteDuTemoin = octets === null ? null : await empreinteDe(octets)
            const empreinteRelevee = await releveeAvant
            const transaction = await transactionSur([MAGASIN_DES_PAGES, MAGASIN_DE_L_ETAT], 'readwrite')
            const fin = finDe(transaction)
            const magasinDeLEtat = transaction.objectStore(MAGASIN_DE_L_ETAT)
            const demandeDeLEtat = magasinDeLEtat.get(CLE_DE_L_ETAT)

            demandeDeLEtat.onsuccess = () => {
                /** @type {EtatDuMagasin | undefined} */
                const etat = demandeDeLEtat.result

                transaction.objectStore(MAGASIN_DES_PAGES).clear()
                magasinDeLEtat.put(
                    {
                        ouvert: false,
                        compte: null,
                        empreinte: null,
                        contrat: VERSION_DU_CONTRAT,
                        empreintesFermees: empreintesFermees(etat?.empreintesFermees ?? [], [
                            fermerLEtat ? (etat?.empreinte ?? null) : null,
                            empreinteDuTemoin,
                            empreinteRelevee,
                        ]),
                        dernierRejeu: null,
                    },
                    CLE_DE_L_ETAT,
                )
            }

            await fin

            return true
        } catch {
            return false
        }
    }

    /**
     * Rouvre la clé de la session qu'une purge a fermée avant un envoi dont le
     * serveur a refusé l'effet en gardant la session : la suppression du
     * compte, mot de passe refusé. En une seule transaction, l'empreinte sort
     * des fermées, et seulement si tout tient encore : aucune purge depuis
     * celle-là, ici ou annoncée par un autre onglet (`generation`, notée juste
     * après elle), le témoin qui porte encore cette clé, et le titulaire de
     * l'appareil qui est toujours ce compte. L'état reste fermé : la
     * prochaine réponse complète le rouvre, et le préchauffage refait les
     * copies.
     *
     * @param {{
     *   empreinte: Promise<string | null> | string | null,
     *   compte: string | number | null,
     *   generation: number,
     * }} cle l'empreinte relevée avant l'envoi (`empreinteDuTemoin`), le
     *   compte qui l'envoyait, la génération notée après la purge
     * @returns {Promise<boolean>} vrai quand la clé est rouverte
     */
    const rouvrirLaCle = async ({ empreinte: relevee, compte, generation: generationDeDepart }) => {
        const empreinte = await Promise.resolve(relevee).catch(() => null)
        const temoin = lireLeTemoin()
        const octets = subtle ? octetsDeLaCle(temoin) : null

        if (typeof empreinte !== 'string' || compte === null || compte === undefined || octets === null) {
            return false
        }

        try {
            if ((await empreinteDe(octets)) !== empreinte) {
                return false
            }

            const transaction = await transactionSur([MAGASIN_DE_L_ETAT], 'readwrite')
            const fin = finDe(transaction)
            const magasinDeLEtat = transaction.objectStore(MAGASIN_DE_L_ETAT)
            const demandeDeLEtat = magasinDeLEtat.get(CLE_DE_L_ETAT)
            let rouverte = false

            demandeDeLEtat.onsuccess = () => {
                /** @type {EtatDuMagasin | undefined} */
                const etat = demandeDeLEtat.result

                if (
                    generation !== generationDeDepart ||
                    lireLeTemoin() !== temoin ||
                    lireLeTitulaire() !== `compte:${compte}` ||
                    etat === undefined ||
                    !etat.empreintesFermees?.includes(empreinte)
                ) {
                    return
                }

                rouverte = true
                magasinDeLEtat.put(
                    { ...etat, empreintesFermees: etat.empreintesFermees.filter((fermee) => fermee !== empreinte) },
                    CLE_DE_L_ETAT,
                )
            }

            await fin

            return rouverte
        } catch {
            return false
        }
    }

    /**
     * L'empreinte de la clé que porte le témoin à cet instant, ou `null` : le
     * cookie se lit tout de suite, l'empreinte se calcule ensuite. Ce que la
     * suppression du compte relève avant son envoi, pour fermer cette clé une
     * fois la suppression acceptée (`oublierLesCopies({ fermerAussi })`) :
     * sa réponse a alors déjà effacé le témoin. Ne rejette jamais.
     *
     * @returns {Promise<string | null>}
     */
    const empreinteDuTemoin = () => {
        const octets = subtle ? octetsDeLaCle(lireLeTemoin()) : null

        return octets === null ? Promise.resolve(null) : empreinteDe(octets).catch(() => null)
    }

    /**
     * Oublie les copies quand l'appareil a changé de titulaire : un autre
     * compte, ou un invité, a reçu une page dans un onglet
     * (`historiqueDuCompte.js`). Dans une seule transaction, qui relit le
     * titulaire : un magasin ouvert pour un autre compte que lui se vide, et
     * sa clé rejoint les fermées ; un magasin déjà à lui, ou fermé, reste tel
     * quel. Un onglet qui apprend le changement après celui qui l'a vu ne
     * ferme donc pas la clé du compte suivant, que ce dernier vient d'ouvrir.
     *
     * La note des écritures de séance acceptées d'un autre compte que le
     * titulaire s'efface aussi.
     *
     * Sans génération ni annonce : chaque écriture relit le titulaire dans sa
     * transaction, et celles de l'ancien compte, en vol ici ou ailleurs, sont
     * déjà refusées ; une annonce ferait refuser, dans les autres onglets,
     * celles du compte suivant. Ce qui suit les purges ici (le préchauffage)
     * en est prévenu.
     *
     * @returns {Promise<boolean>} vrai quand les copies d'un autre compte ont été oubliées
     */
    const oublierLesCopiesDUnAutreTitulaire = async () => {
        const titulaire = lireLeTitulaire()

        oublierLesEcrituresAcceptees(stockageDeLAppareil(), {
            saufDuCompte: titulaire?.startsWith('compte:') ? titulaire.slice('compte:'.length) : null,
        })

        if (subtle === undefined || subtle === null) {
            return false
        }

        try {
            const transaction = await transactionSur([MAGASIN_DES_PAGES, MAGASIN_DE_L_ETAT], 'readwrite')
            const fin = finDe(transaction)
            const magasinDeLEtat = transaction.objectStore(MAGASIN_DE_L_ETAT)
            const demandeDeLEtat = magasinDeLEtat.get(CLE_DE_L_ETAT)
            let oubliees = false

            demandeDeLEtat.onsuccess = () => {
                /** @type {EtatDuMagasin | undefined} */
                const etat = demandeDeLEtat.result

                if (etat?.ouvert !== true || lireLeTitulaire() === `compte:${etat.compte}`) {
                    return
                }

                oubliees = true
                transaction.objectStore(MAGASIN_DES_PAGES).clear()
                magasinDeLEtat.put(
                    {
                        ouvert: false,
                        compte: null,
                        empreinte: null,
                        contrat: VERSION_DU_CONTRAT,
                        empreintesFermees: empreintesFermees(etat.empreintesFermees ?? [], [etat.empreinte]),
                        dernierRejeu: null,
                    },
                    CLE_DE_L_ETAT,
                )
            }

            await fin

            if (oubliees) {
                prevenirDUnePurge()
            }

            return oubliees
        } catch {
            return false
        }
    }

    /**
     * L'hygiène du magasin, à chaque chargement de la page : une copie
     * gardée il y a plus de sept jours s'efface, et un magasin ouvert sous un
     * autre contrat se vide entier et se ferme, sans fermer sa clé (la
     * session peut être encore vivante : une réponse complète le rouvrira
     * sous ce contrat ; la note du dernier préchauffage s'efface alors,
     * pour qu'il les refasse sans attendre). Une seule transaction.
     *
     * @returns {Promise<number>} le nombre de copies effacées
     */
    const entretenir = async () => {
        if (subtle === undefined || subtle === null) {
            return 0
        }

        try {
            const transaction = await transactionSur([MAGASIN_DES_PAGES, MAGASIN_DE_L_ETAT], 'readwrite')
            const fin = finDe(transaction)
            const pages = transaction.objectStore(MAGASIN_DES_PAGES)
            const magasinDeLEtat = transaction.objectStore(MAGASIN_DE_L_ETAT)
            const demandeDeLEtat = magasinDeLEtat.get(CLE_DE_L_ETAT)
            const demandeDesCopies = pages.getAll()
            let effacees = 0
            let autreContrat = false

            demandeDesCopies.onsuccess = () => {
                /** @type {EtatDuMagasin | undefined} */
                const etat = demandeDeLEtat.result
                /** @type {EnregistrementDUneCopie[]} */
                const copies = demandeDesCopies.result

                if (etat !== undefined && etat.contrat !== VERSION_DU_CONTRAT) {
                    autreContrat = true
                    effacees = copies.length
                    pages.clear()
                    magasinDeLEtat.put(
                        {
                            ouvert: false,
                            compte: null,
                            empreinte: null,
                            contrat: VERSION_DU_CONTRAT,
                            empreintesFermees: empreintesFermees(etat.empreintesFermees ?? [], []),
                            dernierRejeu: null,
                        },
                        CLE_DE_L_ETAT,
                    )

                    return
                }

                const maintenant = horloge()

                for (const copie of copies) {
                    if (maintenant - copie.copieDu > AGE_MAXIMAL_D_UNE_COPIE_MS) {
                        pages.delete(copie.chemin)
                        effacees += 1
                    }
                }
            }

            await fin

            if (autreContrat) {
                oublierLeDernierPrechauffage(stockageDeLAppareil())
            }

            return effacees
        } catch {
            return 0
        }
    }

    /**
     * Déchiffre la copie d'une adresse, ou `null`.
     *
     * Elle ne se lit que si tout tient : un état ouvert sous ce contrat, la
     * clé du témoin présente, non fermée, de l'empreinte de l'état, le
     * titulaire de l'appareil égal au compte de l'état, une copie gardée de
     * moins de sept jours, et un déchiffrement qui authentifie compte, chemin
     * et contrat. Une clé absente, une autre clé, un autre titulaire ou un
     * autre contrat purgent le magasin (la clé absente ou l'autre titulaire
     * ferment la clé de l'état ; une autre clé, celle d'une session plus
     * récente, ou un autre contrat, non) ; une clé fermée, reposée par une
     * réponse en vol, ne fait rien lire.
     *
     * @param {string} adresse l'adresse de la page, sans origine
     * @returns {Promise<CopieLue | null>}
     */
    const lireUneCopie = async (adresse) => {
        const generationDeDepart = generation
        const chemin = cleDeLaCopie(adresse)

        if (subtle === undefined || subtle === null || chemin === null) {
            return null
        }

        try {
            const { etat, copie } = await lireLEtatEtLaCopie(chemin)

            if (etat?.ouvert !== true) {
                return null
            }

            if (etat.contrat !== VERSION_DU_CONTRAT) {
                await oublierLesCopies({ fermerLEtat: false })

                return null
            }

            const temoin = lireLeTemoin()
            const octets = octetsDeLaCle(temoin)

            if (octets === null) {
                await oublierLesCopies()

                return null
            }

            const empreinte = await empreinteDe(octets)

            if (etat.empreintesFermees?.includes(empreinte)) {
                return null
            }

            if (empreinte !== etat.empreinte) {
                await oublierLesCopies({ fermerLEtat: false })

                return null
            }

            if (lireLeTitulaire() !== `compte:${etat.compte}`) {
                await oublierLesCopies()

                return null
            }

            if (
                copie === undefined ||
                copie.empreinte !== etat.empreinte ||
                horloge() - copie.copieDu > AGE_MAXIMAL_D_UNE_COPIE_MS ||
                pageGardee(chemin, copie.composant) === null
            ) {
                return null
            }

            const contenu = await dechiffrer(await importer(octets), donneesAuthentifiees(etat.compte, chemin), copie)

            if (
                generation !== generationDeDepart ||
                lireLeTemoin() !== temoin ||
                contenu?.page?.component !== copie.composant
            ) {
                return null
            }

            return {
                page: contenu.page,
                ziggy: contenu.ziggy ?? null,
                copieDu: copie.copieDu,
                ecriteLe: copie.ecriteLe,
                datesDesProps: contenu.datesDesProps ?? {},
            }
        } catch {
            return null
        }
    }

    /**
     * Ce que la coquille hors ligne lit pour démarrer l'application sans
     * réseau (#2048, partie F, `demarrageHorsLigne.js`) : la copie de
     * l'adresse, la plus récente de toutes (elle porte le `auth` le plus
     * récent du compte, et sa table des routes), et ce qui s'ouvre sans
     * réseau. La coquille n'a pas de page d'où lire le compte : il vient de
     * l'état, et tout ce dont la lecture d'une copie dépend se vérifie avant
     * de rien déchiffrer.
     *
     * Les vérifications, dans l'ordre, chacune purgeant le magasin quand elle
     * échoue, comme `lireUneCopie` : un état ouvert (`ferme` : rien à purger,
     * toute fermeture vide le magasin dans sa transaction), sous ce contrat
     * (`autre-contrat`, la clé gardée) ; la clé du témoin présente
     * (`sans-cle`, la clé de l'état fermée), non fermée (`cle-fermee`), de
     * l'empreinte de l'état (`autre-cle`, la clé de l'état gardée : celle
     * d'une autre session) ; le titulaire de l'appareil égal au compte de
     * l'état (`autre-titulaire`, la clé fermée). Une copie qui ne se
     * déchiffre pas, ou dont la page n'est pas du composant qu'elle nomme en
     * clair (`illisible`), purge aussi, sans fermer la clé : ses données
     * authentifiées nomment le compte de l'état, son chemin et le contrat, et
     * une copie rangée pour un autre compte ou déplacée sous un autre chemin
     * ne se déchiffre pas. Une purge annoncée pendant la
     * lecture, ou un témoin ou un titulaire qui ont changé, ne laissent rien
     * servir (`perimee`), sans nouvelle purge. Sans `crypto.subtle` ou sans
     * IndexedDB : `indisponible`, sans purge.
     *
     * Seules comptent les copies de la clé de l'état, de moins de sept jours,
     * d'une page gardée : la liste blanche se rejuge ici, sur le composant en
     * clair, comme dans `lireUneCopie`. Les autres sont écartées sans refus ni
     * purge : une copie d'une autre clé, plus récente, ferait sinon refuser
     * toute la lecture. Sans aucune, la lecture aboutit sans copie : rien à
     * montrer, et rien à purger.
     *
     * @param {string} adresse l'adresse du document, sans origine
     * @returns {Promise<{ refus: string } | {
     *   compte: string,
     *   copie: CopieLue | null,
     *   plusRecente: CopieLue | null,
     *   copies: Array<{ chemin: string, composant: string, copieDu: number }>,
     * }>}
     */
    const lireAuDemarrage = async (adresse) => {
        const generationDeDepart = generation

        if (subtle === undefined || subtle === null) {
            return { refus: 'indisponible' }
        }

        /**
         * @param {string} raison
         * @param {{ fermerLEtat?: boolean }} [options]
         */
        const refuser = async (raison, options) => {
            await oublierLesCopies(options)

            return { refus: raison }
        }

        let etat
        let enregistrements

        try {
            const transaction = await transactionSur([MAGASIN_DES_PAGES, MAGASIN_DE_L_ETAT], 'readonly')

            ;[etat, enregistrements] = await Promise.all([
                resultatDe(transaction.objectStore(MAGASIN_DE_L_ETAT).get(CLE_DE_L_ETAT)),
                resultatDe(transaction.objectStore(MAGASIN_DES_PAGES).getAll()),
            ])
        } catch {
            return { refus: 'indisponible' }
        }

        if (etat?.ouvert !== true) {
            return { refus: 'ferme' }
        }

        if (etat.contrat !== VERSION_DU_CONTRAT) {
            return refuser('autre-contrat', { fermerLEtat: false })
        }

        const temoin = lireLeTemoin()
        const octets = octetsDeLaCle(temoin)

        if (octets === null) {
            return refuser('sans-cle')
        }

        let empreinte
        let cle

        try {
            ;[empreinte, cle] = await Promise.all([empreinteDe(octets), importer(octets)])
        } catch {
            return { refus: 'indisponible' }
        }

        if (etat.empreintesFermees?.includes(empreinte)) {
            return refuser('cle-fermee', { fermerLEtat: false })
        }

        if (empreinte !== etat.empreinte) {
            return refuser('autre-cle', { fermerLEtat: false })
        }

        if (lireLeTitulaire() !== `compte:${etat.compte}`) {
            return refuser('autre-titulaire')
        }

        const maintenant = horloge()
        /** @type {EnregistrementDUneCopie[]} */
        const valables = (enregistrements ?? []).filter(
            (copie) =>
                copie.empreinte === etat.empreinte &&
                maintenant - copie.copieDu <= AGE_MAXIMAL_D_UNE_COPIE_MS &&
                pageGardee(copie.chemin, copie.composant) !== null,
        )
        const chemin = cleDeLaCopie(adresse)
        const deLAdresse = valables.find((copie) => copie.chemin === chemin) ?? null
        const laPlusRecente = valables.reduce(
            (retenue, copie) => (retenue === null || copie.copieDu > retenue.copieDu ? copie : retenue),
            null,
        )

        /**
         * Déchiffre un enregistrement : ses données authentifiées sont le
         * compte de l'état, son chemin et le contrat. La page déchiffrée doit
         * être du composant que l'enregistrement nomme en clair, celui que la
         * liste blanche a jugé, comme dans `lireUneCopie` : sinon, elle est
         * illisible.
         *
         * @param {EnregistrementDUneCopie} enregistrement
         * @returns {Promise<CopieLue>}
         */
        const dechiffrerLaCopie = async (enregistrement) => {
            const contenu = await dechiffrer(
                cle,
                donneesAuthentifiees(etat.compte, enregistrement.chemin),
                enregistrement,
            )

            if (contenu?.page?.component !== enregistrement.composant) {
                throw new TypeError('La page déchiffrée n’est pas celle que nomme sa copie.')
            }

            return {
                page: contenu.page,
                ziggy: contenu.ziggy ?? null,
                copieDu: enregistrement.copieDu,
                ecriteLe: enregistrement.ecriteLe,
                datesDesProps: contenu.datesDesProps ?? {},
            }
        }

        /** @type {{ copie: CopieLue | null, plusRecente: CopieLue | null }} */
        let lues

        try {
            const copie = deLAdresse === null ? null : await dechiffrerLaCopie(deLAdresse)
            const plusRecente =
                laPlusRecente === null
                    ? null
                    : laPlusRecente === deLAdresse
                      ? copie
                      : await dechiffrerLaCopie(laPlusRecente)

            lues = { copie, plusRecente }
        } catch {
            return refuser('illisible', { fermerLEtat: false })
        }

        if (
            generation !== generationDeDepart ||
            lireLeTemoin() !== temoin ||
            lireLeTitulaire() !== `compte:${etat.compte}`
        ) {
            return { refus: 'perimee' }
        }

        return {
            compte: etat.compte,
            ...lues,
            copies: valables.map(({ chemin: cheminDeLaCopie, composant, copieDu }) => ({
                chemin: cheminDeLaCopie,
                composant,
                copieDu,
            })),
        }
    }

    /**
     * Ce que le magasin garde pour le compte d'une page, sans rien déchiffrer :
     * le chemin, le composant et les dates de chaque copie, et le dernier
     * rejeu d'une écriture de séance. Ce que le préchauffage lit pour savoir
     * quelle page du noyau manque ou a vieilli.
     *
     * `possible` est faux quand aucune copie ne pourrait s'écrire pour ce
     * compte : sans `crypto.subtle`, sans compte, sans clé au témoin, un autre
     * titulaire de l'appareil, une clé fermée, ou IndexedDB qui se refuse.
     * Un état ouvert pour une autre clé, un autre compte ou un autre contrat
     * ne montre aucune copie : la prochaine réponse complète le videra.
     *
     * @param {object} page la page d'Inertia qui dit le compte
     * @returns {Promise<{
     *   possible: boolean,
     *   raison: string | null,
     *   empreinte: string | null,
     *   copies: Array<{ chemin: string, composant: string, copieDu: number, ecriteLe: number }>,
     *   dernierRejeu: number | null,
     * }>}
     */
    const inventaire = async (page) => {
        const refus = (raison) => ({ possible: false, raison, empreinte: null, copies: [], dernierRejeu: null })

        if (subtle === undefined || subtle === null) {
            return refus('indisponible')
        }

        const compte = compteDeLaPage(page)

        if (compte === null) {
            return refus('sans-compte')
        }

        const octets = octetsDeLaCle(lireLeTemoin())

        if (octets === null) {
            return refus('sans-cle')
        }

        if (lireLeTitulaire() !== `compte:${compte}`) {
            return refus('autre-titulaire')
        }

        try {
            const empreinte = await empreinteDe(octets)
            const transaction = await transactionSur([MAGASIN_DES_PAGES, MAGASIN_DE_L_ETAT], 'readonly')
            const [etat, copies] = await Promise.all([
                resultatDe(transaction.objectStore(MAGASIN_DE_L_ETAT).get(CLE_DE_L_ETAT)),
                resultatDe(transaction.objectStore(MAGASIN_DES_PAGES).getAll()),
            ])

            if (etat?.empreintesFermees?.includes(empreinte)) {
                return refus('cle-fermee')
            }

            const ouvert = ouvertPour(etat, compte, empreinte)

            return {
                possible: true,
                raison: null,
                empreinte,
                copies: ouvert
                    ? copies
                          .filter((copie) => copie.empreinte === empreinte)
                          .map(({ chemin, composant, copieDu, ecriteLe }) => ({ chemin, composant, copieDu, ecriteLe }))
                    : [],
                dernierRejeu: ouvert && Number.isFinite(etat.dernierRejeu) ? etat.dernierRejeu : null,
            }
        } catch {
            return refus('indisponible')
        }
    }

    /**
     * Note dans l'état l'instant d'un rejeu d'écriture de séance par la file
     * hors ligne : la copie de la séance, écrite avant, ne l'a pas, et le
     * prochain préchauffage la redemande. Rien quand l'état est fermé.
     *
     * @returns {Promise<boolean>} vrai quand l'instant est noté
     */
    const noterUnRejeu = async () => {
        if (subtle === undefined || subtle === null) {
            return false
        }

        const instant = horloge()

        try {
            const transaction = await transactionSur([MAGASIN_DE_L_ETAT], 'readwrite')
            const fin = finDe(transaction)
            const magasinDeLEtat = transaction.objectStore(MAGASIN_DE_L_ETAT)
            const demandeDeLEtat = magasinDeLEtat.get(CLE_DE_L_ETAT)
            let note = false

            demandeDeLEtat.onsuccess = () => {
                /** @type {EtatDuMagasin | undefined} */
                const etat = demandeDeLEtat.result

                if (etat?.ouvert !== true) {
                    return
                }

                note = true
                magasinDeLEtat.put({ ...etat, dernierRejeu: Math.max(instant, etat.dernierRejeu ?? 0) }, CLE_DE_L_ETAT)
            }

            await fin

            return note
        } catch {
            return false
        }
    }

    /**
     * Retire les copies de séance qui la montrent encore en cours, sauf celle
     * de la séance que le compte a en cours (`auth.user.active_workout`) :
     * close ou supprimée depuis, ici ou sur un autre appareil, une telle copie
     * ferait croire qu'elle attend des séries. Une copie qui montre sa séance
     * close reste.
     *
     * Chaque copie se déchiffre d'abord ; elles ne se retirent que dans une
     * transaction qui les retrouve telles quelles, sous la génération notée à
     * la décision.
     *
     * @param {string | null} enCours la clé de la séance en cours, ou `null`
     * @param {{ generation?: number }} [options]
     * @returns {Promise<string[] | string>} les chemins retirés, ou la raison de ne rien avoir fait
     */
    const retirerLesSeancesQuiNeSontPlusEnCours = async (
        enCours,
        { generation: generationDeDepart = generation } = {},
    ) => {
        if (subtle === undefined || subtle === null) {
            return 'indisponible'
        }

        try {
            const lecture = await transactionSur([MAGASIN_DES_PAGES, MAGASIN_DE_L_ETAT], 'readonly')
            const [etat, copies] = await Promise.all([
                resultatDe(lecture.objectStore(MAGASIN_DE_L_ETAT).get(CLE_DE_L_ETAT)),
                resultatDe(lecture.objectStore(MAGASIN_DES_PAGES).getAll()),
            ])

            if (etat?.ouvert !== true || etat.contrat !== VERSION_DU_CONTRAT) {
                return 'etat-ferme'
            }

            const octets = octetsDeLaCle(lireLeTemoin())

            if (octets === null || (await empreinteDe(octets)) !== etat.empreinte) {
                return 'sans-cle'
            }

            const cle = await importer(octets)
            const aRetirer = []

            for (const copie of copies) {
                if (
                    copie.composant !== COMPOSANT_DE_LA_SEANCE ||
                    copie.chemin === enCours ||
                    copie.empreinte !== etat.empreinte
                ) {
                    continue
                }

                const contenu = await dechiffrer(cle, donneesAuthentifiees(etat.compte, copie.chemin), copie).catch(
                    () => null,
                )
                const seance = contenu?.page?.props?.workout

                if (seance !== null && typeof seance === 'object' && !seance.ended_at) {
                    aRetirer.push(copie)
                }
            }

            if (aRetirer.length === 0) {
                return []
            }

            const transaction = await transactionSur([MAGASIN_DES_PAGES], 'readwrite')
            const fin = finDe(transaction)
            const pages = transaction.objectStore(MAGASIN_DES_PAGES)
            const retirees = []

            if (generation !== generationDeDepart) {
                transaction.abort()
                await fin.catch(() => {})

                return 'perimee'
            }

            for (const copie of aRetirer) {
                const demande = pages.get(copie.chemin)

                demande.onsuccess = () => {
                    // Une copie réécrite depuis la lecture est celle d'une réponse plus récente : elle reste.
                    if (demande.result?.ecriteLe === copie.ecriteLe) {
                        pages.delete(copie.chemin)
                        retirees.push(copie.chemin)
                    }
                }
            }

            await fin

            return retirees
        } catch {
            return 'erreur'
        }
    }

    /**
     * Suit les purges, de cet onglet comme des autres : le préchauffage s'y
     * arrête. Rend de quoi ne plus suivre.
     *
     * @param {() => void} rappel
     * @returns {() => void}
     */
    const surUnePurge = (rappel) => {
        rappelsDePurge.add(rappel)

        return () => rappelsDePurge.delete(rappel)
    }

    /** Ferme le canal, l'écoute et la base : la fin d'un onglet, dans un test. */
    const fermer = async () => {
        canal?.close()
        canal = null
        fenetre?.removeEventListener?.('storage', surLeStockage)
        rappelsDePurge.clear()

        const ouverte = await baseOuverte?.catch(() => null)

        ouverte?.close()
        baseOuverte = null
    }

    return {
        ecrireUneCopie,
        completerUneCopie,
        lireUneCopie,
        lireAuDemarrage,
        inventaire,
        noterUnRejeu,
        retirerLesSeancesQuiNeSontPlusEnCours,
        oublierLesCopies,
        rouvrirLaCle,
        empreinteDuTemoin,
        oublierLesCopiesDUnAutreTitulaire,
        entretenir,
        surUnePurge,
        generationCourante: () => generation,
        fermer,
    }
}

/** @type {ReturnType<typeof creerLeMagasinDesCopies> | null} */
let magasinDeLaPage = null

/**
 * Le magasin de la page, créé à la première demande : il écoute dès lors les
 * purges des autres onglets.
 */
export const magasinDesCopies = () => {
    magasinDeLaPage ??= creerLeMagasinDesCopies()

    return magasinDeLaPage
}

/**
 * Oublie les copies du magasin de la page (voir `oublierLesCopies` du
 * magasin) : ce qu'appellent la déconnexion, avant son envoi, et la
 * suppression du compte (#2048, décision 6 du propriétaire). Ne lève jamais,
 * pas même à la création du magasin : rien ici ne doit retenir une
 * déconnexion.
 *
 * @param {{
 *   fermerLEtat?: boolean,
 *   fermerLeTemoin?: boolean,
 *   fermerAussi?: Promise<string | null> | string | null,
 * }} [options]
 * @returns {Promise<boolean>}
 */
export const oublierLesCopies = (options) => {
    try {
        return magasinDesCopies().oublierLesCopies(options)
    } catch {
        return Promise.resolve(false)
    }
}

/**
 * Oublie les copies du magasin de la page avant un envoi qui peut finir la
 * session, la clé du témoin fermée avec elles : ce que la suppression du
 * compte fait avant son envoi (#2048, décision 6 du propriétaire). Une
 * réponse perdue ne laisse donc rien de lisible, et une réponse en vol d'un
 * autre onglet ne rouvre rien. Rend de quoi fermer encore la clé relevée
 * avant l'envoi une fois l'envoi accepté (`empreinte`, que sa réponse a
 * déjà effacée du témoin), et de quoi la rouvrir s'il est refusé, la session
 * vivante (`rouvrir`, voir `rouvrirLaCle` du magasin). Ne lève ni ne rejette
 * jamais.
 *
 * @returns {{
 *   empreinte: Promise<string | null>,
 *   fini: Promise<boolean>,
 *   rouvrir: (compte: string | number | null) => Promise<boolean>,
 * }}
 */
export const oublierLesCopiesAvantUnEnvoi = () => {
    try {
        const magasin = magasinDesCopies()
        const empreinte = magasin.empreinteDuTemoin()
        const fini = magasin.oublierLesCopies({ fermerLeTemoin: true }).catch(() => false)
        const generation = magasin.generationCourante()

        return {
            empreinte,
            fini,
            rouvrir: (compte) =>
                fini.then(() => magasin.rouvrirLaCle({ empreinte, compte, generation })).catch(() => false),
        }
    } catch {
        return { empreinte: Promise.resolve(null), fini: Promise.resolve(false), rouvrir: () => Promise.resolve(false) }
    }
}

/**
 * Oublie les copies du magasin de la page quand l'appareil a changé de
 * titulaire : ce que la garde de l'historique appelle (`main.js`). Ne lève
 * jamais.
 *
 * @returns {Promise<boolean>}
 */
export const oublierLesCopiesDUnAutreTitulaire = () => {
    try {
        return magasinDesCopies().oublierLesCopiesDUnAutreTitulaire()
    } catch {
        return Promise.resolve(false)
    }
}
