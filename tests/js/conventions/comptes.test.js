import { it, expect } from 'vitest'
import { readFileSync } from 'node:fs'
import { collectSourceFiles, jsRoot } from './sourceFiles'

/**
 * Un nombre suivi d'un nom au pluriel figé s'écrit « 1 séries » quand il vaut 1,
 * le cas d'une semaine à une séance ou d'une série unique (#1980). Les gabarits
 * avaient été corrigés, mais cinq infobulles de graphique gardaient
 * `${context.parsed.y} séances`. Un compte passe par `compte(n, singulier,
 * pluriel)` de `Utils/nombre.js`, qui accorde le nom.
 *
 * La garde cherche une interpolation, de gabarit Vue (`}}`) ou de chaîne
 * (`${…}`), suivie d'un des noms que l'application compte.
 */
const NOMS_COMPTES = ['séances', 'séries', 'exercices', 'exos', 'habitudes', 'doses', 'reps', 'répétitions', 'produits']

const interpolationSuivieDUnPluriel = new RegExp(
    String.raw`(?:\}\}|\$\{[^{}\n]*(?:\{[^{}\n]*\}[^{}\n]*)*\})\s*(?:${NOMS_COMPTES.join('|')})(?!\p{L})`,
    'gu',
)

it('accorde chaque compte affiché par compte()', () => {
    const fautifs = collectSourceFiles({ extensions: ['.vue', '.js'] }).flatMap((fichier) => {
        const source = readFileSync(fichier, 'utf8')

        return [...source.matchAll(interpolationSuivieDUnPluriel)].map(
            (trouve) =>
                `${fichier.replace(jsRoot, 'resources/js')}:${source.slice(0, trouve.index).split('\n').length} ${trouve[0]}`,
        )
    })

    expect(fautifs, 'passer par compte(n, singulier, pluriel) de Utils/nombre.js').toEqual([])
})

it('reconnaît les formes qu’elle refuse, et laisse passer compte()', () => {
    const refuse = (texte) => new RegExp(interpolationSuivieDUnPluriel.source, 'u').test(texte)

    expect(refuse('label: (context) => `${context.parsed.y} séances`')).toBe(true)
    expect(refuse('`${context.parsed.x} kg × ${context.parsed.y} reps`')).toBe(true)
    expect(refuse("`Atteindre ${props.form.target_value || '?'} séances au total`")).toBe(true)
    expect(refuse('{{ line.sets_count }}\n        séries')).toBe(true)
    expect(refuse("compte(context.parsed.y, 'séance', 'séances')")).toBe(false)
    expect(refuse("{{ compte(line.sets_count, 'série', 'séries') }}")).toBe(false)
    expect(refuse('`${n} séancesX`')).toBe(false)
})
