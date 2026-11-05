import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { afterEach, describe, expect, it, vi } from 'vitest'

import { FAMILLE_DE_REPLI_DES_ICONES, installerLaPoliceDeRepliDesIcones } from '@/Utils/policeDeRepliDesIcones'
import { jsRoot } from '../conventions/sourceFiles'

/**
 * La police de repli des icônes (#2193) : avant Material Symbols, le nom d'une
 * icône se compose dans la police suivante de sa pile, et ne doit ni s'écrire
 * ni prendre de place. Elle doit donc être chargée avant la première icône :
 * une `FontFace` construite depuis ses octets l'est dès sa création, quand une
 * déclaration CSS ne se charge qu'à son premier usage. Ce que le navigateur en
 * fait se mesure dans tests/Browser/IconesAvantLeurPoliceTest.php ; ici, ce
 * que le module pose, et que `main.js` le pose avant de monter l'application.
 */
describe('la police de repli des icônes', () => {
    afterEach(() => {
        vi.unstubAllGlobals()
    })

    /** Une `FontFace` qui garde ce qu'on lui donne. */
    const fauxFontFace = () =>
        vi.fn(function FontFace(famille, source) {
            this.family = famille
            this.source = source
        })

    it('pose une police construite depuis les octets du fichier, sous le nom que la pile des icônes donne', () => {
        const FontFace = fauxFontFace()
        vi.stubGlobal('FontFace', FontFace)
        const polices = { add: vi.fn() }

        expect(installerLaPoliceDeRepliDesIcones(polices)).toBe(true)

        expect(polices.add).toHaveBeenCalledTimes(1)
        const [police] = polices.add.mock.calls[0]
        expect(police.family).toBe('Icones en attente')
        expect(police.source).toBeInstanceOf(Uint8Array)
        expect(
            Buffer.from(police.source).equals(readFileSync(resolve(jsRoot, '../fonts/icones-en-attente.woff2'))),
        ).toBe(true)
        expect(Buffer.from(police.source.subarray(0, 4)).toString('latin1')).toBe('wOF2')
    })

    it('porte le nom que la règle des icônes écrit après Material Symbols', () => {
        const feuille = readFileSync(resolve(jsRoot, '../css/app.css'), 'utf-8')

        expect(feuille).toContain(`font-family: 'Material Symbols Outlined', '${FAMILLE_DE_REPLI_DES_ICONES}';`)
    })

    it('ne pose rien sans `FontFace` ni ensemble de polices : Material Symbols reste en « block »', () => {
        const polices = { add: vi.fn() }
        vi.stubGlobal('FontFace', undefined)

        expect(installerLaPoliceDeRepliDesIcones(polices)).toBe(false)

        vi.stubGlobal('FontFace', fauxFontFace())

        expect(installerLaPoliceDeRepliDesIcones(undefined)).toBe(false)
        expect(polices.add).not.toHaveBeenCalled()
    })

    it('lit par défaut les polices du document', () => {
        vi.stubGlobal('FontFace', fauxFontFace())
        const add = vi.fn()
        Object.defineProperty(document, 'fonts', { value: { add }, configurable: true })

        try {
            expect(installerLaPoliceDeRepliDesIcones()).toBe(true)
            expect(add).toHaveBeenCalledTimes(1)
        } finally {
            delete document.fonts
        }
    })

    it('est posée par main.js avant le montage de l’application', () => {
        const main = readFileSync(resolve(jsRoot, 'main.js'), 'utf-8')
        const pose = main.indexOf('installerLaPoliceDeRepliDesIcones()')

        expect(pose).toBeGreaterThan(-1)
        expect(pose).toBeLessThan(main.indexOf('const demarrerLApplication'))
        expect(pose).toBeLessThan(main.indexOf('createInertiaApp({'))
    })
})
