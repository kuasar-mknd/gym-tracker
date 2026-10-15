import { shallowReactive } from 'vue'
import { isTemporaryId } from '@/Utils/pendingIds'
import { classifySyncError, SYNC_OFFLINE, SYNC_PERMANENT } from '@/Utils/syncErrors'

/**
 * Les champs d'une série que l'écran modifie. Tous sont des nombres en base :
 * une valeur venue d'un champ de saisie, toujours une chaîne, est normalisée
 * avant d'atteindre la rangée ou la charge utile.
 */
export const NUMERIC_SET_FIELDS = ['weight', 'reps', 'distance_km', 'duration_seconds']

const PREFIXE_DES_BROUILLONS = 'draft_set_'
const draftKey = (setId) => `${PREFIXE_DES_BROUILLONS}${setId}`
const confirmedKey = (setId, field) => `${setId}_${field}`

/**
 * Efface tous les brouillons de séries de l'appareil.
 *
 * Un brouillon est rangé sous l'identifiant de la série, pas sous celui du
 * compte : à la déconnexion, il n'a plus de lecteur légitime (#1964). Un
 * stockage bloqué ne doit pas retenir la déconnexion qui l'appelle.
 */
export const effacerLesBrouillons = () => {
    try {
        Object.keys(localStorage)
            .filter((cle) => cle.startsWith(PREFIXE_DES_BROUILLONS))
            .forEach((cle) => localStorage.removeItem(cle))
    } catch {
        // Le stockage refuse : les brouillons restent, la déconnexion part.
    }
}

/**
 * Efface les brouillons rangés sous un identifiant provisoire qu'aucune
 * écriture en file ne tient plus.
 *
 * Un identifiant provisoire ne vaut que pour le montage qui l'a donné : le
 * compteur de `useIdentiteDesRangees` repart de zéro à chaque montage, et le
 * `temp-3` d'hier désigne une autre série aujourd'hui. Laissé en place, le
 * brouillon d'une série rangé sous ce nom serait appliqué par
 * `rejouerLesBrouillons` à la série qui le porte maintenant, puis envoyé avec
 * elle (#2048). Seuls restent ceux des rangées que la file crée encore
 * (`temp-q<queueId>`, `Utils/seanceEtFile`), qui gardent ce nom d'un montage à
 * l'autre ; ceux d'une série enregistrée, sous son identifiant réel, ne sont
 * pas touchés. Un stockage bloqué ne doit pas retenir l'ouverture de la séance.
 *
 * @param {Iterable<string>} gardes les identifiants provisoires des rangées que
 *   la file crée encore
 */
export const effacerLesBrouillonsOrphelins = (gardes) => {
    const encoreEnFile = new Set(gardes)

    try {
        Object.keys(localStorage)
            .filter((cle) => cle.startsWith(PREFIXE_DES_BROUILLONS))
            .filter((cle) => {
                const setId = cle.slice(PREFIXE_DES_BROUILLONS.length)

                return isTemporaryId(setId) && !encoreEnFile.has(setId)
            })
            .forEach((cle) => localStorage.removeItem(cle))
    } catch {
        // Le stockage refuse : les brouillons restent, la séance s'ouvre.
    }
}

/**
 * Ce que le serveur détient de chaque série, et ce que l'écran n'a pas encore
 * réussi à lui faire accepter.
 *
 * Les valeurs confirmées servent de point de retour : un refus du serveur
 * restaure ce qu'il détient, jamais une valeur optimiste que l'écran a
 * inventée. Elles sont relevées à chaque charge utile reçue, pas seulement sur
 * une réponse acceptée : tant qu'un champ n'avait jamais été enregistré depuis
 * la page, le repli était la valeur à l'écran (#1540).
 *
 * Les brouillons gardent, par série, les seuls champs encore en vol dans le
 * stockage local. Un brouillon entier sous une clef, effacé dès qu'UN champ
 * revenait accepté, perdait les répétitions dont le PATCH n'était pas parti
 * quand le poids venait d'être confirmé.
 *
 * Les écritures non confirmées sont celles d'une série réelle qui attendent le
 * debounce ou leur réponse, avec la valeur que la page rétablit si le serveur
 * refuse. L'entrée d'historique de la page garde cette valeur de repli à leur
 * place (`seanceConnueDuServeur`, #2048) : une écriture refusée après le
 * départ revenait sinon au bouton Avance comme enregistrée. Une écriture partie
 * en file n'en est plus une : la file la garde, et la rend au retour.
 */
export const useBrouillonsDeSeries = () => {
    const confirmedValues = new Map()

    /**
     * Par écriture (le champ d'une série, ou sa coche) : la rangée, le champ et
     * la valeur de repli. Réactive, pour que l'entrée d'historique suive aussi
     * une confirmation, qui ne change rien à l'écran.
     *
     * @type {Map<string, {serie: object, champ: string, valeurDeRepli: unknown}>}
     */
    const ecrituresNonConfirmees = shallowReactive(new Map())

    /**
     * @param {string} cle l'écriture : la même clef pour la même rafale ou la même coche
     * @param {object} serie la rangée à l'écran, dont l'identifiant peut encore changer
     * @param {string} champ
     * @param {unknown} valeurDeRepli ce que la page rétablit si le serveur refuse
     */
    const noterUneEcritureNonConfirmee = (cle, serie, champ, valeurDeRepli) => {
        ecrituresNonConfirmees.set(cle, { serie, champ, valeurDeRepli })
    }

    /** L'écriture a sa réponse, acceptée ou refusée, ou elle est partie en file. */
    const oublierLEcritureNonConfirmee = (cle) => {
        ecrituresNonConfirmees.delete(cle)
    }

    /**
     * Les valeurs de repli des écritures qui attendent, sous l'identifiant que
     * chaque série porte maintenant.
     *
     * @returns {Array<{id: unknown, champ: string, valeur: unknown}>}
     */
    const valeursDeRepli = () =>
        [...ecrituresNonConfirmees.values()].map(({ serie, champ, valeurDeRepli }) => ({
            id: serie.id,
            champ,
            valeur: valeurDeRepli,
        }))

    const rememberConfirmed = (setId, field, value) => {
        confirmedValues.set(confirmedKey(setId, field), value)
    }

    const lastConfirmed = (set, field, fallback) => {
        const key = confirmedKey(set.id, field)

        return confirmedValues.has(key) ? confirmedValues.get(key) : fallback
    }

    const releverLesValeursDuServeur = (workout) => {
        // Le serveur envoie parfois les lignes en objet plutôt qu'en tableau, et
        // la fusion s'en accommode déjà ; ce relevé doit en faire autant.
        const lignes = workout?.workout_lines
        const enTableau = Array.isArray(lignes) ? lignes : Object.values(lignes ?? {})

        enTableau.forEach((line) =>
            (Array.isArray(line?.sets) ? line.sets : []).forEach((set) => {
                if (set === null || isTemporaryId(set.id)) {
                    return
                }

                NUMERIC_SET_FIELDS.forEach((field) => {
                    if (set[field] !== undefined) {
                        rememberConfirmed(set.id, field, set[field])
                    }
                })
            }),
        )
    }

    const readDraft = (setId) => {
        try {
            return JSON.parse(localStorage.getItem(draftKey(setId)) || '{}')
        } catch {
            return {}
        }
    }

    const writeDraftField = (setId, field, value) => {
        localStorage.setItem(draftKey(setId), JSON.stringify({ ...readDraft(setId), [field]: value }))
    }

    const clearDraftField = (setId, field) => {
        const { [field]: _dropped, ...rest } = readDraft(setId)

        if (Object.keys(rest).filter((key) => key !== 'syncRejected').length === 0) {
            localStorage.removeItem(draftKey(setId))

            return
        }

        localStorage.setItem(draftKey(setId), JSON.stringify(rest))
    }

    // La rangée disparaît : ni brouillon à rejouer contre un identifiant qui
    // n'existe plus, ni valeur confirmée ou de repli à garder pour elle.
    const oublierLaSerie = (setId) => {
        NUMERIC_SET_FIELDS.forEach((field) => confirmedValues.delete(confirmedKey(setId, field)))
        ecrituresNonConfirmees.forEach(({ serie }, cle) => {
            if (String(serie.id) === String(setId)) {
                ecrituresNonConfirmees.delete(cle)
            }
        })
        localStorage.removeItem(draftKey(setId))
    }

    /**
     * Rejoue au montage les brouillons qu'un depart de la page a laisses.
     *
     * Un brouillon deja refuse reste visible et marque, mais n'est plus
     * renvoye : un 4xx ne devient pas un 2xx. Une reponse hors ligne veut
     * dire « en file d'attente », le brouillon en serait une seconde copie.
     * Un echec passager garde le brouillon pour le montage suivant.
     *
     * @param {{
     *   trouverLaSerie: (setId: string) => object | null,
     *   envoyer: (set: object, payload: object) => Promise<unknown>,
     *   marquerNonSynchronisee: (setId: unknown) => void,
     * }} page
     */
    const rejouerLesBrouillons = ({ trouverLaSerie, envoyer, marquerNonSynchronisee }) => {
        const illisibles = []

        for (let i = 0; i < localStorage.length; i++) {
            const key = localStorage.key(i)

            if (!key?.startsWith(PREFIXE_DES_BROUILLONS)) {
                continue
            }

            let brouillon

            try {
                brouillon = JSON.parse(localStorage.getItem(key))
            } catch {
                brouillon = null
            }

            if (brouillon === null || typeof brouillon !== 'object') {
                illisibles.push(key)

                continue
            }

            const set = trouverLaSerie(key.replace(PREFIXE_DES_BROUILLONS, ''))

            if (!set) {
                continue
            }

            const payload = {}

            NUMERIC_SET_FIELDS.forEach((field) => {
                if (brouillon[field] !== undefined) {
                    set[field] = brouillon[field]
                    payload[field] = brouillon[field]
                }
            })

            if (brouillon.syncRejected) {
                marquerNonSynchronisee(set.id)

                continue
            }

            envoyer(set, payload)
                .then(() => localStorage.removeItem(key))
                .catch((err) => {
                    const kind = classifySyncError(err)

                    if (kind === SYNC_OFFLINE) {
                        localStorage.removeItem(key)

                        return
                    }

                    if (kind === SYNC_PERMANENT) {
                        localStorage.setItem(key, JSON.stringify({ ...brouillon, syncRejected: true }))
                    }

                    marquerNonSynchronisee(set.id)
                })
        }

        illisibles.forEach((key) => localStorage.removeItem(key))
    }

    return {
        releverLesValeursDuServeur,
        rememberConfirmed,
        lastConfirmed,
        readDraft,
        writeDraftField,
        clearDraftField,
        oublierLaSerie,
        rejouerLesBrouillons,
        noterUneEcritureNonConfirmee,
        oublierLEcritureNonConfirmee,
        valeursDeRepli,
    }
}
