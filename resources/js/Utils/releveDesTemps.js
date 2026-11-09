/**
 * Le relevé des temps, dans le navigateur (#1315, #2148, étape 1).
 *
 * Deux mesures que le serveur ne peut pas prendre seul :
 *
 * - **chaque requête de la page** de même origine, `XMLHttpRequest` (les
 *   visites d'Inertia, les props différées) et `fetch` (les écritures de la
 *   séance par `http()`), lue dans `PerformanceResourceTiming` : le TTFB
 *   (`responseStart − requestStart`), le passage par le service worker
 *   (`requestStart − workerStart`), les métriques de l'en-tête
 *   `Server-Timing` (`app`, `routage`, `controleur`, `rendu`, `sql` et
 *   `cache`, avec leurs nombres), et l'écart entre le TTFB et `app`, ce qui
 *   entoure l'application : réseau à l'aller et au retour, proxy inverse,
 *   attente d'un worker libre. La page complète d'ouverture y entre aussi,
 *   par son entrée `navigation` ;
 * - **la navigation perçue** : une marque User Timing (`releve:toucher`) au
 *   `before` d'une visite au premier plan, ni préchargée, ni asynchrone, ni
 *   partielle, puis une mesure (`navigation /workouts`) à la première image
 *   peinte après son `navigate`, deux `requestAnimationFrame` plus tard. Elle
 *   se lit dans l'onglet Performance des outils du navigateur.
 *
 * **Dormant tant que le serveur ne mesure pas.** Le relevé ne s'arme que si
 * l'entrée `navigation` du document porte des `serverTiming`, c'est-à-dire
 * `SERVER_TIMING_ENABLED` allumée et une page complète servie à un compte
 * connecté ; à défaut, il regarde la première requête de même origine de la
 * page, au cas où un navigateur ne recopierait pas l'en-tête d'un document
 * que le service worker a rendu, puis ne regarde plus rien. Coupé, il ne pose
 * ni marque, ni écouteur du routeur, ni fonction de console, et ne garde rien.
 *
 * **Rien ne sort de l'appareil, rien n'est gardé hors de la mémoire.** Aucune
 * requête : les mesures vivent dans deux tampons bornés (`MESURES_AU_PLUS`),
 * perdus au rechargement, et rien d'elles n'est écrit. Les adresses sont
 * réduites à leur chemin, sans origine ni chaîne de requête, et chaque
 * segment qui identifie quelque chose devient `{id}` : le tableau se colle tel
 * quel dans un commentaire d'issue.
 *
 * **Lisible sans outils de développement.** `releverLesTemps()` rend le
 * tableau dans la console. Sur un téléphone, `#releve-des-temps` au bout de
 * l'adresse d'une page pose un drapeau local, et le relevé s'affiche alors à
 * l'écran, avec un bouton qui copie le tableau ; « Fermer » retire le
 * drapeau, seule chose que le relevé écrive (`localStorage`, sous try/catch).
 * Replié, le panneau ne calcule rien ; déplié, il se recalcule au plus une
 * fois par seconde, jamais pendant une navigation : sinon il allongerait la
 * mesure qu'il affiche.
 */
import { nombre } from '@/Utils/nombre'

/** La clé du drapeau local qui affiche le relevé à l'écran. */
export const DRAPEAU_DE_L_AFFICHAGE = 'releve-des-temps'

/** Le fragment d'adresse qui pose ce drapeau : il ne part jamais au serveur. */
export const FRAGMENT_QUI_AFFICHE = '#releve-des-temps'

/** Le nombre de mesures gardées, par tampon : les plus anciennes partent. */
export const MESURES_AU_PLUS = 200

/** La marque User Timing posée au toucher. */
export const MARQUE_DU_TOUCHER = 'releve:toucher'

/** Le début du nom de chaque mesure de navigation, suivi du chemin relatif. */
export const PREFIXE_DES_NAVIGATIONS = 'navigation '

/** La fonction de console, posée sur la fenêtre tant que le relevé est armé. */
export const FONCTION_DE_CONSOLE = 'releverLesTemps'

/** L'écart au moins entre deux recalculs du tableau du panneau déplié. */
export const RECALCUL_DU_PANNEAU_AU_PLUS_TOUTES_LES_MS = 1000

/** Les métriques que pose `App\Http\Middleware\TempsDuServeur`. */
const METRIQUES_DU_SERVEUR = ['app', 'routage', 'controleur', 'rendu', 'sql', 'cache']

/** Les requêtes que la page fait elle-même. */
const SORTES_DE_REQUETES = ['xmlhttprequest', 'fetch']

/** Les requêtes annoncées dont l'entrée n'est pas encore arrivée, au plus. */
const ANNONCES_AU_PLUS = 50

/** Ce que la page a annoncé de la requête en cours, lu par `noterLaRequete()`. */
let annoncerUneRequete = null

/**
 * Un segment de chemin qui identifie quelque chose : un nombre, ou une clé
 * assez longue qui porte un chiffre (UUID, jeton, clé tirée par l'appareil).
 * `v1`, `en-attente` ou `chest` restent tels quels.
 *
 * @param {string} segment
 * @returns {boolean}
 */
const estUnIdentifiant = (segment) =>
    /^\d+$/.test(segment) || (segment.length >= 16 && /\d/.test(segment) && /^[\w-]+$/.test(segment))

/**
 * Le chemin d'une adresse de même origine, sans chaîne de requête ni
 * fragment, chaque identifiant remplacé par `{id}` ; `null` pour une autre
 * origine ou une adresse illisible.
 *
 * @param {string} adresse
 * @param {string} origine
 * @returns {string|null}
 */
export const cheminNormalise = (adresse, origine) => {
    let url

    try {
        url = new URL(adresse, origine)
    } catch {
        return null
    }

    if (url.origin !== origine) {
        return null
    }

    return url.pathname
        .split('/')
        .map((segment) => (estUnIdentifiant(segment) ? '{id}' : segment))
        .join('/')
}

/**
 * Les métriques de `Server-Timing` qu'une entrée porte : durées en
 * millisecondes, et le nombre que `sql` et `cache` disent dans leur
 * description (`"7 requetes"`, `"8 lectures"`).
 *
 * @param {ReadonlyArray<{name: string, duration: number, description: string}>|undefined} serverTiming
 * @returns {Record<string, number>}
 */
export const tempsDuServeur = (serverTiming) => {
    const temps = {}

    for (const { name, duration, description } of serverTiming ?? []) {
        if (!METRIQUES_DU_SERVEUR.includes(name)) {
            continue
        }

        temps[name] = duration

        const nombreAnnonce = Number.parseInt(description ?? '', 10)

        if ((name === 'sql' || name === 'cache') && Number.isFinite(nombreAnnonce)) {
            temps[`${name}Nombre`] = nombreAnnonce
        }
    }

    return temps
}

/**
 * Si l'entrée porte l'en-tête de l'application : le serveur mesure.
 *
 * @param {PerformanceEntry|undefined} entree
 * @returns {boolean}
 */
const porteLesTempsDuServeur = (entree) => tempsDuServeur(entree?.serverTiming).app !== undefined

/**
 * Une entrée que la page a demandée elle-même, à sa propre origine.
 *
 * @param {PerformanceResourceTiming} entree
 * @param {string} origine
 * @returns {boolean}
 */
const estUneRequeteDeLaPage = (entree, origine) =>
    SORTES_DE_REQUETES.includes(entree.initiatorType) && cheminNormalise(entree.name, origine) !== null

/**
 * Un écart entre deux instants d'une entrée, ou `null` quand l'un manque ou
 * qu'ils ne se suivent pas.
 *
 * @param {number} depuis
 * @param {number} jusqua
 * @returns {number|null}
 */
const ecart = (depuis, jusqua) => (depuis > 0 && jusqua >= depuis ? jusqua - depuis : null)

/**
 * La ligne du relevé d'une entrée.
 *
 * L'écart hors de l'application (`horsDeLApp`, TTFB − `app`) va de l'envoi de
 * la requête au premier octet reçu : il compte le réseau dans les deux sens,
 * le proxy inverse, l'attente d'un worker libre et ce que le serveur fait
 * avant et après sa mesure. Ce n'est pas l'attente d'un worker seule.
 *
 * Le passage par le service worker se lit de `workerStart` (le démarrage du
 * worker, ou l'envoi de l'évènement `fetch` s'il tournait déjà) à l'envoi de
 * la requête : `fetchStart`, que les navigateurs ne placent pas tous du même
 * côté de `workerStart`, s'y prêterait mal. Une requête que le navigateur a
 * envoyée pendant que le worker démarrait (le préchargement d'une navigation)
 * n'en a pas.
 *
 * Une redirection de même origine, que `XMLHttpRequest` et `fetch` suivent
 * d'eux-mêmes (le 303 qui suit une écriture d'Inertia), ne fait qu'une entrée :
 * son nom est l'adresse de départ, mais ses instants et ses `serverTiming`
 * sont ceux de la requête d'arrivée. `redirection` garde le temps de la
 * requête de départ, de `redirectStart` à `redirectEnd` (la fin de la réponse
 * qui redirige) ; l'en-tête de cette réponse, lui, ne se lit pas. L'entrée du
 * document porte au contraire l'adresse d'arrivée, comme ses temps : seule sa
 * colonne `redirection` dit qu'il a été redirigé.
 *
 * @param {PerformanceResourceTiming} entree
 * @param {string} etiquette
 * @returns {object}
 */
export const ligneDuReleve = (entree, etiquette) => {
    const ttfb = ecart(entree.requestStart, entree.responseStart)
    const serveur = tempsDuServeur(entree.serverTiming)

    return {
        etiquette,
        ttfb,
        horsDeLApp: ttfb !== null && serveur.app !== undefined ? ttfb - serveur.app : null,
        app: serveur.app ?? null,
        routage: serveur.routage ?? null,
        controleur: serveur.controleur ?? null,
        rendu: serveur.rendu ?? null,
        sql: serveur.sql ?? null,
        requetesSql: serveur.sqlNombre ?? null,
        cache: serveur.cache ?? null,
        lecturesDuCache: serveur.cacheNombre ?? null,
        worker: ecart(entree.workerStart, entree.requestStart),
        redirection: ecart(entree.redirectStart, entree.redirectEnd),
    }
}

/**
 * La valeur au rang `centieme` des valeurs, par le rang le plus proche : la
 * médiane à 50, le p95 à 95, qui vaut le maximum sous vingt mesures.
 *
 * @param {number[]} valeurs
 * @param {number} centieme
 * @returns {number|null}
 */
export const centile = (valeurs, centieme) => {
    const triees = valeurs
        .filter((valeur) => typeof valeur === 'number' && Number.isFinite(valeur))
        .sort((a, b) => a - b)

    if (triees.length === 0) {
        return null
    }

    return triees[Math.max(0, Math.ceil((centieme / 100) * triees.length) - 1)]
}

/** Une durée en millisecondes, une décimale au plus ; un tiret sans valeur. */
const duree = (valeur) => nombre(valeur, 1)

/**
 * Une cellule « médiane / p95 ».
 *
 * @param {number[]} valeurs
 * @returns {string}
 */
const medianeEtP95 = (valeurs) => {
    const mediane = centile(valeurs, 50)

    return mediane === null ? '—' : `${duree(mediane)} / ${duree(centile(valeurs, 95))}`
}

/**
 * Les lignes regroupées par une clé, dans l'ordre alphabétique de la clé.
 *
 * @template T
 * @param {T[]} lignes
 * @param {(ligne: T) => string} cle
 * @returns {Array<[string, T[]]>}
 */
const regroupees = (lignes, cle) => {
    const groupes = new Map()

    for (const ligne of lignes) {
        groupes.set(cle(ligne), [...(groupes.get(cle(ligne)) ?? []), ligne])
    }

    return [...groupes.entries()].sort(([a], [b]) => a.localeCompare(b))
}

/**
 * Le relevé en tableaux Markdown, prêts à coller dans un commentaire :
 * chemins relatifs, millisecondes, médiane puis p95 de chaque mesure.
 *
 * @param {{requetes: object[], navigations: Array<{chemin: string, duree: number}>}} releve
 * @returns {string}
 */
export const tableauDuReleve = ({ requetes, navigations }) => {
    const lignes = [
        'Relevé des temps : chemins relatifs, millisecondes, médiane / p95.',
        'Une requête « → redirigée » porte les temps de sa requête d’arrivée ; « redirection » est le temps de la requête de départ, réponse qui redirige comprise.',
        '',
        '| Requête | n | TTFB | hors de l’app | app | routage | contrôleur | rendu | sql | requêtes SQL | cache | lectures du cache | worker | redirection |',
        '| --- | --: | --: | --: | --: | --: | --: | --: | --: | --: | --: | --: | --: | --: |',
    ]

    for (const [etiquette, groupe] of regroupees(requetes, (ligne) => ligne.etiquette)) {
        const colonne = (nom) => groupe.map((ligne) => ligne[nom])

        lignes.push(
            `| ${etiquette} | ${groupe.length} | ${medianeEtP95(colonne('ttfb'))} | ${medianeEtP95(colonne('horsDeLApp'))} | ` +
                `${medianeEtP95(colonne('app'))} | ${medianeEtP95(colonne('routage'))} | ${medianeEtP95(colonne('controleur'))} | ` +
                `${medianeEtP95(colonne('rendu'))} | ${medianeEtP95(colonne('sql'))} | ${duree(centile(colonne('requetesSql'), 50))} | ` +
                `${medianeEtP95(colonne('cache'))} | ${duree(centile(colonne('lecturesDuCache'), 50))} | ${medianeEtP95(colonne('worker'))} | ` +
                `${medianeEtP95(colonne('redirection'))} |`,
        )
    }

    lignes.push('', '| Navigation | n | médiane | p95 |', '| --- | --: | --: | --: |')

    for (const [chemin, groupe] of regroupees(navigations, (navigation) => navigation.chemin)) {
        const durees = groupe.map((navigation) => navigation.duree)

        lignes.push(`| ${chemin} | ${groupe.length} | ${duree(centile(durees, 50))} | ${duree(centile(durees, 95))} |`)
    }

    return lignes.join('\n')
}

/**
 * Ajoute au tampon, et retire les plus anciennes au-delà de la borne.
 *
 * @template T
 * @param {T[]} tampon
 * @param {T} element
 * @param {number} borne
 */
const ajouterBorne = (tampon, element, borne) => {
    tampon.push(element)
    tampon.splice(0, Math.max(0, tampon.length - borne))
}

/**
 * Le drapeau local, lu ou écrit sous try/catch : le stockage peut être
 * bloqué (navigation privée, données du site refusées) ou plein.
 *
 * @param {Window} fenetre
 * @param {'lire'|'poser'|'retirer'} geste
 * @returns {boolean}
 */
const drapeau = (fenetre, geste) => {
    try {
        if (geste === 'poser') {
            fenetre.localStorage.setItem(DRAPEAU_DE_L_AFFICHAGE, '1')
        } else if (geste === 'retirer') {
            fenetre.localStorage.removeItem(DRAPEAU_DE_L_AFFICHAGE)
        }

        return fenetre.localStorage.getItem(DRAPEAU_DE_L_AFFICHAGE) === '1'
    } catch {
        return false
    }
}

/**
 * Ce qu'une visite d'Inertia est : préchargée, partielle (les props
 * différées en sont), asynchrone, ou une visite au premier plan, la seule
 * dont la navigation se mesure.
 *
 * @param {object} visite
 * @returns {'préchargement'|'partielle'|'asynchrone'|'visite'}
 */
const sorteDeLaVisite = (visite) => {
    if (visite.prefetch) {
        return 'préchargement'
    }

    if ((visite.only?.length ?? 0) > 0 || (visite.except?.length ?? 0) > 0 || (visite.reset?.length ?? 0) > 0) {
        return 'partielle'
    }

    return visite.async ? 'asynchrone' : 'visite'
}

/**
 * Le relevé à l'écran : un `<details>` replié en haut de l'écran, sous
 * l'en-tête, deux boutons puis le tableau dans un `<pre>`, écrit par
 * `textContent`. Construit hors de l'application Vue, qu'il ne doit ni
 * attendre ni gêner : c'est un outil de mesure, pas une page. Il n'écrit son
 * tableau que sur demande (`ecrire`), déplié (`deplie`, à chaque ouverture)
 * ou pour le sélectionner à copier.
 *
 * En haut, parce que le bas de l'écran est au minuteur de repos, qu'il
 * cachait pendant le relevé de la séance en cours. Déplié, son corps se borne
 * à l'écran moins le haut du panneau, son titre et la barre du bas
 * (`hauteur-sure-60`), et le tableau défile en lui-même : le titre, qui le
 * replie sans oublier le drapeau, et les boutons restent à l'écran, même sur
 * un téléphone en paysage.
 *
 * @param {Window} fenetre
 * @param {{tableau: () => string, fermer: () => void, deplie: () => void}} rappels
 * @returns {{ecrire: (texte: string) => void, estDeplie: () => boolean, retirer: () => void}}
 */
const monterLePanneau = (fenetre, { tableau, fermer, deplie }) => {
    const document = fenetre.document
    const panneau = document.createElement('details')
    const titre = document.createElement('summary')
    const corps = document.createElement('div')
    const contenu = document.createElement('pre')
    const copier = document.createElement('button')
    const quitter = document.createElement('button')
    const boutons = document.createElement('div')

    panneau.id = 'releve-des-temps'
    panneau.className =
        'z-flottant top-sure-20 right-sure-4 left-sure-4 sm:left-sure-6 glass-panel-light text-text-main fixed rounded-2xl px-3 py-1 text-xs shadow-lg sm:right-auto sm:w-96'
    titre.className = 'min-h-touch flex cursor-pointer items-center font-bold'
    corps.className = 'hauteur-sure-60 flex flex-col gap-2 pb-2'
    contenu.className = 'min-h-0 flex-1 overflow-auto whitespace-pre font-mono'
    boutons.className = 'flex shrink-0 gap-2'

    for (const bouton of [copier, quitter]) {
        bouton.type = 'button'
        bouton.className = 'min-h-touch bg-surface-sunken text-text-main flex-1 rounded-xl px-3 font-bold'
    }

    copier.textContent = 'Copier le tableau'
    quitter.textContent = 'Fermer'
    boutons.append(copier, quitter)
    corps.append(boutons, contenu)
    panneau.append(titre, corps)

    titre.textContent = 'Relevé des temps : rien ne quitte cet appareil'

    const ecrire = (texte) => {
        contenu.textContent = texte
    }

    copier.addEventListener('click', async () => {
        const texte = tableau()

        try {
            await fenetre.navigator.clipboard.writeText(texte)
            copier.textContent = 'Copié'
        } catch {
            // Sans presse-papiers (page non sécurisée, refus) : le texte reste sélectionné, à copier à la main.
            ecrire(texte)
            fenetre.getSelection?.()?.selectAllChildren(contenu)
            copier.textContent = 'Sélectionné : copie-le'
        }
    })

    quitter.addEventListener('click', fermer)
    panneau.addEventListener('toggle', () => {
        if (panneau.open) {
            deplie()
        }
    })
    document.body.append(panneau)

    return { ecrire, estDeplie: () => panneau.open, retirer: () => panneau.remove() }
}

/**
 * Arme le relevé : les tampons, l'observateur des requêtes, les écouteurs du
 * routeur, la fonction de console et, si le drapeau est posé, le panneau.
 *
 * @param {{routeur: object, fenetre: Window, origine: string, fragment: string}} contexte
 * @returns {{desinstaller: () => void, releve: () => {requetes: object[], navigations: object[]}}}
 */
const armer = ({ routeur, fenetre, origine, fragment }) => {
    const performance = fenetre.performance
    const requetes = []
    const navigations = []
    const annonces = []
    const retraits = []
    let panneau = null
    let enCours = null
    let mesuresAttendues = 0
    let tableauEnRetard = true
    let recalculPrevu = false
    let dernierRecalcul = Number.NEGATIVE_INFINITY

    const releve = () => ({ requetes: [...requetes], navigations: [...navigations] })
    const tableau = () => tableauDuReleve(releve())

    /*
     * Le tableau du panneau, tenu loin de ce qu'il mesure. Le bâtir coûte
     * (deux cents lignes : une dizaine de millisecondes, plusieurs fois plus
     * sur un téléphone lent), et une entrée de requête arrive pendant
     * qu'Inertia traite la réponse d'une visite : recalculé dans le rappel de
     * l'observateur, il allongeait la navigation qu'il rapporte. Il ne se
     * recalcule donc que déplié : à l'ouverture, puis par une minuterie, une
     * seconde au moins après le précédent, jamais pendant une navigation, du
     * `before` d'une visite au premier plan à sa mesure ou à sa fin sans page.
     */
    const navigationEnCours = () => enCours !== null || mesuresAttendues > 0

    const recalculer = () => {
        tableauEnRetard = false
        dernierRecalcul = performance.now()
        panneau?.ecrire(tableau())
    }

    const prevoirLeRecalcul = (attente = 0) => {
        if (!tableauEnRetard || recalculPrevu || !panneau?.estDeplie() || navigationEnCours()) {
            return
        }

        recalculPrevu = true
        fenetre.setTimeout(() => {
            recalculPrevu = false

            if (!panneau?.estDeplie() || navigationEnCours()) {
                return
            }

            const reste = dernierRecalcul + RECALCUL_DU_PANNEAU_AU_PLUS_TOUTES_LES_MS - performance.now()

            if (reste > 0) {
                prevoirLeRecalcul(reste)
            } else {
                recalculer()
            }
        }, attente)
    }

    /** Une mesure de plus : le tableau est en retard, et se recalculera plus tard s'il est montré. */
    const signaler = () => {
        tableauEnRetard = true
        prevoirLeRecalcul()
    }

    /**
     * L'étiquette d'une requête : sa méthode et sa sorte, d'après ce que la
     * page en a annoncé à son départ, la plus ancienne annonce de la même
     * adresse d'abord ; `?` quand rien ne l'annonce. Une requête redirigée le
     * dit : ses temps sont ceux de la requête d'arrivée, pas de l'écriture
     * qu'elle nomme (`ligneDuReleve()`).
     */
    const etiquetteDe = (entree, chemin) => {
        const rang = annonces.findIndex((annonce) => annonce.adresse === entree.name)
        const redirigee = entree.redirectEnd > 0 ? ' → redirigée' : ''

        if (rang === -1) {
            return `? ${chemin}${redirigee}`
        }

        const [{ methode, sorte }] = annonces.splice(rang, 1)

        return `${methode} ${chemin}${sorte ? ` (${sorte})` : ''}${redirigee}`
    }

    /** Une requête qui part : son adresse absolue, sans fragment, comme son entrée la nommera. */
    const annoncer = (adresse, methode, sorte) => {
        let url

        try {
            url = new URL(String(adresse), fenetre.location.href)
        } catch {
            return
        }

        url.hash = ''
        ajouterBorne(annonces, { adresse: url.href, methode: String(methode).toUpperCase(), sorte }, ANNONCES_AU_PLUS)
    }

    const relever = (entree, etiquette) => {
        ajouterBorne(requetes, ligneDuReleve(entree, etiquette), MESURES_AU_PLUS)
        signaler()
    }

    const entreeDuDocument = performance.getEntriesByType('navigation')[0]
    const cheminDuDocument = entreeDuDocument ? cheminNormalise(entreeDuDocument.name, origine) : null

    if (cheminDuDocument !== null) {
        relever(entreeDuDocument, `GET ${cheminDuDocument} (document)`)
    }

    const observateur = new fenetre.PerformanceObserver((liste) => {
        for (const entree of liste.getEntries()) {
            if (estUneRequeteDeLaPage(entree, origine)) {
                const chemin = cheminNormalise(entree.name, origine)

                relever(entree, etiquetteDe(entree, chemin))
            }
        }
    })

    observateur.observe({ type: 'resource', buffered: true })
    retraits.push(() => observateur.disconnect())

    retraits.push(
        routeur.on('start', (evenement) => {
            const visite = evenement.detail.visit

            annoncer(visite.url, visite.method, sorteDeLaVisite(visite))
        }),
    )

    retraits.push(
        routeur.on('before', (evenement) => {
            const visite = evenement.detail.visit

            if (sorteDeLaVisite(visite) !== 'visite') {
                return
            }

            let debut = performance.now()

            try {
                debut = performance.mark(MARQUE_DU_TOUCHER)?.startTime ?? debut
            } catch {
                // Sans User Timing (un navigateur ancien), la durée se garde tout de même.
            }

            enCours = { id: visite.id, debut }
        }),
    )

    retraits.push(
        routeur.on('navigate', (evenement) => {
            const { page, type, visitId } = evenement.detail

            if (type !== 'visit' || enCours === null || enCours.id !== visitId) {
                return
            }

            const { debut } = enCours
            const chemin = cheminNormalise(page.url, origine) ?? '?'

            enCours = null
            mesuresAttendues += 1

            const mesurer = () => {
                const fin = performance.now()

                try {
                    performance.measure(`${PREFIXE_DES_NAVIGATIONS}${chemin}`, { start: debut, end: fin })
                } catch {
                    // Une mesure refusée (User Timing absent ou ancien) n'empêche pas le relevé.
                }

                mesuresAttendues -= 1
                ajouterBorne(navigations, { chemin, duree: fin - debut }, MESURES_AU_PLUS)
                signaler()
            }

            // La première image peinte après la nouvelle page : le premier rappel précède sa peinture, le second la suit.
            if (typeof fenetre.requestAnimationFrame === 'function') {
                fenetre.requestAnimationFrame(() => fenetre.requestAnimationFrame(mesurer))
            } else {
                fenetre.setTimeout(mesurer, 0)
            }
        }),
    )

    /*
     * Une visite marquée qui finit sans `navigate` (vers la page courante,
     * refusée, interrompue) ne retient plus le recalcul ; Inertia émet
     * `navigate` avant `finish`, et une visite déjà posée n'a plus rien ici.
     */
    retraits.push(
        routeur.on('finish', (evenement) => {
            if (enCours !== null && enCours.id === evenement.detail.visit?.id) {
                enCours = null
                prevoirLeRecalcul()
            }
        }),
    )

    annoncerUneRequete = annoncer
    retraits.push(() => {
        annoncerUneRequete = null
    })

    fenetre[FONCTION_DE_CONSOLE] = () => {
        const texte = tableau()

        fenetre.console?.log(texte)

        return texte
    }
    retraits.push(() => {
        delete fenetre[FONCTION_DE_CONSOLE]
    })

    if (fragment === FRAGMENT_QUI_AFFICHE) {
        drapeau(fenetre, 'poser')
    }

    if (drapeau(fenetre, 'lire') && fenetre.document?.body) {
        panneau = monterLePanneau(fenetre, {
            tableau,
            fermer: () => {
                drapeau(fenetre, 'retirer')
                panneau?.retirer()
                panneau = null
            },
            deplie: recalculer,
        })
        retraits.push(() => panneau?.retirer())
    }

    return {
        releve,
        desinstaller: () => retraits.splice(0).forEach((retirer) => retirer?.()),
    }
}

/**
 * Note la méthode d'une requête que la page envoie hors du routeur d'Inertia
 * (`http()`, par `fetch`) : l'entrée de `PerformanceResourceTiming` ne la
 * dit pas. Ne fait rien tant que le relevé dort.
 *
 * @param {string} methode
 * @param {string} adresse
 */
export const noterLaRequete = (methode, adresse) => {
    annoncerUneRequete?.(adresse, methode, '')
}

/**
 * Installe le relevé des temps, depuis `main.js`, avant `createInertiaApp`.
 *
 * Rend `null` quand il dort pour de bon (le navigateur n'a pas les API, ou le
 * serveur ne mesure pas), sinon de quoi le retirer et lire ce qu'il garde.
 *
 * @param {{routeur: object, fenetre?: Window}} options
 * @returns {{desinstaller: () => void, releve: () => object}|null}
 */
export const installerLeReleveDesTemps = ({ routeur, fenetre = globalThis.window }) => {
    const performance = fenetre?.performance

    if (
        typeof performance?.getEntriesByType !== 'function' ||
        typeof performance.now !== 'function' ||
        typeof fenetre.PerformanceObserver !== 'function'
    ) {
        return null
    }

    const contexte = { routeur, fenetre, origine: fenetre.location.origin, fragment: fenetre.location.hash }

    if (porteLesTempsDuServeur(performance.getEntriesByType('navigation')[0])) {
        return armer(contexte)
    }

    /*
     * Le document ne porte pas l'en-tête : le serveur ne mesure pas, ou le
     * navigateur ne l'a pas recopié d'un document que le service worker a
     * rendu. La première requête de la page le dit, et elle seule.
     */
    let arme = null
    const guet = new fenetre.PerformanceObserver((liste) => {
        const premiere = liste.getEntries().find((entree) => estUneRequeteDeLaPage(entree, contexte.origine))

        if (premiere === undefined || arme !== null) {
            return
        }

        guet.disconnect()

        if (porteLesTempsDuServeur(premiere)) {
            arme = armer(contexte)
        }
    })

    try {
        guet.observe({ type: 'resource', buffered: true })
    } catch {
        return null
    }

    return {
        releve: () => arme?.releve() ?? { requetes: [], navigations: [] },
        desinstaller: () => {
            guet.disconnect()
            arme?.desinstaller()
        },
    }
}
