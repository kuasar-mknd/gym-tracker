import { vi } from 'vitest'
import { config } from '@vue/test-utils'

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

/*
 * `v-press` est enregistree pour tous les montages, et c'est la meme histoire
 * que le canvas ci-dessus.
 *
 * La directive est reelle : `main.js` la pose sur l'application. Un composant
 * qui l'emploie et qu'un test monte sans elle produit
 * « [Vue warn]: Failed to resolve directive: press ». Ce n'est pas du bruit
 * inoffensif : plusieurs pages chargent leurs composants par
 * `defineAsyncComponent`, qui se resolvent APRES la fin du fichier de test.
 * L'avertissement arrive alors que le worker se ferme deja, et Vitest rend
 * `EnvironmentTeardownError: Closing rpc while "onUserConsoleLog" was pending`.
 * Tous les tests passent, la couverture tient son seuil, et la commande sort
 * quand meme en erreur.
 *
 * Comme pour le canvas, ca ne se voit qu'en CI, ou le rythme est plus serre —
 * la pire facon pour un defaut de se manifester.
 *
 * Un stub vide, et non la vraie directive : c'est deja la convention des 167
 * fichiers qui la declarent a la main — « les tests ont seulement besoin
 * qu'elle existe ». Importer la vraie ici la lierait au `useHaptics` non
 * simule, et son propre test cesserait de voir ses appels.
 *
 * Une declaration locale garde le dernier mot — Test Utils fusionne
 * `config.global` avec le `global` du montage, et le second l'emporte. C'est
 * ce qui laisse `tests/js/directives/vPress.test.js` enregistrer la vraie
 * directive et voir ses appels.
 */
config.global.directives = { ...config.global.directives, press: {} }
