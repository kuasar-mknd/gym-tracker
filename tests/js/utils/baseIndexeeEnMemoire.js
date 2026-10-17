/**
 * Une fabrique IndexedDB en mémoire, pour les tests du magasin des copies
 * hors ligne (#2048).
 *
 * jsdom n'a pas IndexedDB, et la décision 10 du propriétaire n'admet aucune
 * dépendance nouvelle (ni `fake-indexeddb`, ni `idb`). Elle ne fait que ce
 * dont `resources/js/Utils/copiesHorsLigne.js` se sert : `open` et sa mise à
 * niveau, `objectStoreNames.contains`, `createObjectStore` (clé dans
 * l'enregistrement ou à part), `transaction`, et sur un magasin `get`, `put`,
 * `delete`, `clear` et `getAll`. Elle tient les trois règles d'IndexedDB dont
 * la barrière de la purge dépend, pour qu'un test tombe là où un navigateur
 * tomberait :
 *
 * - une transaction est tout ou rien : annulée, rien de ce qu'elle a écrit ne
 *   reste ;
 * - les transactions d'une base passent l'une après l'autre, dans l'ordre où
 *   elles ont été créées ;
 * - une transaction n'accepte de requête que pendant la tâche qui l'a créée
 *   et pendant le rappel d'une de ses requêtes, microtâches comprises. Une
 *   requête faite après avoir attendu autre chose (le chiffrement, une
 *   minuterie) lève `TransactionInactiveError`, et la transaction se valide
 *   dès qu'elle n'a plus rien à faire.
 *
 * Chaque valeur rangée ou lue est recopiée (`structuredClone`), comme
 * IndexedDB la recopie. `bases` montre ce qui est rangé, pour qu'un test le
 * lise ou le falsifie.
 */

/** Une tâche plus tard : après les microtâches en attente. */
const uneTache = (rappel) => setTimeout(rappel, 0)

class DemandeSimulee {
    constructor() {
        this.result = undefined
        this.error = null
        this.readyState = 'pending'
        this.onsuccess = null
        this.onerror = null
        this.onupgradeneeded = null
        this.onblocked = null
    }

    reussir(resultat) {
        this.result = resultat
        this.readyState = 'done'
        this.onsuccess?.({ type: 'success', target: this })
    }

    echouer(erreur) {
        this.error = erreur
        this.readyState = 'done'
        this.onerror?.({ type: 'error', target: this, preventDefault() {} })
    }
}

class MagasinSimule {
    constructor(transaction, nom) {
        this.transaction = transaction
        this.nom = nom
    }

    get donnees() {
        return this.transaction.base.magasins.get(this.nom)
    }

    get(cle) {
        return this.transaction.requete(() => structuredClone(this.donnees.enregistrements.get(cle)))
    }

    getAll() {
        return this.transaction.requete(() =>
            [...this.donnees.enregistrements.entries()]
                .sort(([premiere], [seconde]) => (premiere < seconde ? -1 : 1))
                .map(([, valeur]) => structuredClone(valeur)),
        )
    }

    put(valeur, cle) {
        this.transaction.exigerLEcriture()

        const { cheminDeLaCle } = this.donnees
        const cleRangee = cheminDeLaCle === null ? cle : valeur?.[cheminDeLaCle]

        if (cleRangee === undefined || (cheminDeLaCle !== null && cle !== undefined)) {
            throw new DOMException('Clé absente ou donnée deux fois', 'DataError')
        }

        const copie = structuredClone(valeur)

        return this.transaction.requete(() => {
            this.donnees.enregistrements.set(cleRangee, copie)

            return cleRangee
        })
    }

    delete(cle) {
        this.transaction.exigerLEcriture()

        return this.transaction.requete(() => {
            this.donnees.enregistrements.delete(cle)
        })
    }

    clear() {
        this.transaction.exigerLEcriture()

        return this.transaction.requete(() => {
            this.donnees.enregistrements.clear()
        })
    }
}

class TransactionSimulee {
    constructor(base, noms, mode) {
        this.base = base
        this.noms = noms
        this.mode = mode
        this.error = null
        this.oncomplete = null
        this.onabort = null
        this.onerror = null
        this.active = true
        this.finie = false
        this.demarree = false
        this.pompeEnCours = false
        this.operations = []
        this.instantane = null

        const precedente = base.file

        base.file = new Promise((liberer) => {
            this.liberer = liberer
        })
        precedente.then(() => this.demarrer())
        uneTache(() => this.rendreLaMain())
    }

    objectStore(nom) {
        if (!this.noms.includes(nom)) {
            throw new DOMException(`Le magasin ${nom} n’est pas dans la transaction`, 'NotFoundError')
        }

        return new MagasinSimule(this, nom)
    }

    exigerLEcriture() {
        if (this.mode !== 'readwrite') {
            throw new DOMException('Transaction en lecture seule', 'ReadOnlyError')
        }
    }

    requete(operation) {
        if (!this.active || this.finie) {
            throw new DOMException('La transaction n’accepte plus de requête', 'TransactionInactiveError')
        }

        const demande = new DemandeSimulee()

        this.operations.push({ operation, demande })
        this.pomper()

        return demande
    }

    demarrer() {
        if (this.finie) {
            this.liberer()

            return
        }

        this.demarree = true
        this.instantane = new Map(
            this.noms.map((nom) => [nom, structuredClone(this.base.magasins.get(nom).enregistrements)]),
        )
        this.pomper()
        this.peutEtreValider()
    }

    pomper() {
        if (!this.demarree || this.pompeEnCours || this.finie || this.operations.length === 0) {
            return
        }

        this.pompeEnCours = true

        uneTache(() => {
            this.pompeEnCours = false

            if (this.finie) {
                return
            }

            const { operation, demande } = this.operations.shift()
            let resultat
            let erreur = null

            try {
                resultat = operation()
            } catch (exception) {
                erreur = exception
            }

            this.active = true

            if (erreur === null) {
                demande.reussir(resultat)
            } else {
                demande.echouer(erreur)
                this.annuler(erreur)
            }

            uneTache(() => this.rendreLaMain())
        })
    }

    rendreLaMain() {
        this.active = false
        this.pomper()
        this.peutEtreValider()
    }

    peutEtreValider() {
        if (!this.demarree || this.finie || this.active || this.pompeEnCours || this.operations.length > 0) {
            return
        }

        this.finie = true
        this.liberer()
        uneTache(() => this.oncomplete?.({ type: 'complete', target: this }))
    }

    abort() {
        if (this.finie) {
            throw new DOMException('Transaction déjà finie', 'InvalidStateError')
        }

        this.annuler(new DOMException('Transaction annulée', 'AbortError'))
    }

    annuler(erreur) {
        if (this.finie) {
            return
        }

        this.finie = true
        this.error = erreur

        if (this.instantane !== null) {
            for (const [nom, enregistrements] of this.instantane) {
                this.base.magasins.get(nom).enregistrements = enregistrements
            }
        }

        if (this.demarree) {
            this.liberer()
        }

        uneTache(() => this.onabort?.({ type: 'abort', target: this }))
    }
}

class ConnexionSimulee {
    constructor(base) {
        this.base = base
        this.fermee = false
        this.enMiseANiveau = false
        this.onversionchange = null
    }

    get objectStoreNames() {
        const noms = [...this.base.magasins.keys()]

        return { contains: (nom) => noms.includes(nom), length: noms.length }
    }

    createObjectStore(nom, { keyPath = null } = {}) {
        if (!this.enMiseANiveau) {
            throw new DOMException('Hors d’une mise à niveau', 'InvalidStateError')
        }

        this.base.magasins.set(nom, { cheminDeLaCle: keyPath, enregistrements: new Map() })
    }

    transaction(noms, mode = 'readonly') {
        if (this.fermee) {
            throw new DOMException('Connexion fermée', 'InvalidStateError')
        }

        const liste = typeof noms === 'string' ? [noms] : [...noms]

        for (const nom of liste) {
            if (!this.base.magasins.has(nom)) {
                throw new DOMException(`Le magasin ${nom} n’existe pas`, 'NotFoundError')
            }
        }

        return new TransactionSimulee(this.base, liste, mode)
    }

    close() {
        this.fermee = true
    }
}

/**
 * Une fabrique neuve, sans aucune base : un appareil sur lequel rien n'a
 * encore été rangé. Les onglets d'un même appareil partagent la même.
 *
 * @returns {{
 *   open: (nom: string, version?: number) => DemandeSimulee,
 *   bases: Map<string, { version: number, magasins: Map<string, { cheminDeLaCle: string | null, enregistrements: Map<unknown, unknown> }> }>,
 *   ouvertures: () => number,
 *   enregistrements: (base: string, magasin: string) => Map<unknown, unknown>,
 *   auRepos: () => Promise<void>,
 * }}
 */
export const creerUneFabriqueEnMemoire = () => {
    const bases = new Map()
    let ouvertures = 0

    return {
        open(nom, version = 1) {
            const demande = new DemandeSimulee()

            ouvertures += 1

            uneTache(() => {
                if (!bases.has(nom)) {
                    bases.set(nom, { version: 0, magasins: new Map(), file: Promise.resolve() })
                }

                const base = bases.get(nom)

                if (version < base.version) {
                    demande.echouer(new DOMException('Version plus ancienne que la base', 'VersionError'))

                    return
                }

                const connexion = new ConnexionSimulee(base)

                demande.result = connexion

                if (version > base.version) {
                    const ancienne = base.version

                    base.version = version
                    connexion.enMiseANiveau = true
                    demande.onupgradeneeded?.({ oldVersion: ancienne, newVersion: version, target: demande })
                    connexion.enMiseANiveau = false
                }

                demande.reussir(connexion)
            })

            return demande
        },
        bases,
        ouvertures: () => ouvertures,
        enregistrements: (base, magasin) => bases.get(base)?.magasins.get(magasin)?.enregistrements ?? new Map(),
        auRepos: async () => {
            await Promise.all([...bases.values()].map((base) => base.file))
            await new Promise(uneTache)
        },
    }
}
