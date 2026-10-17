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
 * **L'état** (`etat`) dit à quelle clé le magasin est ouvert : le compte,
 * l'empreinte de la clé, la version du contrat, `ouvert`, les empreintes des
 * vingt dernières clés fermées, et `dernierRejeu`.
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
 * **Le volume** est borné (`PLAFOND_DES_COPIES_EN_OCTETS`) : la copie la plus
 * anciennement écrite sort la première, jamais la séance en cours ; trois
 * séances closes au plus restent à côté d'elle ; une copie de plus de sept
 * jours s'efface.
 *
 * Sans `crypto.subtle` (un contexte qui n'est pas sûr) ou sans IndexedDB
 * (jsdom, une navigation privée qui le refuse), aucune copie, et aucune
 * erreur. Ce module est le seul à ouvrir IndexedDB, par une fonction injectée
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

    /** @returns {Promise<IDBDatabase>} */
    const base = () => {
        baseOuverte ??= Promise.resolve()
            .then(ouvrirLaBase)
            .then((ouverte) => {
                ouverte.onversionchange = () => {
                    ouverte.close()
                    baseOuverte = null
                }

                return ouverte
            })
            .catch((erreur) => {
                baseOuverte = null

                throw erreur
            })

        return baseOuverte
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
     * @param {IDBDatabase} ouverte
     * @param {string} chemin
     * @returns {Promise<{ etat: EtatDuMagasin | undefined, copie: EnregistrementDUneCopie | undefined }>}
     */
    const lireLEtatEtLaCopie = async (ouverte, chemin) => {
        const transaction = ouverte.transaction([MAGASIN_DES_PAGES, MAGASIN_DE_L_ETAT], 'readonly')
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
     * @param {IDBDatabase} ouverte
     * @param {EnregistrementDUneCopie} copie
     * @param {{
     *   compte: string,
     *   temoin: string,
     *   generationDeDepart: number,
     *   ouvrir: boolean,
     *   ecriteLeAttendue?: number,
     *   protegee: string | null,
     * }} conditions
     * @returns {Promise<string>} `ecrite`, ou la raison du refus
     */
    const ranger = async (
        ouverte,
        copie,
        { compte, temoin, generationDeDepart, ouvrir, ecriteLeAttendue, protegee },
    ) => {
        const transaction = ouverte.transaction([MAGASIN_DES_PAGES, MAGASIN_DE_L_ETAT], 'readwrite')
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
            const ouverte = await base()
            const [empreinte, cle] = await Promise.all([empreinteDe(octets), importer(octets)])

            return { chemin, compte, temoin, empreinte, cle, ouverte }
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
     * gardées, avec leur date.
     *
     * La génération se note à l'appel : une écriture différée passe celle
     * qu'elle a notée quand elle a été décidée.
     *
     * @param {object} page l'objet de page d'Inertia
     * @param {{ ziggy?: object | null, generation?: number }} [options]
     * @returns {Promise<string>} `ecrite`, ou la raison du refus
     */
    const ecrireUneCopie = async (page, { ziggy = null, generation: generationDeDepart = generation } = {}) => {
        const preparee = await preparer(page)

        if (preparee.refus !== undefined) {
            return preparee.refus
        }

        const { chemin, compte, temoin, empreinte, cle, ouverte } = preparee

        try {
            const authentifiees = donneesAuthentifiees(compte, chemin)
            const { etat, copie: precedente } = await lireLEtatEtLaCopie(ouverte, chemin)
            const ancienne =
                precedente !== undefined && ouvertPour(etat, compte, empreinte) && precedente.empreinte === empreinte
                    ? await dechiffrer(cle, authentifiees, precedente).catch(() => null)
                    : null
            const maintenant = horloge()
            const props = { ...page.props }
            const datesDesProps = Object.fromEntries(Object.keys(props).map((nom) => [nom, maintenant]))

            for (const nom of Object.values(page.deferredProps ?? {}).flat()) {
                if (!(nom in props) && ancienne !== null && nom in (ancienne.page?.props ?? {})) {
                    props[nom] = ancienne.page.props[nom]
                    datesDesProps[nom] = ancienne.datesDesProps?.[nom] ?? precedente.copieDu
                }
            }

            const { iv, chiffre } = await chiffrer(cle, authentifiees, {
                page: { ...page, props },
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

            return await ranger(ouverte, copie, {
                compte,
                temoin,
                generationDeDepart,
                ouvrir: true,
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
     * renouvelle.
     *
     * @param {object} page l'objet de page d'Inertia après la réponse
     * @param {string[]} noms les props que la réponse a reçues
     * @param {{ generation?: number }} [options]
     * @returns {Promise<string>} `ecrite`, ou la raison du refus
     */
    const completerUneCopie = async (page, noms, { generation: generationDeDepart = generation } = {}) => {
        const preparee = await preparer(page)

        if (preparee.refus !== undefined) {
            return preparee.refus
        }

        const { chemin, compte, temoin, empreinte, cle, ouverte } = preparee
        const recues = noms.filter((nom) => nom !== 'auth' && nom in (page.props ?? {}))

        if (recues.length === 0) {
            return 'sans-props'
        }

        try {
            const authentifiees = donneesAuthentifiees(compte, chemin)
            const { etat, copie: precedente } = await lireLEtatEtLaCopie(ouverte, chemin)

            if (
                precedente === undefined ||
                !ouvertPour(etat, compte, empreinte) ||
                precedente.empreinte !== empreinte
            ) {
                return 'sans-copie'
            }

            const ancienne = await dechiffrer(cle, authentifiees, precedente)
            const maintenant = horloge()

            ancienne.datesDesProps ??= {}

            for (const nom of recues) {
                ancienne.page.props[nom] = page.props[nom]
                ancienne.datesDesProps[nom] = maintenant
            }

            const { iv, chiffre } = await chiffrer(cle, authentifiees, ancienne)
            const copie = { ...precedente, ecriteLe: maintenant, iv, chiffre }

            if (volumeDe(copie) > PLAFOND_DES_COPIES_EN_OCTETS) {
                return 'trop-grande'
            }

            return await ranger(ouverte, copie, {
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
     * L'empreinte de la clé à laquelle le magasin était ouvert rejoint les
     * fermées (`fermerLEtat`). Celle de la clé que porte encore le témoin
     * aussi quand la session finit (`fermerLeTemoin` : la déconnexion, la
     * suppression du compte, un signal de fin de session), même si le magasin
     * ne lui a jamais été ouvert : une réponse en vol qui la reposerait ne
     * rouvrirait rien. Une clé fermée reste fermée tant que l'état la retient.
     *
     * @param {{ fermerLEtat?: boolean, fermerLeTemoin?: boolean }} [options]
     * @returns {Promise<boolean>} vrai quand la transaction a abouti
     */
    const oublierLesCopies = async ({ fermerLEtat = true, fermerLeTemoin = false } = {}) => {
        generation += 1
        annoncer()
        prevenirDUnePurge()

        try {
            const octets = fermerLeTemoin && subtle ? octetsDeLaCle(lireLeTemoin()) : null
            const empreinteDuTemoin = octets === null ? null : await empreinteDe(octets)
            const ouverte = await base()
            const transaction = ouverte.transaction([MAGASIN_DES_PAGES, MAGASIN_DE_L_ETAT], 'readwrite')
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
            const { etat, copie } = await lireLEtatEtLaCopie(await base(), chemin)

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
        oublierLesCopies,
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
