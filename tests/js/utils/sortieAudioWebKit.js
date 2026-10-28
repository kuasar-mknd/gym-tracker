/**
 * Une sortie audio factice qui tient la règle de WebKit (#2112).
 *
 * Sur iOS, WebKit ne démarre une sortie Web Audio que sous l'activation
 * récente de la page, c'est-à-dire pendant un geste : créée hors geste (depuis
 * le rappel d'une minuterie), elle reste `suspended`, et `resume()` appelé hors
 * geste ne la démarre pas ; sa promesse attend le geste suivant. Rien de ce qui
 * se joue sur une sortie arrêtée ne s'entend, et un son démarré sur son
 * horloge arrêtée part quand elle reprend : au geste suivant, à contretemps.
 *
 * Le geste se joue par `dansUnGeste(geste)` : l'activation ne vaut que pendant
 * la partie synchrone du geste, comme le traitement d'un évènement, et non
 * pour ce qu'il attend ensuite. C'est plus strict que WebKit, qui la garde
 * quelques secondes : ce qui sonne ici sonne a fortiori sur un iPhone.
 *
 * Chaque sortie créée se range dans `sorties`, et chaque son démarré dans les
 * `sons` de sa sortie : sa fréquence, s'il s'est entendu à son départ
 * (`audible`), et s'il est parti plus tard, à la reprise d'une sortie arrêtée
 * (`aContretemps`).
 */

let enGeste = false

/** Les sorties créées depuis l'installation, dans l'ordre. */
export const sorties = []

/**
 * Joue un geste : ce que `geste` fait de façon synchrone se passe sous
 * l'activation de la page, la suite (ce qu'il rend, attendu) hors d'elle.
 *
 * @template T
 * @param {() => T} geste
 * @returns {Promise<Awaited<T>>}
 */
export const dansUnGeste = async (geste) => {
    enGeste = true
    let suite

    try {
        suite = geste()
    } finally {
        enGeste = false
    }

    return await suite
}

/** Un nœud qui retient ce à quoi il est relié. */
class Noeud {
    constructor(sortie) {
        this.sortie = sortie
        this.cible = null
    }

    connect(cible) {
        this.cible = cible

        return cible
    }

    /** Ce nœud mène-t-il à la destination de sa sortie ? */
    relie() {
        let noeud = this

        while (noeud instanceof Noeud) {
            noeud = noeud.cible
        }

        return noeud === this.sortie.destination
    }
}

/** Un paramètre audio : sa valeur, et ce qu'on lui a programmé. */
class Parametre {
    constructor(valeur) {
        this.value = valeur
        this.programme = []
    }

    setValueAtTime(valeur, quand) {
        this.value = valeur
        this.programme.push({ valeur, quand })
    }

    exponentialRampToValueAtTime(valeur, quand) {
        this.programme.push({ rampe: valeur, quand })
    }
}

class Oscillateur extends Noeud {
    constructor(sortie) {
        super(sortie)
        this.type = 'sine'
        this.frequency = new Parametre(440)
        this.son = null
    }

    start() {
        this.son = {
            frequence: this.frequency.value,
            relie: this.relie(),
            audible: this.sortie.state === 'running' && this.relie(),
            aContretemps: false,
            fin: null,
        }
        this.sortie.sons.push(this.son)
    }

    stop(quand) {
        this.son.fin = quand
    }
}

class Gain extends Noeud {
    constructor(sortie) {
        super(sortie)
        this.gain = new Parametre(1)
    }
}

/**
 * La sortie factice : `AudioContext`, ou `webkitAudioContext` seul selon
 * l'installation.
 */
export class SortieAudioWebKit {
    /**
     * Le navigateur reprend une sortie hors geste (Chrome, Firefox après une
     * première interaction) : à poser avant de créer la sortie. WebKit, non.
     */
    static repriseHorsGeste = false

    constructor() {
        this.state = enGeste ? 'running' : 'suspended'
        this.creeeDansUnGeste = enGeste
        this.repriseHorsGeste = SortieAudioWebKit.repriseHorsGeste
        this.currentTime = 0
        this.destination = { nom: 'destination' }
        /** Chaque appel à `resume()`, et s'il venait d'un geste. */
        this.reprises = []
        this.fermetures = 0
        this.sons = []
        this.enAttenteDeReprise = []
        sorties.push(this)
    }

    resume() {
        this.reprises.push({ dansUnGeste: enGeste, etat: this.state })

        if (this.state === 'closed') {
            return Promise.reject(new DOMException('La sortie est fermée.', 'InvalidStateError'))
        }

        if (!enGeste && !this.repriseHorsGeste) {
            return new Promise((resoudre) => this.enAttenteDeReprise.push(resoudre))
        }

        this.demarrer()

        return Promise.resolve()
    }

    /** La sortie tourne : ce qui attendait sa reprise part, à contretemps. */
    demarrer() {
        this.state = 'running'

        for (const son of this.sons) {
            if (!son.audible && son.relie) {
                son.aContretemps = true
            }
        }

        this.enAttenteDeReprise.splice(0).forEach((resoudre) => resoudre())
    }

    /** Ce que fait iOS d'une sortie quand un appel ou Siri prend le son. */
    interrompre() {
        this.state = 'interrupted'
    }

    close() {
        this.fermetures += 1
        this.state = 'closed'

        return Promise.resolve()
    }

    createOscillator() {
        return new Oscillateur(this)
    }

    createGain() {
        return new Gain(this)
    }

    /** Les sons entendus à leur départ. */
    get sonsAudibles() {
        return this.sons.filter((son) => son.audible)
    }
}

/**
 * Pose la sortie factice sur `window`, sous `AudioContext`, ou sous
 * `webkitAudioContext` seul (`prefixee`), comme un Safari ancien.
 *
 * @param {{ prefixee?: boolean }} [options]
 */
export const installerLaSortieWebKit = ({ prefixee = false } = {}) => {
    sorties.length = 0
    enGeste = false
    SortieAudioWebKit.repriseHorsGeste = false
    delete window.AudioContext
    delete window.webkitAudioContext

    if (prefixee) {
        window.webkitAudioContext = SortieAudioWebKit
    } else {
        window.AudioContext = SortieAudioWebKit
    }
}

/** Retire la sortie factice, et oublie les sorties créées. */
export const retirerLaSortieWebKit = () => {
    delete window.AudioContext
    delete window.webkitAudioContext
    sorties.length = 0
    enGeste = false
    SortieAudioWebKit.repriseHorsGeste = false
}

/** Tous les sons entendus à leur départ, toutes sorties confondues. */
export const sonsAudibles = () => sorties.flatMap((sortie) => sortie.sonsAudibles)
