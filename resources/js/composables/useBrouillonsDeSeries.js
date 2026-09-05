import { isTemporaryId } from '@/Utils/pendingIds'

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

    return {
        releverLesValeursDuServeur,
        rememberConfirmed,
        lastConfirmed,
        readDraft,
        writeDraftField,
        clearDraftField,
        oublierLaSerie,
    }
}
