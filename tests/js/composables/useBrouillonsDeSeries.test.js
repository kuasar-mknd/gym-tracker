import { describe, it, expect, beforeEach } from 'vitest'
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
