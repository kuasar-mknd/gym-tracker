import { it, expect } from 'vitest'
import { filesMatching } from './sourceFiles'

/**
 * La charte nomme des rôles ; une valeur écrite à la main dans un composant lui
 * échappe, et deux valeurs voisines finissent par se contredire (#1784, #1785,
 * #1803, #1808). Ce qui manque à la charte s'ajoute à la charte, pas au composant.
 */
const interdits = [
    [/\btext-\[/, 'une taille de texte arbitraire : ajouter un jeton --text-* ou utiliser sur-titre'],
    [/\btracking-\[/, 'un espacement de lettres arbitraire : tracking-sur-titre'],
    [/\bz-\[/, 'un z-index arbitraire : z-collant, z-nav, z-flottant, z-modale, z-toast, z-alerte, z-evitement'],
    [/\b(?:drop-)?shadow-\[/, 'une ombre arbitraire : un jeton --shadow-* ou --drop-shadow-*'],
    [/\brounded-\[/, 'un arrondi arbitraire : md, lg, xl, 2xl, 3xl ou full, par rôle'],
    [
        /\btransition-all\b/,
        'transition-all anime tout, y compris ce qui ne bouge pas : transition (couleurs, opacité, ombre, transformation)',
    ],
    [/style="animation-delay/, 'un délai inline : les classes stagger-1 à stagger-6'],
]

it.each(interdits)('ne laisse passer %s dans resources/js', (motif, conseil) => {
    expect(filesMatching(motif, { extensions: ['.vue', '.js'] }), conseil).toEqual([])
})

it('laisse le rembourrage vertical des pages au layout', () => {
    expect(filesMatching(/<div class="py-12">|space-y-8\b/)).toEqual([])
})
