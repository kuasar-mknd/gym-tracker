import { it, expect } from 'vitest'
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'

import { jsRoot } from './sourceFiles'

const css = readFileSync(resolve(jsRoot, '../css/app.css'), 'utf8')
const fond = readFileSync(resolve(jsRoot, 'Components/UI/LiquidBackground.vue'), 'utf8')

/** Le corps d'un `@utility` de app.css, de son accolade ouvrante à la fermante. */
const corpsDeLUtilitaire = (nom) => css.match(new RegExp(`@utility ${nom} \\{[\\s\\S]*?\\n\\}`))?.[0] ?? ''

/**
 * Les cartes n'ont plus de cyan : les lueurs du fond, qui en étaient les restes,
 * sont devenues violettes. L'information (`accent-info`) garde ses autres
 * emplois, mais ne revient pas teinter le fond.
 */
it('ne teinte aucune lueur de fond de cyan', () => {
    expect(corpsDeLUtilitaire('liquid-bg-2')).toContain('accent-tertiary')
    expect(corpsDeLUtilitaire('liquid-bg-2')).not.toContain('accent-info')
    expect(fond).not.toContain('accent-info')
})

it('nomme la variante des statistiques par ses couleurs', () => {
    expect(fond).toContain("variant === 'violet-bleu'")
    expect(fond).not.toContain('cyan-magenta')
})
