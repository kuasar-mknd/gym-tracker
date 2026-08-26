import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { vi } from 'vitest'
import { config } from '@vue/test-utils'

/*
 * La charte, chargée pour de vrai.
 *
 * jsdom n'applique aucune feuille de style : `getComputedStyle` y rend une
 * chaîne vide pour toute variable, donc `Utils/couleurs.js` ne trouvait rien et
 * les graphiques recevaient des dégradés sans couleur. C'est précisément le
 * défaut qui s'est vu à l'écran — un anneau aux segments noirs — et il est
 * heureux que les tests le reproduisent plutôt que de le manquer.
 *
 * Poser ici une liste de variables écrite à la main aurait recréé la panne que
 * toute cette charte existe pour supprimer : une deuxième copie des valeurs,
 * libre de diverger. On lit donc `app.css`, le registre lui-même. Un test qui
 * vérifie qu'un graphique emploie l'orange de la charte vérifie alors le vrai
 * orange, et le jour où il change, il change des deux côtés à la fois.
 */
const charte = readFileSync(resolve(__dirname, 'resources/css/app.css'), 'utf-8')

for (const [, nom, valeur] of charte.matchAll(/--color-([a-z0-9-]+):\s*([^;]+);/g)) {
    document.documentElement.style.setProperty(`--color-${nom}`, valeur.trim())
}

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

/*
 * `Head` est enregistre pour tous les montages, troisieme source de journaux
 * tardifs apres le canvas et `v-press`.
 *
 * Meme mecanique que les deux precedentes, et meme signature en CI :
 * « [Vue warn]: Failed to resolve component: Head » emis par un composant
 * charge en `defineAsyncComponent`, donc resolu APRES la fin du fichier de
 * test. L'avertissement arrive alors que le worker se ferme, et Vitest rend
 * `EnvironmentTeardownError: Closing rpc while "onUserConsoleLog" was pending`.
 * Tous les tests passent, la couverture tient son seuil, et la commande sort
 * quand meme en erreur.
 *
 * 162 fichiers de test le simulent deja a la main ; ceux qui ne le font pas
 * sont precisement ceux qui montent une page sans savoir qu'elle en rendra un.
 * Une declaration locale garde le dernier mot — Test Utils fusionne
 * `config.global` avec le `global` du montage, et le second l'emporte.
 *
 * Il ne rend rien, comme le vrai : `Head` pose des balises dans le document,
 * pas dans l'arbre du composant. Un stub qui rendrait un element fausserait
 * les assertions de structure.
 */
config.global.components = { ...config.global.components, Head: { render: () => null } }

/*
 * La charte, sous jsdom.
 *
 * `Utils/couleurs.js` lit les jetons par `getComputedStyle` sur la racine. Une
 * feuille Tailwind n'est pas chargee ici, donc sans ces declarations tout
 * graphique dessinerait avec une couleur vide.
 *
 * Ces valeurs ne sont PAS une copie de la charte : elles n'ont pas a coincider
 * avec elle, elles ont seulement a exister pour que le mecanisme de lecture
 * soit exerce. `LaCharteEstLueParLeJsTest` verifie la correspondance des NOMS
 * cote depot ; c'est la que la charte est tenue.
 */
const JETONS_DE_TEST = {
    'category-chest': '#ff5500',
    'category-back': '#8800ff',
    'category-shoulders': '#ff0080',
    'category-arms': '#00e5ff',
    'category-legs': '#ccff00',
    'category-core': '#f5009b',
    'category-cardio': '#c0eb00',
    'category-other': '#64748b',
}

for (const [nom, valeur] of Object.entries(JETONS_DE_TEST)) {
    document.documentElement.style.setProperty(`--color-${nom}`, valeur)
}
