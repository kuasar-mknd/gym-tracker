import { describe, expect, it } from 'vitest'

import { creerUneListePartagee, normaliserLesIdentifiants } from '@/Utils/listePartagee'

/*
 * La liste que les onglets se partagent dans le `localStorage` (#2049) : la
 * file hors ligne et ses refus. Le stockage fait foi, chaque changement le
 * relit et s'y applique par identifiant ; ce que le stockage refuse reste à
 * l'onglet, superposé à ses lectures suivantes.
 */

/**
 * Un `localStorage` en mémoire, qui compte ses écritures et peut refuser
 * celles d'une clé, comme un quota atteint.
 */
const unStockage = (valeurs = {}) => {
    const cles = new Map(Object.entries(valeurs))

    return {
        ecritures: 0,
        refuse: false,
        getItem: (cle) => (cles.has(cle) ? cles.get(cle) : null),
        setItem(cle, valeur) {
            if (this.refuse) {
                throw new DOMException('plein', 'QuotaExceededError')
            }

            this.ecritures += 1
            cles.set(cle, String(valeur))
        },
        removeItem(cle) {
            if (this.refuse) {
                throw new DOMException('plein', 'QuotaExceededError')
            }

            this.ecritures += 1
            cles.delete(cle)
        },
        lire: (cle) => JSON.parse(cles.get(cle) ?? 'null'),
    }
}

const ids = (liste) => liste.map((entree) => entree.id)

describe('la lecture', () => {
    it.each([
        ['absente', {}],
        ['corrompue', { file: '{coupé' }],
        ['qui n’est pas une liste', { file: '"une chaîne"' }],
    ])('lit une liste vide quand elle est %s', (_cas, valeurs) => {
        const stockage = unStockage(valeurs)

        expect(creerUneListePartagee('file', { stockage: () => stockage }).lire()).toEqual([])
    })

    it('lit une liste vide, et n’écrit rien, quand le stockage lui-même est inaccessible', () => {
        const liste = creerUneListePartagee('file', {
            stockage: () => {
                throw new DOMException('refusé', 'SecurityError')
            },
        })

        expect(liste.lire()).toEqual([])
        expect(liste.changer((entrees) => [...entrees, { id: 'a' }])).toEqual({ liste: [{ id: 'a' }], ecrite: false })
        expect(liste.lire()).toEqual([{ id: 'a' }])
    })

    it('donne à une entrée en double ou sans identifiant un identifiant dérivé, le même à chaque lecture', () => {
        const entrees = [{ id: 'a' }, { id: 'a', n: 2 }, { n: 3 }, { id: 'a~2' }, 'illisible', { id: 7 }, { id: 7 }]

        const lue = normaliserLesIdentifiants(entrees)

        expect(lue.map((entree) => entree?.id ?? entree)).toEqual([
            'a',
            'a~3',
            'sans-identifiant~2',
            'a~2',
            'illisible',
            7,
            '7~2',
        ])
        expect(normaliserLesIdentifiants(entrees)).toEqual(lue)
        expect(new Set(lue.map((entree) => entree?.id)).size).toBe(lue.length)
    })

    it('rend la liste telle quelle quand chaque entrée a son identifiant', () => {
        const entrees = [{ id: 'a' }, { id: 'b' }]

        expect(normaliserLesIdentifiants(entrees)).toBe(entrees)
    })
})

describe('le changement', () => {
    it('relit le stockage avant de changer : ce qu’un autre onglet y a mis reste', () => {
        const stockage = unStockage({ file: JSON.stringify([{ id: 'a' }]) })
        const liste = creerUneListePartagee('file', { stockage: () => stockage })

        liste.lire()
        // Un autre onglet ajoute b.
        stockage.setItem('file', JSON.stringify([{ id: 'a' }, { id: 'b' }]))
        liste.changer((entrees) => entrees.filter((entree) => entree.id !== 'a'))

        expect(ids(stockage.lire('file'))).toEqual(['b'])
    })

    it('n’écrit rien quand la transformation rend la liste reçue', () => {
        const stockage = unStockage({ file: JSON.stringify([{ id: 'a' }]) })
        const liste = creerUneListePartagee('file', { stockage: () => stockage })

        expect(liste.changer((entrees) => entrees)).toEqual({ liste: [{ id: 'a' }], ecrite: true })
        expect(stockage.ecritures).toBe(0)
    })

    it('écrit une liste vide, ou retire la clé quand on le lui demande', () => {
        const stockage = unStockage({ file: JSON.stringify([{ id: 'a' }]), refus: JSON.stringify([{ id: 'r' }]) })

        creerUneListePartagee('file', { stockage: () => stockage }).changer(() => [])
        creerUneListePartagee('refus', { stockage: () => stockage, retirerSiVide: true }).changer(() => [])

        expect(stockage.getItem('file')).toBe('[]')
        expect(stockage.getItem('refus')).toBeNull()
    })
})

describe('un stockage qui refuse d’écrire', () => {
    it('superpose au stockage ce que l’onglet voulait, à sa place, jusqu’à une écriture qui passe', () => {
        const stockage = unStockage({ file: JSON.stringify([{ id: 'a' }, { id: 'b' }, { id: 'c' }]) })
        const liste = creerUneListePartagee('file', { stockage: () => stockage })

        stockage.refuse = true
        // a est remplacée, à sa place, par sa suite ; b change ; d s'ajoute au bout.
        const { ecrite } = liste.changer(() => [{ id: 'a2' }, { id: 'b', change: true }, { id: 'c' }, { id: 'd' }])

        expect(ecrite).toBe(false)
        expect(ids(stockage.lire('file'))).toEqual(['a', 'b', 'c'])
        expect(liste.lire()).toEqual([{ id: 'a2' }, { id: 'b', change: true }, { id: 'c' }, { id: 'd' }])

        // Un autre onglet retire c et ajoute e, sans que cet onglet le voie.
        stockage.refuse = false
        stockage.setItem('file', JSON.stringify([{ id: 'a' }, { id: 'b' }, { id: 'e' }]))

        expect(liste.lire()).toEqual([{ id: 'a2' }, { id: 'b', change: true }, { id: 'e' }, { id: 'd' }])

        // L'écriture suivante passe : tout ce que l'onglet gardait part au stockage, une fois.
        liste.changer((entrees) => entrees.filter((entree) => entree.id !== 'a2'))

        expect(stockage.lire('file')).toEqual([{ id: 'b', change: true }, { id: 'e' }, { id: 'd' }])
        expect(liste.lire()).toEqual(stockage.lire('file'))
    })

    it('réessaie d’écrire ce qu’il garde, même sur une transformation qui ne change rien', () => {
        const stockage = unStockage()
        const liste = creerUneListePartagee('file', { stockage: () => stockage })

        stockage.refuse = true
        liste.changer(() => [{ id: 'a' }])
        stockage.refuse = false
        liste.changer((entrees) => entrees)

        expect(stockage.lire('file')).toEqual([{ id: 'a' }])
    })

    it('oublie ce qu’il gardait pour les entrées que désigne le prédicat, et elles seules', () => {
        const stockage = unStockage()
        const liste = creerUneListePartagee('file', { stockage: () => stockage })

        stockage.refuse = true
        liste.changer(() => [
            { id: 'a', compte: '1' },
            { id: 'b', compte: '2' },
        ])
        liste.oublierCeQuiNAPasEteEcrit((entree) => entree.compte === '1')

        expect(liste.lire()).toEqual([{ id: 'b', compte: '2' }])
    })

    it('pose au début ce que l’onglet voulait en tête, et au bout ce qui suivait une entrée disparue', () => {
        const stockage = unStockage({ file: JSON.stringify([{ id: 'b' }]) })
        const liste = creerUneListePartagee('file', { stockage: () => stockage })

        stockage.refuse = true
        liste.changer(() => [{ id: 'a' }, { id: 'b' }, { id: 'c' }])
        stockage.refuse = false
        // Un autre onglet a envoyé b entre-temps.
        stockage.setItem('file', JSON.stringify([]))

        expect(ids(liste.lire())).toEqual(['a', 'c'])
    })
})
