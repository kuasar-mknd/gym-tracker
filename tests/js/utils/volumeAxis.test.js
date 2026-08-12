import { describe, it, expect } from 'vitest'
import { formatVolumeTick } from '@/Utils/volumeAxis'

describe('formatVolumeTick', () => {
    it('keeps the half-thousand instead of rounding it away', () => {
        // The defect this rule exists for: toFixed(0) labelled 1500 as "2k",
        // and with the 500 kg step Chart.js picks for smaller volumes the axis
        // read "1k, 2k, 2k, 3k, 3k" — duplicated labels, each 500 kg out.
        expect(formatVolumeTick(1500)).toBe('1.5k')
        expect(formatVolumeTick(2500)).toBe('2.5k')
    })

    it('still labels round thousands cleanly', () => {
        expect(formatVolumeTick(2000)).toBe('2.0k')
        expect(formatVolumeTick(12000)).toBe('12.0k')
    })

    it('shortens from a thousand up, not from the tick after it', () => {
        expect(formatVolumeTick(1000)).toBe('1.0k')
        expect(formatVolumeTick(999)).toBe(999)
    })

    it('leaves values under a thousand as numbers, not strings', () => {
        // Chart.js formats its own small end; handing back "750" would lose
        // whatever grouping the axis applies.
        expect(formatVolumeTick(750)).toBe(750)
        expect(formatVolumeTick(0)).toBe(0)
    })
})
