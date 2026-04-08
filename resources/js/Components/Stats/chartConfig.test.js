import { describe, it, expect } from 'vitest'
import { volumeTooltipCallback } from './chartConfig'

describe('volumeTooltipCallback', () => {
    it('returns formatted label with kg when dataset label and y value are present', () => {
        const context = {
            dataset: { label: 'Bench Press' },
            parsed: { y: 100 },
        }
        expect(volumeTooltipCallback(context)).toBe('Bench Press: 100 kg')
    })

    it('returns only formatted y value with kg when dataset label is missing', () => {
        const context = {
            dataset: {},
            parsed: { y: 100 },
        }
        expect(volumeTooltipCallback(context)).toBe('100 kg')
    })

    it('returns only dataset label with colon when y value is null', () => {
        const context = {
            dataset: { label: 'Bench Press' },
            parsed: { y: null },
        }
        expect(volumeTooltipCallback(context)).toBe('Bench Press: ')
    })

    it('returns empty string when dataset label is missing and y value is null', () => {
        const context = {
            dataset: {},
            parsed: { y: null },
        }
        expect(volumeTooltipCallback(context)).toBe('')
    })

    it('formats 0 correctly', () => {
        const context = {
            dataset: { label: 'Squat' },
            parsed: { y: 0 },
        }
        expect(volumeTooltipCallback(context)).toBe('Squat: 0 kg')
    })

    it('formats large numbers correctly using fr-FR locale', () => {
        const context = {
            dataset: { label: 'Total Volume' },
            parsed: { y: 12500 },
        }
        // Intl.NumberFormat('fr-FR') uses a narrow no-break space (U+202F) or regular space depending on the environment/browser for thousands separator.
        // Node's ICU might use U+202F
        const formattedNumber = new Intl.NumberFormat('fr-FR').format(12500)
        expect(volumeTooltipCallback(context)).toBe(`Total Volume: ${formattedNumber} kg`)
    })
})
