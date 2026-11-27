import { readdirSync, readFileSync } from 'node:fs'
import { join } from 'node:path'
import { describe, expect, it } from 'vitest'

/*
 * Une page qui pose `<Head title="…">` sans importer `Head` ne règle pas le
 * titre du document : Vue rend un élément inconnu et l'onglet affiche
 * « - GymTracker ». Les tests de page moquent `Head`, ce qui masque l'oubli.
 */
const pages = (dossier) =>
    readdirSync(dossier, { withFileTypes: true }).flatMap((entree) => {
        const chemin = join(dossier, entree.name)

        if (entree.isDirectory()) {
            return pages(chemin)
        }

        return chemin.endsWith('.vue') ? [chemin] : []
    })

describe('le titre du document', () => {
    it('importe Head dans chaque page qui le pose', () => {
        const racine = join(__dirname, '../../../resources/js/Pages')
        const sansImport = pages(racine)
            .filter((chemin) => {
                const source = readFileSync(chemin, 'utf8')

                return (
                    /<Head[\s>]/.test(source) &&
                    !/import\s*\{[^}]*\bHead\b[^}]*\}\s*from\s*'@inertiajs\/vue3'/.test(source)
                )
            })
            .map((chemin) => chemin.slice(racine.length + 1))

        expect(sansImport).toEqual([])
    })
})
