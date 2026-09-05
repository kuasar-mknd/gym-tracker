import { isTemporaryId } from '@/Utils/pendingIds'
import { classifySyncError, SYNC_OFFLINE, SYNC_PERMANENT } from '@/Utils/syncErrors'

/**
 * Les champs d'une série que l'écran modifie. Tous sont des nombres en base :
 * une valeur venue d'un champ de saisie, toujours une chaîne, est normalisée
 * avant d'atteindre la rangée ou la charge utile.
 */
export const NUMERIC_SET_FIELDS = ['weight', 'reps', 'distance_km', 'duration_seconds']

const draftKey = (setId) => `draft_set_${setId}`
const confirmedKey = (setId, field) => `${setId}_${field}`

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
 */
export const useBrouillonsDeSeries = () => {
    const confirmedValues = new Map()

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
    // n'existe plus, ni valeur confirmée à garder pour elle.
    const oublierLaSerie = (setId) => {
        NUMERIC_SET_FIELDS.forEach((field) => confirmedValues.delete(confirmedKey(setId, field)))
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

            if (!key?.startsWith('draft_set_')) {
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

            const set = trouverLaSerie(key.replace('draft_set_', ''))

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
    }
}
