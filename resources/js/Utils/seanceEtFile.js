/**
 * La séance que l'écran doit montrer : celle que le serveur a rendue, et
 * par-dessus ce qui attend encore dans la file hors ligne.
 *
 * La page construisait sa copie locale depuis les seules props. Ce qui attendait
 * en file n'apparaissait qu'après le vidage : une série ajoutée et cochée sans
 * réseau disparaissait de l'écran au rechargement, et revenait sans elle au
 * bouton Retour puis Avance, qui rendent les props de la visite (#2048). La
 * file, elle, gardait tout ; la personne croyait sa série perdue, et la refaisait.
 *
 * Tout ici est pur : la séance et les entrées reçues ne sont jamais modifiées.
 */

import { isTemporaryId } from '@/Utils/pendingIds'

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
 * La création d'une séance (#2049) : `POST /api/v1/workouts`, celle que la file
 * rejoue pour une séance démarrée sans réseau. Elle n'est pas l'une des sept :
 * elle ne nomme aucune séance, elle en fait naître une, et ne se note pas comme
 * une écriture acceptée de la séance que la page suivait avant.
 */
const CREATION_DE_SEANCE = { sorte: 'creerUneSeance', methodes: ['post'], motif: /\/api\/v1\/workouts$/ }

/**
 * La fin d'une séance (#2049) : `PATCH /api/v1/workouts/finish`, la séance
 * nommée dans la charge (`workout_id`), l'heure du geste sur l'appareil
 * (`ended_at`). Elle n'est pas l'une des sept non plus : c'est la dernière
 * écriture de sa séance, celle derrière laquelle aucune ne doit passer
 * (`SyncService.ajouterALaFile`).
 */
const FIN_DE_SEANCE = { sorte: 'terminerLaSeance', methodes: ['patch'], motif: /\/api\/v1\/workouts\/finish$/ }

/**
 * Ce qu'est cette entrée pour la séance, et l'identifiant que son adresse nomme.
 *
 * @param {object} entree
 * @param {Array<{sorte: string, methodes: string[], motif: RegExp}>} sortes
 * @returns {{sorte: string, cible: string|undefined}|null}
 */
const lireLEcriture = (entree, sortes = [...ECRITURES, CREATION_DE_SEANCE, FIN_DE_SEANCE]) => {
    const methode = String(entree?.method ?? '').toLowerCase()
    const chemin = String(entree?.url ?? '').split(/[?#]/, 1)[0]

    for (const { sorte, methodes, motif } of sortes) {
        const trouve = methodes.includes(methode) ? motif.exec(chemin) : null

        if (trouve !== null) {
            return { sorte, cible: trouve[1] }
        }
    }

    return null
}

/**
 * Si une écriture (une entrée de file, ou la requête d'une écriture directe)
 * est l'une des sept de la séance : ce que la copie hors ligne de la séance
 * suit (#2048, `Utils/ecrituresAcceptees.js`).
 *
 * @param {{ method?: string, url?: string } | null | undefined} ecriture
 * @returns {boolean}
 */
export const estUneEcritureDeLaSeance = (ecriture) => lireLEcriture(ecriture, ECRITURES) !== null

/**
 * Si une écriture est la création d'une séance (#2049) : `POST /api/v1/workouts`.
 *
 * @param {{ method?: string, url?: string } | null | undefined} ecriture
 * @returns {boolean}
 */
export const estUneCreationDeSeance = (ecriture) => lireLEcriture(ecriture, [CREATION_DE_SEANCE]) !== null

/**
 * Si une écriture est la fin d'une séance (#2049) : `PATCH /api/v1/workouts/finish`.
 *
 * @param {{ method?: string, url?: string } | null | undefined} ecriture
 * @returns {boolean}
 */
export const estUneFinDeSeance = (ecriture) => lireLEcriture(ecriture, [FIN_DE_SEANCE]) !== null

/**
 * Si une adresse est celle d'une des sept écritures de la séance, quelle que
 * soit la méthode : chacune de ces adresses ne sert qu'à elles. Ce que lit un
 * écouteur de `sync:replayed`, qui ne porte que l'adresse.
 *
 * @param {unknown} url
 * @returns {boolean}
 */
export const estUneAdresseDEcritureDeLaSeance = (url) => {
    const chemin = String(url ?? '').split(/[?#]/, 1)[0]

    return ECRITURES.some(({ motif }) => motif.test(chemin))
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
 *
 * @param {object|null|undefined} entree
 * @returns {string|null}
 */
export const cleDeLaCreation = (entree) => {
    const cle = entree?.headers?.['Idempotency-Key']

    return typeof cle === 'string' && cle !== '' ? cle : null
}

/**
 * Si une valeur de charge nomme cette séance : son identifiant, ou, pour une
 * séance démarrée sans réseau dont la création attend encore en file
 * (`{ enAttenteDe: queueId }`, #2049), la séance qui en tient lieu.
 *
 * Deux séances la portent. Celle que la page construit depuis l'entrée de sa
 * création, sous `temp-q<queueId>` (`Utils/seanceEnAttente.js`). Et celle du
 * serveur dont la clé d'idempotence est celle de cette création : partie sans
 * réponse, elle l'a créée là-bas, et le rejeu la rendra telle quelle.
 *
 * @param {unknown} valeur `workout_id` d'une charge, ou d'un contexte
 * @param {object} seance
 * @param {Map<string, string|null>} seancesEnFile la clé de chaque création de séance en file
 * @returns {boolean}
 */
const nommeLaSeance = (valeur, seance, seancesEnFile) => {
    if (valeur === null || typeof valeur !== 'object') {
        return memeIdentifiant(valeur, seance.id)
    }

    if (typeof valeur.enAttenteDe !== 'string') {
        return false
    }

    if (memeIdentifiant(identifiantEnFile(valeur.enAttenteDe), seance.id)) {
        return true
    }

    const cle = seancesEnFile.get(valeur.enAttenteDe)

    return typeof cle === 'string' && cle === seance.idempotency_key
}

/**
 * La clé de chaque création de séance en file que l'écran n'a pas retirée,
 * par entrée : ce par quoi une écriture nomme une séance démarrée sans réseau
 * (#2049).
 *
 * @param {Array<object>} entrees
 * @returns {Map<string, string|null>}
 */
const seancesEnFileDe = (entrees) =>
    new Map(
        entrees
            .filter((entree) => estUneCreationDeSeance(entree) && entree.aAnnuler === undefined)
            .map((entree) => [entree.id, cleDeLaCreation(entree)]),
    )

/**
 * La fin de cette séance qui attend en file (#2049), ou undefined : la
 * première qui la nomme, par son identifiant, par l'entrée de sa création
 * (`{ enAttenteDe }`), ou par la clé de celle-ci. La première, parce que le
 * serveur garde la première fin d'une séance et rend les suivantes sans rien
 * changer.
 *
 * @param {{ id: unknown, idempotency_key?: string|null }} seance
 * @param {Array<object>} entrees les entrées de la file du compte, dans l'ordre
 * @returns {object|undefined}
 */
export const finDeLaSeanceEnFile = (seance, entrees) => {
    const lues = Array.isArray(entrees) ? entrees : []
    const seancesEnFile = seancesEnFileDe(lues)

    return lues.find(
        (entree) => estUneFinDeSeance(entree) && nommeLaSeance(chargeDe(entree).workout_id, seance, seancesEnFile),
    )
}

/**
 * La séance qu'une valeur nomme, sous une forme qui se compare : `id:42` pour
 * un identifiant du serveur, `q:<queueId>` pour une séance démarrée sans
 * réseau, qu'elle soit nommée par l'entrée de sa création (`{ enAttenteDe }`)
 * ou par l'identifiant provisoire qui la montre (`temp-q<queueId>`) ; null
 * pour rien de cela.
 *
 * @param {unknown} valeur
 * @returns {string|null}
 */
const seanceReperee = (valeur) => {
    if (valeur !== null && typeof valeur === 'object') {
        return typeof valeur.enAttenteDe === 'string' ? `q:${valeur.enAttenteDe}` : null
    }

    if (valeur === null || valeur === undefined || valeur === '') {
        return null
    }

    const enFile = entreeDeLaRangee(valeur)

    if (enFile !== null) {
        return `q:${enFile}`
    }

    return isTemporaryId(valeur) ? null : `id:${String(valeur)}`
}

/**
 * La séance que vise une écriture de la séance ou sa fin (#2049), sous la
 * forme de `seanceReperee`, ou null.
 *
 * La charge le dit pour la création d'une ligne et pour la fin
 * (`workout_id`), le contexte pour la création d'une série, l'adresse pour
 * l'ordre des lignes. Pour une modification, une suppression ou l'ordre des
 * séries, seule la séance que la page suivait au départ de l'écriture le dit
 * (`SyncService.suivreLesEcrituresDeLaSeance`).
 *
 * @param {object} ecriture une entrée de file, ou la requête d'une écriture directe
 * @param {number|string|null} seanceDuDepart la séance que la page suivait au départ
 * @returns {string|null}
 */
export const seanceDeLEcriture = (ecriture, seanceDuDepart = null) => {
    const lue = lireLEcriture(ecriture)

    if (lue === null || lue.sorte === 'creerUneSeance') {
        return null
    }

    const charge = chargeDe(ecriture)
    const nommee = {
        ordonnerLesLignes: lue.cible,
        creerUneLigne: charge.workout_id,
        creerUneSerie: ecriture?.contexte?.workout_id,
        terminerLaSeance: charge.workout_id,
    }[lue.sorte]

    return seanceReperee(nommee ?? seanceDuDepart)
}

/**
 * Une ligne que la page montre sans que la séance reçue la porte : celle qu'une
 * création encore en file ajoute, ou celle qu'une série en file nomme.
 *
 * @param {number|string} id
 * @param {number|string} exerciseId
 * @param {number} ordre
 * @param {Array<object>} exercices les exercices du compte
 */
const ligneMontree = (id, exerciseId, ordre, exercices) => {
    const exercice = exercices.find((candidat) => memeIdentifiant(candidat?.id, exerciseId))

    return {
        id,
        exercise_id: exerciseId,
        // Un exercice qui n'est plus dans la liste reste montré : son écriture attend encore.
        exercise: exercice ? { ...exercice } : { id: exerciseId, name: 'Exercice', type: 'strength' },
        sets: [],
        order: ordre,
        notes: null,
        recommended_values: null,
    }
}

/**
 * La ligne qu'une création de série en file nomme sans que la séance la porte,
 * reconstruite d'après ce que la page a noté sur l'entrée (`contexte`), quand
 * c'est une ligne réelle de cette séance-ci ; sinon undefined.
 *
 * La séance de départ peut dater d'avant la ligne : l'entrée d'historique
 * d'une visite plus ancienne de la même séance (la séance, la liste, puis la
 * séance rouverte) n'a pas l'exercice ajouté en ligne depuis. La série en file
 * sous cet exercice était écartée sans bruit, et disparaissait avec lui alors
 * que la file la gardait : la personne la refaisait, et le vidage en créait
 * une seconde (#2048). Une entrée sans contexte, d'une version précédente,
 * reste écartée : rien ne dit de quelle séance elle est.
 *
 * @param {object} seance
 * @param {object} entree
 * @param {unknown} ligneId l'identifiant que nomme la série
 * @param {Array<object>} exercices
 * @param {Map<string, string|null>} seancesEnFile la clé de chaque création de séance en file
 * @returns {object|undefined}
 */
const ligneReconstruite = (seance, entree, ligneId, exercices, seancesEnFile) => {
    const contexte = entree?.contexte

    if (
        contexte === null ||
        typeof contexte !== 'object' ||
        !nommeLaSeance(contexte.workout_id, seance, seancesEnFile) ||
        contexte.exercise_id === undefined ||
        ligneId === undefined ||
        ligneId === null ||
        typeof ligneId === 'object' ||
        isTemporaryId(ligneId)
    ) {
        return undefined
    }

    const ligne = ligneMontree(ligneId, contexte.exercise_id, seance.workout_lines.length, exercices)

    seance.workout_lines.push(ligne)

    return ligne
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
 * @param {{ exercices: Array<object>, produites: Map<string, number|string>, seancesEnFile: Map<string, string|null> }} contexte
 *   les exercices du compte, les lignes que des créations encore en file ont
 *   déjà produites sur le serveur, par entrée, et la clé de chaque création
 *   de séance en file
 */
const appliquer = (seance, entree, { exercices, produites, seancesEnFile }) => {
    const ecriture = lireLEcriture(entree)

    // La création d'une séance ne change rien à la séance : c'est elle que la page montre, ou une autre.
    if (ecriture === null || ecriture.sorte === 'creerUneSeance') {
        return
    }

    const charge = chargeDe(entree)

    /*
     * La fin de la séance (#2049) : close à l'heure du geste, en attente de
     * la file. Une séance que le serveur dit close, ou qu'une fin plus
     * ancienne de la file clôt déjà, garde sa fin : le serveur garde la
     * première, et rend les suivantes sans rien changer.
     */
    if (ecriture.sorte === 'terminerLaSeance') {
        if (!seance.ended_at && nommeLaSeance(charge.workout_id, seance, seancesEnFile)) {
            seance.ended_at = typeof charge.ended_at === 'string' ? charge.ended_at : entree.timestamp
            seance._finEnAttente = entree.id
        }

        return
    }

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

            if (retireeDeLEcran || !nommeLaSeance(charge.workout_id, seance, seancesEnFile)) {
                return
            }

            const id = identifiantEnFile(entree.id)

            lignes.push({ ...ligneMontree(id, charge.exercise_id, lignes.length, exercices), _rowKey: id })

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

            if (retireeDeLEcran) {
                return
            }

            const ligneId = ligneNommee(charge.workout_line_id, produites)
            const ligne = ligneDe(ligneId) ?? ligneReconstruite(seance, entree, ligneId, exercices, seancesEnFile)

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
 * - une série sous une ligne réelle que la séance reçue ne porte pas encore :
 *   la ligne est reconstruite d'après le contexte de l'entrée (`ligneReconstruite`) ;
 * - une création déjà arrivée au serveur, sa réponse perdue, reconnue à sa clé
 *   d'idempotence sur la rangée qu'elle a produite : pas de seconde rangée, ses
 *   valeurs se posent sur celle-là ;
 * - les modifications et les suppressions de séries et de lignes ;
 * - les créations retirées de l'écran (`aAnnuler`), qui ne reviennent pas ;
 * - l'ordre des lignes et celui des séries de chaque ligne ;
 * - la fin de la séance (#2049) : `ended_at`, l'heure du geste, et
 *   `_finEnAttente`, l'entrée de file qui la porte, tant que le serveur ne
 *   la connaît pas.
 *
 * Une séance démarrée sans réseau attend elle-même en file (#2049) : ses lignes
 * la nomment par `{ enAttenteDe }`, et se superposent à la séance que la page
 * construit depuis l'entrée de sa création (`temp-q<queueId>`), ou à celle du
 * serveur qui porte la clé de cette création, une seule fois.
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

    const lues = Array.isArray(entrees) ? entrees : []
    const contexte = {
        exercices: Array.isArray(exercices) ? exercices : [],
        produites: new Map(),
        // Une séance démarrée sans réseau : ses écritures la nomment par l'entrée de sa création (#2049).
        seancesEnFile: seancesEnFileDe(lues),
    }

    for (const entree of lues) {
        appliquer(copie, entree, contexte)
    }

    return copie
}

/** Une rangée sans ce que l'écran y ajoute : les champs `_*`, comme `_rowKey`. */
const sansLesChampsDeLEcran = (rangee) =>
    Object.fromEntries(Object.entries(rangee ?? {}).filter(([champ]) => !champ.startsWith('_')))

/**
 * La séance à l'écran, sans ce que le serveur ne connaît pas encore : ce que
 * l'entrée d'historique de la page garde pour les boutons Retour et Avance.
 *
 * Les rangées provisoires sortent : leur création attend en file, et la
 * superposition les rend au retour sous `temp-q<queueId>`, ou elle vole, et
 * elle n'a pas encore d'identifiant à garder. Les champs `_*` sortent aussi :
 * `_rowKey` vient du compteur du montage qui l'a donné, et le montage suivant,
 * qui repart de zéro, donnerait la même clef à une autre rangée.
 *
 * Un champ d'une série réelle dont l'écriture attend le debounce ou sa réponse
 * garde sa valeur de repli, celle que la page rétablit si le serveur refuse
 * (`useBrouillonsDeSeries`). Gardée telle qu'à l'écran, une valeur refusée
 * après le départ revenait au bouton Avance comme enregistrée, sans marque, et
 * la page la relevait comme confirmée. La saisie en cours, elle, revient par
 * son brouillon tant que sa réponse n'est pas arrivée, ou par la file.
 *
 * Une fin qui attend la file (`_finEnAttente`, #2049) sort aussi : la séance
 * gardée est ouverte, comme le serveur la connaît, et la superposition la
 * clôt de nouveau au retour, tant que la fin attend.
 *
 * @param {object} seance la copie locale de la page
 * @param {Array<{id: unknown, champ: string, valeur: unknown}>} valeursDeRepli
 *   les champs dont une écriture attend encore, et leur valeur de repli
 * @returns {object} une copie, la séance reçue n'est pas touchée
 */
export const seanceConnueDuServeur = (seance, valeursDeRepli = []) => {
    const copie = JSON.parse(JSON.stringify(seance))

    if (copie._finEnAttente !== undefined) {
        copie.ended_at = null
        delete copie._finEnAttente
    }

    const repliDe = (serie) =>
        Object.fromEntries(
            (Array.isArray(valeursDeRepli) ? valeursDeRepli : [])
                .filter(({ id, champ }) => memeIdentifiant(id, serie.id) && typeof champ === 'string')
                .map(({ champ, valeur }) => [champ, valeur]),
        )

    copie.workout_lines = Object.values(copie.workout_lines ?? {})
        .filter((ligne) => ligne !== null && !isTemporaryId(ligne.id))
        .map((ligne) => ({
            ...sansLesChampsDeLEcran(ligne),
            sets: (Array.isArray(ligne.sets) ? ligne.sets : [])
                .filter((serie) => serie !== null && !isTemporaryId(serie.id))
                .map((serie) => ({ ...sansLesChampsDeLEcran(serie), ...repliDe(serie) })),
        }))

    return copie
}
