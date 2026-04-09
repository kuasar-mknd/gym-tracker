import { describe, it, expect } from 'vitest'
import { volumeTooltipCallback } from './chartConfig'

describe('volumeTooltipCallback', () => {
    it('returns label and value when both are present', () => {
        const context = {
            dataset: { label: 'Volume' },
            parsed: { y: 1500 },
        }
        const expectedValue = new Intl.NumberFormat('fr-FR').format(1500)
        expect(volumeTooltipCallback(context)).toBe(`Volume: ${expectedValue} kg`)
    })

    it('returns only value when label is empty or missing', () => {
        const context = {
            dataset: { label: '' },
            parsed: { y: 1500 },
        }
        const expectedValue = new Intl.NumberFormat('fr-FR').format(1500)
        expect(volumeTooltipCallback(context)).toBe(`${expectedValue} kg`)

        const contextWithoutLabel = {
            dataset: {},
            parsed: { y: 1500 },
        }
        expect(volumeTooltipCallback(contextWithoutLabel)).toBe(`${expectedValue} kg`)
    })

    it('handles zero value correctly', () => {
        const context = {
            dataset: { label: 'Volume' },
            parsed: { y: 0 },
        }
        const expectedValue = new Intl.NumberFormat('fr-FR').format(0)
        expect(volumeTooltipCallback(context)).toBe(`Volume: ${expectedValue} kg`)
    })

    it('returns only label when parsed y is null', () => {
        const context = {
            dataset: { label: 'Volume' },
            parsed: { y: null },
        }
        expect(volumeTooltipCallback(context)).toBe('Volume: ')
    })

    it('handles empty dataset and null value', () => {
        const context = {
            dataset: {},
            parsed: { y: null },
        }
        expect(volumeTooltipCallback(context)).toBe('')
    })
})
