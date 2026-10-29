/**
 * Une sortie audio factice qui tient la règle de WebKit (#2112).
 *
 * Sur iOS, WebKit ne démarre une sortie Web Audio que sous l'activation
 * récente de la page, c'est-à-dire pendant un geste. Une sortie neuve est
 * `suspended`, même créée dans un geste : WebKit ne la démarre qu'à un
 * `resume()`, ou à un son qui y démarre, pendant un geste. Créée hors geste
 * (depuis le rappel d'une minuterie), elle le reste, et `resume()` appelé hors
 * geste ne la démarre pas ; sa promesse attend le geste suivant. Rien de ce qui
 * se joue sur une sortie arrêtée ne s'entend, et un son démarré sur son
 * horloge arrêtée part quand elle reprend : au geste suivant, à contretemps.
 *
 * L'état d'une sortie ne change jamais pendant l'appel qui la démarre. WebKit
 * le pose dans une tâche mise en file, puis résout la promesse de `resume()` ;
 * le faux le pose à la suite, dans une microtâche, puis résout la promesse.
 * Un code qui lit l'état juste après `resume()` y lit donc encore l'ancien,
 * comme sur l'appareil ; seul celui qui attend la promesse la voit tourner. Un
 * son démarré pendant un geste démarre sa sortie suspendue de même, à la
 * suite, et s'entend dès qu'elle tourne, sans partir à contretemps.
 *
 * Le geste se joue par `dansUnGeste(geste)` : l'activation ne vaut que pendant
 * la partie synchrone du geste, comme le traitement d'un évènement, et non
 * pour ce qu'il attend ensuite ; WebKit la garde quelques secondes.
 *
 * Sur ces deux points, l'activation et l'état qui ne change qu'à la résolution
 * de la reprise, le faux est aussi strict que WebKit ou davantage. Il ne l'est
 * pas en tout : sa mise en route arrive plus tôt que la tâche de WebKit, si
 * bien qu'un code qui lirait l'état après quelques tours de microtâches, au
 * lieu d'attendre la promesse de `resume()`, pourrait sonner ici et se taire
 * sur l'appareil ; et sa fermeture, que seule la page demande, est immédiate.
 * Seul un iPhone dit tout à fait ce qui s'entend.
 *
 * Chaque sortie créée se range dans `sorties`, et chaque son démarré dans les
 * `sons` de sa sortie : sa fréquence, s'il s'est entendu à son départ
 * (`audible` : sa sortie tournait, ou le geste qui l'a lancé l'a démarrée), et
 * s'il est parti plus tard, à la reprise d'une sortie arrêtée (`aContretemps`).
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
        const sortie = this.sortie
        const son = {
            frequence: this.frequency.value,
            relie: this.relie(),
            audible: sortie.state === 'running' && this.relie(),
            aContretemps: false,
            fin: null,
        }
        this.son = son
        sortie.sons.push(son)

        /*
         * Un son qui démarre pendant un geste démarre sa sortie suspendue
         * (`sourceNodeWillBeginPlayback` de WebKit), à la suite et non pendant
         * l'appel : il s'entend dès qu'elle tourne.
         */
        if (enGeste && sortie.state === 'suspended') {
            sortie.sonsQuiLaDemarrent.add(son)
            Promise.resolve().then(() => {
                if (sortie.state === 'suspended') {
                    sortie.demarrer()
                }

                sortie.sonsQuiLaDemarrent.delete(son)
            })
        }
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
        // Neuve, elle attend son démarrage, geste ou non : seuls `resume()` ou un son dans un geste la démarrent.
        this.state = 'suspended'
        this.creeeDansUnGeste = enGeste
        this.repriseHorsGeste = SortieAudioWebKit.repriseHorsGeste
        this.currentTime = 0
        this.destination = { nom: 'destination' }
        /** Chaque appel à `resume()`, et s'il venait d'un geste. */
        this.reprises = []
        this.fermetures = 0
        this.sons = []
        /** Les sons démarrés pendant un geste sur la sortie suspendue, qui la démarrent à la suite. */
        this.sonsQuiLaDemarrent = new Set()
        this.enAttenteDeReprise = []
        sorties.push(this)
    }

    /**
     * Comme WebKit, l'état ne change pas pendant l'appel : la sortie tourne à
     * la suite, juste avant que la promesse ne se résolve. Une sortie fermée
     * entre-temps reste fermée.
     */
    resume() {
        this.reprises.push({ dansUnGeste: enGeste, etat: this.state })

        if (this.state === 'closed') {
            return Promise.reject(new DOMException('La sortie est fermée.', 'InvalidStateError'))
        }

        if (!enGeste && !this.repriseHorsGeste) {
            return new Promise((resoudre) => this.enAttenteDeReprise.push(resoudre))
        }

        return Promise.resolve().then(() => {
            if (this.state !== 'closed') {
                this.demarrer()
            }
        })
    }

    /**
     * La sortie tourne : un son qui l'a démarrée depuis un geste s'entend, ce
     * qui attendait sa reprise part, à contretemps.
     */
    demarrer() {
        this.state = 'running'

        for (const son of this.sons) {
            if (son.audible || !son.relie) {
                continue
            }

            if (this.sonsQuiLaDemarrent.has(son)) {
                son.audible = true
            } else {
                son.aContretemps = true
            }
        }

        this.sonsQuiLaDemarrent.clear()
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
