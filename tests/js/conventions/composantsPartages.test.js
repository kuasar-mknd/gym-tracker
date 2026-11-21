import { it, expect } from 'vitest'
import { readFileSync } from 'node:fs'

import { collectSourceFiles, jsRoot } from './sourceFiles'

/** Un interlettrage positif : `normal`, zéro et les valeurs négatives (titres serrés) passent. */
const INTERLETTRAGE_POSITIF = /letter-spacing:\s*\+?(?:0?\.\d+|[1-9]\d*(?:\.\d+)?)(?:px|em|rem)?(?![\w.])/

/**
 * Ce qui datait d'avant la refonte du verre (#2434) ne revient pas dans
 * Components/UI : capitales étirées, graisses 800 et 900, lueurs et ombres
 * colorées, texte dégradé, pulsation hors direct, survol qui agrandit. Un
 * composant partagé y répond pour toutes les pages qui l'emploient.
 *
 * Les rôles `titre-page`, `titre-section`, `titre-carte` et `sur-titre` sont
 * encore ceux des pages en capitales : un composant partagé prend `titre-plat`
 * ou `etiquette`.
 */
const INTERDITS = [
    [/\buppercase\b/, 'capitales : la casse de phrase se garde'],
    [/\btracking-(?:wide|wider|widest)\b|tracking-\[/, 'interlettrage étiré'],
    [/\bfont-(?:extrabold|black)\b|font-\[(?:8|9)00\]|font-weight:\s*(?:8|9)00\b/, 'graisse 800 ou 900'],
    [/text-transform:\s*uppercase/, 'capitales en style'],
    [INTERLETTRAGE_POSITIF, 'interlettrage étiré en style'],
    [/\bitalic\b(?<!not-italic)/, 'italique'],
    [/\bshadow-(?:glow|neon)/, 'ombre colorée'],
    [/\bdrop-shadow/, 'ombre portée sur un glyphe'],
    [/\btext-gradient|\bbg-linear|\bbg-gradient|\bbg-clip-text|\btext-transparent/, 'dégradé'],
    [/\banimate-(?:pulse|ping|bounce)/, 'mouvement qui boucle hors direct'],
    [/\b(?:group-)?hover:scale/, 'survol qui agrandit'],
    [/\btransition-all\b/, 'transition de toutes les propriétés'],
    [/\b(?:titre-page|titre-section|titre-carte|sur-titre|font-display-label)\b/, 'rôle des pages en capitales'],
]

it('ne garde dans Components/UI rien de ce qui datait d’avant le verre', () => {
    const fautifs = collectSourceFiles()
        .filter((fichier) => fichier.startsWith(`${jsRoot}/Components/UI/`))
        .flatMap((fichier) =>
            readFileSync(fichier, 'utf8')
                .split('\n')
                .flatMap((ligne, index) =>
                    INTERDITS.filter(([motif]) => motif.test(ligne)).map(
                        ([, raison]) => `${fichier.replace(jsRoot, 'resources/js')}:${index + 1} — ${raison}`,
                    ),
                ),
        )

    expect(fautifs).toEqual([])
})

/**
 * Un utilitaire `glass-*` de app.css ne porte ce qui datait d'avant le verre ni
 * en déclaration (`text-transform`, `font-style`, `font-weight`,
 * `letter-spacing`), ni par `@apply` de la classe Tailwind qui le fait.
 */
it('ne garde dans les utilitaires glass-* de app.css ni capitales, ni italique, ni graisse 800 ou 900, ni interlettrage étiré', () => {
    const css = readFileSync(`${jsRoot}/../css/app.css`, 'utf8')
    const blocs = [...css.matchAll(/^@utility (glass-[\w-]+) \{[\s\S]*?^\}/gm)]
    const declarations = [
        /text-transform:\s*uppercase/,
        /font-style:\s*italic/,
        /font-weight:\s*(?:8|9)00\b/,
        INTERLETTRAGE_POSITIF,
    ]

    const fautifs = blocs
        .filter(([bloc]) => {
            const appliques = [...bloc.matchAll(/@apply[^;]*;/g)].map(([ligne]) => ligne).join('\n')

            return declarations.some((motif) => motif.test(bloc)) || INTERDITS.some(([motif]) => motif.test(appliques))
        })
        .map(([, nom]) => nom)

    expect(blocs.length).toBeGreaterThan(5)
    expect(fautifs).toEqual([])
})
