import { describe, it, expect, beforeEach, vi } from 'vitest'
import { useBrouillonsDeSeries, NUMERIC_SET_FIELDS } from '@/composables/useBrouillonsDeSeries'

beforeEach(() => {
    localStorage.clear()
})

describe('les brouillons d’une série', () => {
    it('ne garde que les champs encore en vol, et oublie la clef quand il ne reste rien', () => {
        const { readDraft, writeDraftField, clearDraftField } = useBrouillonsDeSeries()

        writeDraftField(42, 'weight', 80)
        writeDraftField(42, 'reps', 5)
        expect(readDraft(42)).toEqual({ weight: 80, reps: 5 })

        clearDraftField(42, 'weight')
        expect(readDraft(42)).toEqual({ reps: 5 })
        expect(localStorage.getItem('draft_set_42')).not.toBeNull()

        clearDraftField(42, 'reps')
        expect(localStorage.getItem('draft_set_42')).toBeNull()
    })

    it('laisse tomber la clef même si le refus est encore noté dessus', () => {
        const { writeDraftField, clearDraftField } = useBrouillonsDeSeries()

        writeDraftField(7, 'syncRejected', true)
        writeDraftField(7, 'reps', 8)
        clearDraftField(7, 'reps')

        expect(localStorage.getItem('draft_set_7')).toBeNull()
    })

    it('repart de zéro sur un brouillon illisible', () => {
        localStorage.setItem('draft_set_9', '{pas du json')
        const { readDraft, writeDraftField } = useBrouillonsDeSeries()

        expect(readDraft(9)).toEqual({})

        writeDraftField(9, 'weight', 60)
        expect(readDraft(9)).toEqual({ weight: 60 })
    })
})

describe('les valeurs que le serveur détient', () => {
    it('retombe sur le repli tant que rien n’a été relevé, puis sur la valeur relevée', () => {
        const { rememberConfirmed, lastConfirmed } = useBrouillonsDeSeries()
        const serie = { id: 3, weight: 100 }

        expect(lastConfirmed(serie, 'weight', 100)).toBe(100)

        rememberConfirmed(3, 'weight', 95)
        expect(lastConfirmed(serie, 'weight', 100)).toBe(95)
    })

    it('relève chaque champ numérique de chaque série reçue, en tableau comme en objet', () => {
        const { releverLesValeursDuServeur, lastConfirmed } = useBrouillonsDeSeries()

        releverLesValeursDuServeur({
            workout_lines: {
                a: { sets: [{ id: 1, weight: 50, reps: 10, distance_km: null, duration_seconds: 30 }] },
                b: { sets: [{ id: 2, weight: 70 }, null] },
            },
        })

        NUMERIC_SET_FIELDS.forEach((champ) => {
            expect(lastConfirmed({ id: 1 }, champ, 'repli')).not.toBe('repli')
        })
        expect(lastConfirmed({ id: 1 }, 'distance_km', 'repli')).toBeNull()
        expect(lastConfirmed({ id: 2 }, 'weight', 'repli')).toBe(70)
        expect(lastConfirmed({ id: 2 }, 'reps', 'repli')).toBe('repli')
    })

    it('ignore les séries provisoires, que le serveur ne connaît pas, et une séance sans lignes', () => {
        const { releverLesValeursDuServeur, lastConfirmed } = useBrouillonsDeSeries()

        releverLesValeursDuServeur({ workout_lines: [{ sets: [{ id: 'temp-1', weight: 40 }] }, { sets: 'rien' }] })
        releverLesValeursDuServeur(null)

        expect(lastConfirmed({ id: 'temp-1' }, 'weight', 'repli')).toBe('repli')
    })

    it('oublie tout d’une série retirée : ses valeurs relevées comme son brouillon', () => {
        const { rememberConfirmed, lastConfirmed, writeDraftField, oublierLaSerie } = useBrouillonsDeSeries()

        rememberConfirmed(5, 'weight', 80)
        writeDraftField(5, 'reps', 6)
        oublierLaSerie(5)

        expect(lastConfirmed({ id: 5 }, 'weight', 'repli')).toBe('repli')
        expect(localStorage.getItem('draft_set_5')).toBeNull()
    })
})

describe('le rejeu des brouillons au montage', () => {
    const page = (envoyer) => {
        const serie = { id: 11, weight: 50, reps: 5 }
        const marquerNonSynchronisee = vi.fn()
        const { rejouerLesBrouillons } = useBrouillonsDeSeries()

        rejouerLesBrouillons({
            trouverLaSerie: (setId) => (String(setId) === '11' ? serie : null),
            envoyer,
            marquerNonSynchronisee,
        })

        return { serie, marquerNonSynchronisee }
    }

    it('applique le brouillon à la série, l’envoie, et efface la clef une fois accepté', async () => {
        localStorage.setItem('draft_set_11', JSON.stringify({ weight: 60 }))
        const envoyer = vi.fn().mockResolvedValue({})

        const { serie } = page(envoyer)
        await Promise.resolve()

        expect(serie.weight).toBe(60)
        expect(envoyer).toHaveBeenCalledWith(serie, { weight: 60 })
        expect(localStorage.getItem('draft_set_11')).toBeNull()
    })

    it('ne renvoie pas un brouillon déjà refusé : il le marque seulement', () => {
        localStorage.setItem('draft_set_11', JSON.stringify({ reps: 8, syncRejected: true }))
        const envoyer = vi.fn()

        const { serie, marquerNonSynchronisee } = page(envoyer)

        expect(serie.reps).toBe(8)
        expect(envoyer).not.toHaveBeenCalled()
        expect(marquerNonSynchronisee).toHaveBeenCalledWith(11)
        expect(localStorage.getItem('draft_set_11')).not.toBeNull()
    })

    it('laisse tomber la clef quand la file hors ligne a pris l’écriture', async () => {
        localStorage.setItem('draft_set_11', JSON.stringify({ weight: 60 }))
        const envoyer = vi.fn().mockRejectedValue({ isOffline: true })

        const { marquerNonSynchronisee } = page(envoyer)
        await Promise.resolve()
        await Promise.resolve()

        expect(localStorage.getItem('draft_set_11')).toBeNull()
        expect(marquerNonSynchronisee).not.toHaveBeenCalled()
    })

    it('note le refus définitif sur le brouillon et marque la série', async () => {
        localStorage.setItem('draft_set_11', JSON.stringify({ weight: 60 }))
        const envoyer = vi.fn().mockRejectedValue({ response: { status: 422 } })

        const { marquerNonSynchronisee } = page(envoyer)
        await Promise.resolve()
        await Promise.resolve()

        expect(JSON.parse(localStorage.getItem('draft_set_11'))).toEqual({ weight: 60, syncRejected: true })
        expect(marquerNonSynchronisee).toHaveBeenCalledWith(11)
    })

    it('garde le brouillon intact sur un échec passager, pour le montage suivant', async () => {
        localStorage.setItem('draft_set_11', JSON.stringify({ weight: 60 }))
        const envoyer = vi.fn().mockRejectedValue({ response: { status: 503 } })

        const { marquerNonSynchronisee } = page(envoyer)
        await Promise.resolve()
        await Promise.resolve()

        expect(JSON.parse(localStorage.getItem('draft_set_11'))).toEqual({ weight: 60 })
        expect(marquerNonSynchronisee).toHaveBeenCalledWith(11)
    })

    it('efface un brouillon illisible ou vide, et ignore celui d’une série absente', () => {
        localStorage.setItem('draft_set_11', '{pas du json')
        localStorage.setItem('draft_set_12', 'null')
        localStorage.setItem('draft_set_99', JSON.stringify({ weight: 1 }))
        localStorage.setItem('autre_clef', 'x')
        const envoyer = vi.fn()

        page(envoyer)

        expect(envoyer).not.toHaveBeenCalled()
        expect(localStorage.getItem('draft_set_11')).toBeNull()
        expect(localStorage.getItem('draft_set_12')).toBeNull()
        expect(localStorage.getItem('draft_set_99')).not.toBeNull()
        expect(localStorage.getItem('autre_clef')).toBe('x')
    })
})
