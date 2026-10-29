import { getCurrentInstance, onUnmounted } from 'vue'

/**
 * Le temps qu'une sortie arrêtée a pour reprendre, hors geste, avant que le son
 * qu'on lui demandait ne soit abandonné (#2112). Au-delà, le son partirait à
 * contretemps : au geste suivant, quand la sortie reprend enfin.
 */
export const DELAI_DE_REPRISE_MS = 1000

/** Les états d'une sortie qu'un geste fait repartir ; `interrupted` n'existe que dans WebKit. */
const ETATS_A_REPRENDRE = new Set(['suspended', 'interrupted'])

/**
 * Appelle une méthode de la sortie qui rend une promesse (`resume`, `close`),
 * sans jamais lever ni laisser de rejet non géré : une sortie qui ne repart
 * pas reste muette, une sortie qui ne se ferme pas n'a plus rien à rendre.
 *
 * @param {() => Promise<void>|void} appel
 * @returns {Promise<void>}
 */
const sansLever = (appel) => {
    try {
        return Promise.resolve(appel()).catch(() => {})
    } catch {
        return Promise.resolve()
    }
}

/**
 * Reprend une sortie ; une reprise refusée ou qui lève ne remonte pas.
 *
 * @param {AudioContext} contexte
 * @returns {Promise<void>}
 */
const reprendre = (contexte) => sansLever(() => contexte.resume())

/**
 * Construit et démarre un son sur une sortie qui tourne. Un son qui échoue ne
 * fait rien tomber : il part d'une minuterie, qui doit aller à son terme.
 *
 * @param {(contexte: AudioContext) => void} construire
 * @param {AudioContext} contexte
 */
const construireLeSon = (construire, contexte) => {
    try {
        construire(contexte)
    } catch (erreur) {
        console.warn('Le son n’a pas pu se jouer.', erreur)
    }
}

/**
 * La sortie audio d'une page (#2112) : une seule, ouverte ou reprise dans un
 * geste, sur laquelle les sons se jouent ensuite, depuis une minuterie, et
 * rendue au démontage du composant qui l'a demandée.
 *
 * WebKit ne démarre une sortie Web Audio que sous l'activation récente de la
 * page, c'est-à-dire pendant un geste : créée ou reprise hors geste (dans le
 * rappel d'une minuterie), elle reste `suspended`, et rien ne s'entend. Une
 * sortie démarrée une fois dans un geste reste ouverte au son : ce qu'on y
 * joue ensuite depuis une minuterie s'entend. Le minuteur de repos créait sa
 * sortie à la fin du repos, hors de tout geste, une par repos, jamais reprise
 * ni fermée : sur iPhone, la fin ne sonnait pas, et chaque repos laissait une
 * sortie ouverte jusqu'au rechargement.
 *
 * - `preparer()` s'appelle DANS le geste : il crée la sortie (`AudioContext`,
 *   ou `webkitAudioContext` seul), la reprend quand elle est `suspended` (dans
 *   WebKit, c'est l'état d'une sortie neuve, même créée dans un geste : elle
 *   ne démarre qu'à ce `resume()`) ou `interrupted` (l'état que WebKit donne à
 *   une sortie qu'un appel, Siri ou une autre application ont prise), et en
 *   recrée une quand la précédente est `closed`. Il ne lève jamais : un geste
 *   qui lance un repos ne doit pas tomber faute de son.
 * - `jouer(construire)` joue un son, hors geste comme dans un geste. Une sortie
 *   qui tourne le joue tout de suite. Une sortie arrêtée est reprise, et le son
 *   ne part que si elle tourne dans les `DELAI_DE_REPRISE_MS` : un son démarré
 *   sur une horloge arrêtée partirait à la reprise, au geste suivant, à
 *   contretemps. Sans sortie ouverte par un geste, rien : la créer ici, hors
 *   geste, est le défaut même.
 *
 * Le minuteur d'intervalles (`useMinuteurDIntervalles`) suit le même motif
 * avec sa propre sortie : la factoriser ici changerait son comportement (il
 * joue même sur une sortie arrêtée, et ne reprend pas `interrupted`).
 *
 * @returns {{
 *   preparer: () => AudioContext|null,
 *   jouer: (construire: (contexte: AudioContext) => void) => void,
 * }}
 */
export const useSortieAudio = () => {
    /** @type {AudioContext|null} */
    let sortie = null

    const preparer = () => {
        try {
            if (sortie === null || sortie.state === 'closed') {
                const Sortie = window.AudioContext ?? window.webkitAudioContext
                sortie = Sortie ? new Sortie() : null
            }
        } catch (erreur) {
            console.warn('La sortie audio n’a pas pu s’ouvrir.', erreur)
        }

        if (sortie !== null && ETATS_A_REPRENDRE.has(sortie.state)) {
            reprendre(sortie)
        }

        return sortie
    }

    const jouer = (construire) => {
        const contexte = sortie

        if (contexte === null || contexte.state === 'closed') {
            return
        }

        if (contexte.state === 'running') {
            construireLeSon(construire, contexte)

            return
        }

        const demande = Date.now()

        reprendre(contexte).then(() => {
            if (contexte.state === 'running' && Date.now() - demande <= DELAI_DE_REPRISE_MS) {
                construireLeSon(construire, contexte)
            }
        })
    }

    /**
     * Rend la sortie : une sortie laissée ouverte garde la sortie audio de
     * l'appareil éveillée. Un refus de fermer, rejet ou erreur, ne remonte pas
     * au démontage de la page.
     */
    const fermer = () => {
        const contexte = sortie
        sortie = null

        if (contexte !== null && contexte.state !== 'closed') {
            sansLever(() => contexte.close())
        }
    }

    if (getCurrentInstance()) {
        onUnmounted(fermer)
    }

    return { preparer, jouer }
}
