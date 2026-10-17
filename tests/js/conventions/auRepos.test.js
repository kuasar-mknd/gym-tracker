import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

import { filesMatching, jsRoot } from './sourceFiles'

/**
 * `requestIdleCallback` n'existe ni sous Safari ni sous Safari iOS (désactivé
 * jusqu'à la version 27.2 selon caniuse), ni sous jsdom : un appel direct y
 * lève une `ReferenceError`, et ce qui le suit ne tourne plus, sans que rien
 * ne le montre sur un ordinateur. Les copies hors ligne s'écrivent pourtant
 * au repos (#2048), surtout sur un téléphone.
 *
 * Seul `resources/js/Utils/auRepos.js` le nomme donc, et seulement sous la
 * forme `globalThis.requestIdleCallback ?? …`, qui retombe sur un
 * `setTimeout`. Une tâche à faire au repos passe par `auRepos()`.
 */

/** Le code d'un fichier, sans ses commentaires : un docbloc peut nommer l'interface. */
const codeSansCommentaires = (source) =>
    source
        .replace(/\/\*[\s\S]*?\*\//g, '')
        .split('\n')
        .map((ligne) => ligne.replace(/(^|[^:])\/\/.*$/, '$1'))
        .join('\n')

describe('une tâche au repos passe par auRepos()', () => {
    it('ne nomme requestIdleCallback que dans auRepos.js', () => {
        expect(filesMatching(/requestIdleCallback/, { extensions: ['.vue', '.js'] })).toEqual([
            'resources/js/Utils/auRepos.js',
        ])
    })

    it('ne l’y appelle jamais directement, seulement par globalThis et son repli', () => {
        const code = codeSansCommentaires(readFileSync(resolve(jsRoot, 'Utils/auRepos.js'), 'utf8'))
        const occurrences = code.match(/[\w.]*requestIdleCallback(?:\s*\?\?)?/g) ?? []

        expect(occurrences).toEqual(['globalThis.requestIdleCallback ??'])
    })
})
