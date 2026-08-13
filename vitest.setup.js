import { vi } from 'vitest'

/*
 * jsdom ships no canvas, so every chart component that reaches for a drawing
 * context logs "Not implemented: HTMLCanvasElement.prototype.getContext".
 *
 * That noise is not harmless. Pages/Exercises/Show.vue mounts fourteen charts
 * through defineAsyncComponent, and those resolve *after* the test file has
 * finished — the log then arrives once the worker is already closing, and
 * Vitest reports `EnvironmentTeardownError: Closing rpc while "onUserConsoleLog"
 * was pending`. Every test passes, coverage clears its floor, and the run still
 * exits non-zero. It only surfaced on CI, where the timing is tighter, which is
 * the worst way for it to surface.
 *
 * A context stub is enough: the charts get an object whose methods do nothing,
 * draw nothing, and say nothing. Tests that care about what a chart was handed
 * assert on the props given to it, never on pixels.
 */
const noop = () => {}

HTMLCanvasElement.prototype.getContext = vi.fn(() => ({
    canvas: { width: 0, height: 0 },
    fillRect: noop,
    clearRect: noop,
    getImageData: () => ({ data: [] }),
    putImageData: noop,
    createImageData: () => [],
    setTransform: noop,
    drawImage: noop,
    save: noop,
    restore: noop,
    beginPath: noop,
    closePath: noop,
    moveTo: noop,
    lineTo: noop,
    stroke: noop,
    fill: noop,
    measureText: () => ({ width: 0 }),
    fillText: noop,
    translate: noop,
    scale: noop,
    rotate: noop,
    arc: noop,
    createLinearGradient: () => ({ addColorStop: noop }),
    createRadialGradient: () => ({ addColorStop: noop }),
}))

// Mock matchMedia
Object.defineProperty(window, 'matchMedia', {
    writable: true,
    value: vi.fn().mockImplementation((query) => ({
        matches: false,
        media: query,
        onchange: null,
        addListener: vi.fn(), // Deprecated
        removeListener: vi.fn(), // Deprecated
        addEventListener: vi.fn(),
        removeEventListener: vi.fn(),
        dispatchEvent: vi.fn(),
    })),
})
