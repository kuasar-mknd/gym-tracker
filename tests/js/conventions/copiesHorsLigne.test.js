import { describe, expect, it } from 'vitest'

import { filesMatching } from './sourceFiles'

/**
 * Les copies hors ligne des pages gardées (#2048) sont chiffrées, d'un seul
 * compte, et derrière une purge qui fait barrière : une seule transaction qui
 * vide les copies et ferme la clé, une génération que chaque écriture
 * revérifie, une annonce aux autres onglets. Tout cela ne tient que si rien
 * d'autre ne touche à leur base : un second module qui ouvrirait IndexedDB
 * écrirait hors de la barrière, et le worker, qui ne doit jamais rien lire
 * d'un compte, n'a pas à la connaître.
 *
 * Seul `resources/js/Utils/copiesHorsLigne.js` nomme donc IndexedDB, ses
 * interfaces et la base.
 */
describe('IndexedDB ne s’ouvre que par le magasin des copies', () => {
    it('ne se nomme que dans copiesHorsLigne.js', () => {
        expect(
            filesMatching(
                /\bindexedDB\b|\bIDB(?:Factory|Database|ObjectStore|Transaction|Request|OpenDBRequest|KeyRange|Index|Cursor)\b/,
                { extensions: ['.vue', '.js'] },
            ),
        ).toEqual(['resources/js/Utils/copiesHorsLigne.js'])
    })

    it('ne nomme sa base nulle part ailleurs', () => {
        expect(filesMatching(/gym-tracker-copies/, { extensions: ['.vue', '.js'] })).toEqual([
            'resources/js/Utils/copiesHorsLigne.js',
        ])
    })
})
