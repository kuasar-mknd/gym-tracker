/**
 * La séance que l'écran doit montrer : celle que le serveur a rendue, et
 * par-dessus ce qui attend encore dans la file hors ligne.
 *
 * La page construisait sa copie locale depuis les seules props. Ce qui attendait
 * en file n'apparaissait qu'après le vidage : une série ajoutée et cochée sans
 * réseau disparaissait de l'écran au rechargement, et revenait sans elle au
 * bouton Retour puis Avance, qui rendent les props d'avant l'ajout (#2048). La
 * file, elle, gardait tout ; la personne croyait sa série perdue, et la refaisait.
 *
 * Tout ici est pur : la séance et les entrées reçues ne sont jamais modifiées.
 */

/**
 * Ce que porte l'identifiant d'une rangée superposée devant l'entrée de file
 * qui la crée. Une rangée superposée ne prend jamais un identifiant du compteur
 * de `useIdentiteDesRangees`, remis à zéro à chaque montage : `temp-3` désignait
 * une autre série à chaque fois, et un brouillon laissé sous ce nom aurait été
 * appliqué à la mauvaise.
 */
const PREFIXE_EN_FILE = 'temp-q'

/**
 * L'identifiant provisoire de la rangée que crée cette entrée de file.
 *
 * @param {string} queueId
 * @returns {string}
 */
export const identifiantEnFile = (queueId) => `${PREFIXE_EN_FILE}${queueId}`

/**
 * L'entrée de file qui crée cette rangée superposée, ou null pour toute autre
 * rangée : une rangée du serveur, ou une rangée ajoutée depuis le montage.
 *
 * @param {unknown} id
 * @returns {string|null}
 */
export const entreeDeLaRangee = (id) =>
    typeof id === 'string' && id.startsWith(PREFIXE_EN_FILE) && id.length > PREFIXE_EN_FILE.length
        ? id.slice(PREFIXE_EN_FILE.length)
        : null

/**
 * Les sept écritures de la séance, reconnues à leur méthode et à la fin de
 * leur adresse (`routes/api.php`). La file garde l'adresse que Ziggy a rendue,
 * absolue ou non : seul le chemin compte.
 */
const ECRITURES = [
    { sorte: 'creerUneLigne', methodes: ['post'], motif: /\/api\/v1\/workout-lines$/ },
    { sorte: 'retirerUneLigne', methodes: ['delete'], motif: /\/api\/v1\/workout-lines\/(\d+)$/ },
    { sorte: 'ordonnerLesSeries', methodes: ['patch'], motif: /\/api\/v1\/workout-lines\/(\d+)\/set-order$/ },
    { sorte: 'ordonnerLesLignes', methodes: ['patch'], motif: /\/api\/v1\/workouts\/(\d+)\/line-order$/ },
    { sorte: 'creerUneSerie', methodes: ['post'], motif: /\/api\/v1\/sets$/ },
    { sorte: 'modifierUneSerie', methodes: ['patch', 'put'], motif: /\/api\/v1\/sets\/(\d+)$/ },
    { sorte: 'retirerUneSerie', methodes: ['delete'], motif: /\/api\/v1\/sets\/(\d+)$/ },
]

/**
 * Ce qu'est cette entrée pour la séance, et l'identifiant que son adresse nomme.
 *
 * @param {object} entree
 * @returns {{sorte: string, cible: string|undefined}|null}
 */
const lireLEcriture = (entree) => {
    const methode = String(entree?.method ?? '').toLowerCase()
    const chemin = String(entree?.url ?? '').split(/[?#]/, 1)[0]

    for (const { sorte, methodes, motif } of ECRITURES) {
        const trouve = methodes.includes(methode) ? motif.exec(chemin) : null

        if (trouve !== null) {
            return { sorte, cible: trouve[1] }
        }
    }

    return null
}

/** La charge d'une entrée, qu'elle ait été mise en file en objet ou déjà sérialisée. */
const chargeDe = (entree) => {
    const data = entree?.data

    if (typeof data !== 'string') {
        return data !== null && typeof data === 'object' ? data : {}
    }

    try {
        const lue = JSON.parse(data)

        return lue !== null && typeof lue === 'object' ? lue : {}
    } catch {
        return {}
    }
}

/** Les champs d'une charge qui se recopient tels quels sur une rangée : ni objet, ni liste. */
const champsScalaires = (charge) =>
    Object.fromEntries(Object.entries(charge).filter(([, valeur]) => valeur === null || typeof valeur !== 'object'))

/** Deux identifiants qui désignent la même rangée : `42` et `'42'` en sont un. */
const memeIdentifiant = (a, b) => a !== undefined && a !== null && String(a) === String(b)

/**
 * L'identifiant de la ligne qu'une création de série nomme : l'identifiant réel,
 * ou, pour une ligne dont la création attend encore en file
 * (`{ enAttenteDe: queueId }`, #1962), celui de la ligne que cette création a
 * déjà produite sur le serveur, sinon celui de la ligne superposée.
 *
 * @param {unknown} valeur
 * @param {Map<string, number|string>} produites
 */
const ligneNommee = (valeur, produites) =>
    valeur !== null && typeof valeur === 'object' && typeof valeur.enAttenteDe === 'string'
        ? (produites.get(valeur.enAttenteDe) ?? identifiantEnFile(valeur.enAttenteDe))
        : valeur

/**
 * La clé d'idempotence d'une création, celle que le serveur garde sur ce
 * qu'elle a produit (`idempotency_key`), ou null.
 */
const cleDeLaCreation = (entree) => {
    const cle = entree?.headers?.['Idempotency-Key']

    return typeof cle === 'string' && cle !== '' ? cle : null
}

/**
 * Range les rangées dans l'ordre qu'une écriture a donné. Celles qu'il ne nomme
 * pas, ajoutées depuis, le suivent dans leur ordre d'avant.
 */
const ordonner = (rangees, ordre) => {
    if (!Array.isArray(ordre)) {
        return rangees
    }

    const rang = new Map(ordre.map((id, index) => [String(id), index]))

    return [...rangees].sort((a, b) => (rang.get(String(a.id)) ?? Infinity) - (rang.get(String(b.id)) ?? Infinity))
}

/**
 * Applique une entrée de la file à la séance, en place, si elle la concerne.
 *
 * @param {object} seance la copie en cours de construction
 * @param {object} entree
 * @param {{ exercices: Array<object>, produites: Map<string, number|string> }} contexte
 *   les exercices du compte, et les lignes que des créations encore en file ont
 *   déjà produites sur le serveur, par entrée
 */
const appliquer = (seance, entree, { exercices, produites }) => {
    const ecriture = lireLEcriture(entree)

    if (ecriture === null) {
        return
    }

    const charge = chargeDe(entree)
    const lignes = seance.workout_lines
    const ligneDe = (id) => lignes.find((ligne) => memeIdentifiant(ligne.id, id))
    const ligneDeLaSerie = (id) =>
        lignes.find((ligne) => Array.isArray(ligne.sets) && ligne.sets.some((serie) => memeIdentifiant(serie.id, id)))

    /*
     * Une création retirée de l'écran après avoir été tentée reste en file
     * pour être annulée sur le serveur (`aAnnuler`) : la personne l'a
     * supprimée, elle ne revient pas.
     */
    const retireeDeLEcran = entree.aAnnuler !== undefined

    /*
     * Une création partie sans réponse a pu atteindre le serveur : la séance
     * qu'il rend porte alors déjà ce qu'elle a produit, sous sa clé
     * d'idempotence, et le vidage la rejouera sans rien doubler. Elle ne se
     * superpose pas une seconde fois : la série se montrait deux fois, et
     * retirer l'une des deux supprimait au vidage celle du serveur.
     */
    const cle = cleDeLaCreation(entree)
    const dejaProduite = (rangee) => cle !== null && rangee?.idempotency_key === cle

    switch (ecriture.sorte) {
        case 'creerUneLigne': {
            const produite = lignes.find(dejaProduite)

            if (produite !== undefined) {
                if (retireeDeLEcran) {
                    seance.workout_lines = lignes.filter((ligne) => ligne !== produite)
                } else {
                    produites.set(entree.id, produite.id)
                }

                return
            }

            if (retireeDeLEcran || !memeIdentifiant(charge.workout_id, seance.id)) {
                return
            }

            const id = identifiantEnFile(entree.id)
            const exercice = exercices.find((candidat) => memeIdentifiant(candidat?.id, charge.exercise_id))

            lignes.push({
                id,
                _rowKey: id,
                exercise_id: charge.exercise_id,
                // Un exercice qui n'est plus dans la liste reste montré : sa création attend encore.
                exercise: exercice ? { ...exercice } : { id: charge.exercise_id, name: 'Exercice', type: 'strength' },
                sets: [],
                order: lignes.length,
                notes: null,
                recommended_values: null,
            })

            return
        }

        case 'creerUneSerie': {
            // La ligne est celle où la série se range, pas un champ de la série.
            const valeurs = Object.fromEntries(
                Object.entries(champsScalaires(charge)).filter(([champ]) => champ !== 'workout_line_id'),
            )
            const ligneDeLaProduite = lignes.find(
                (candidate) => Array.isArray(candidate.sets) && candidate.sets.some(dejaProduite),
            )

            if (ligneDeLaProduite !== undefined) {
                const produite = ligneDeLaProduite.sets.find(dejaProduite)

                if (retireeDeLEcran) {
                    ligneDeLaProduite.sets = ligneDeLaProduite.sets.filter((serie) => serie !== produite)
                } else {
                    // Ce qui s'est fondu dans l'entrée depuis : le vidage l'enverra en rattrapage (`ajusterPar`).
                    Object.assign(produite, valeurs)
                }

                return
            }

            const ligne = retireeDeLEcran ? undefined : ligneDe(ligneNommee(charge.workout_line_id, produites))

            if (ligne === undefined) {
                return
            }

            const id = identifiantEnFile(entree.id)

            if (!Array.isArray(ligne.sets)) {
                ligne.sets = []
            }

            ligne.sets.push({ id, _rowKey: id, is_completed: false, is_warmup: false, ...valeurs })

            return
        }

        case 'modifierUneSerie': {
            const serie = ligneDeLaSerie(ecriture.cible)?.sets.find((candidate) =>
                memeIdentifiant(candidate.id, ecriture.cible),
            )

            if (serie !== undefined) {
                Object.assign(serie, champsScalaires(charge))
            }

            return
        }

        case 'retirerUneSerie': {
            const ligne = ligneDeLaSerie(ecriture.cible)

            if (ligne !== undefined) {
                ligne.sets = ligne.sets.filter((serie) => !memeIdentifiant(serie.id, ecriture.cible))
            }

            return
        }

        case 'retirerUneLigne': {
            seance.workout_lines = lignes.filter((ligne) => !memeIdentifiant(ligne.id, ecriture.cible))

            return
        }

        case 'ordonnerLesSeries': {
            const ligne = ligneDe(ecriture.cible)

            if (ligne !== undefined && Array.isArray(ligne.sets)) {
                ligne.sets = ordonner(ligne.sets, charge.sets)
            }

            return
        }

        case 'ordonnerLesLignes': {
            if (memeIdentifiant(ecriture.cible, seance.id)) {
                seance.workout_lines = ordonner(lignes, charge.lines)
            }

            return
        }
    }
}

/**
 * La séance rendue par le serveur, avec par-dessus les écritures de la file qui
 * la concernent, dans l'ordre où elles ont été faites :
 *
 * - les créations de lignes et de séries, une série sous une ligne encore en
 *   file comprise (`{ enAttenteDe }`), avec la saisie et la coche qui s'y sont
 *   fondues ; chaque rangée prend `temp-q<queueId>` pour identifiant et pour clef ;
 * - une création déjà arrivée au serveur, sa réponse perdue, reconnue à sa clé
 *   d'idempotence sur la rangée qu'elle a produite : pas de seconde rangée, ses
 *   valeurs se posent sur celle-là ;
 * - les modifications et les suppressions de séries et de lignes ;
 * - les créations retirées de l'écran (`aAnnuler`), qui ne reviennent pas ;
 * - l'ordre des lignes et celui des séries de chaque ligne.
 *
 * Une entrée qui ne concerne pas cette séance (une autre séance, les préférences
 * de notification) est ignorée. Les lignes sortent toujours en tableau.
 *
 * @param {object} seance la séance telle que le serveur la rend
 * @param {Array<object>} entrees les entrées de la file du compte, dans l'ordre
 * @param {{ exercices?: Array<object> }} options les exercices du compte, d'où
 *   une ligne superposée tire le sien
 * @returns {object} une copie, la séance reçue n'est pas touchée
 */
export const superposerLaFile = (seance, entrees, { exercices = [] } = {}) => {
    const copie = JSON.parse(JSON.stringify(seance))

    if (!Array.isArray(copie.workout_lines)) {
        copie.workout_lines = Object.values(copie.workout_lines ?? {})
    }

    const contexte = { exercices: Array.isArray(exercices) ? exercices : [], produites: new Map() }

    for (const entree of Array.isArray(entrees) ? entrees : []) {
        appliquer(copie, entree, contexte)
    }

    return copie
}
