import { it, expect } from 'vitest'
import { readFileSync } from 'node:fs'

import { collectSourceFiles, jsRoot } from './sourceFiles'

/**
 * Les titres avaient 28 combinaisons de classes, et le même rôle — le titre
 * d'une carte — s'écrivait tantôt en capitales italiques, tantôt en casse de
 * phrase, d'un écran à l'autre (#1783). La charte porte trois tailles, plus le
 * sur-titre : un titre en choisit une, et n'écrit plus sa typographie.
 */
const ROLES = ['titre-page', 'titre-section', 'titre-carte', 'sur-titre']

/** Ce qui place ou colore, par opposition à ce qui compose. */
const MISE_EN_PAGE =
    /^(m[btlrxy]?-|p[btlrxy]?-|gap-|space-|w-|max-w-|min-w-|flex|grid|truncate|line-clamp|shrink|grow|inline|block|hidden|sm:block|text-center|text-left|text-right|break-|overflow|whitespace|self-|items-|justify-|sticky|top-|z-|border|bg-|backdrop-|rounded|shadow|group|relative|absolute)/

const COULEUR =
    /^(text-(text|accent|surface)-|text-transparent|bg-clip-text|bg-linear|from-|via-|to-|text-gradient|drop-shadow)/

it('ne compose un titre que par un rôle de la charte', () => {
    const fautifs = collectSourceFiles({ extensions: ['.vue'] }).flatMap((fichier) => {
        const source = readFileSync(fichier, 'utf8')

        return [...source.matchAll(/<(h[1-3])\b[^>]*?\sclass="([^"]*)"/g)]
            .map((trouve) => ({
                balise: trouve[1],
                typographie: trouve[2]
                    .split(/\s+/)
                    .filter((classe) => classe !== '' && !MISE_EN_PAGE.test(classe) && !COULEUR.test(classe)),
                ligne: source.slice(0, trouve.index).split('\n').length,
            }))
            .filter(
                ({ typographie }) =>
                    typographie.length > 1 || (typographie.length === 1 && !ROLES.includes(typographie[0])),
            )
            .map(
                ({ balise, typographie, ligne }) =>
                    `${fichier.replace(jsRoot, 'resources/js')}:${ligne} — ${balise} « ${typographie.join(' ')} »`,
            )
    })

    expect(fautifs, `un titre porte titre-page, titre-section, titre-carte ou sur-titre, et rien d'autre`).toEqual([])
})
