import { it, expect } from 'vitest'
import { readFileSync, readdirSync, statSync } from 'node:fs'
import { join } from 'node:path'

/**
 * Un graphique chargé à la demande met plusieurs secondes à arriver dans la CI,
 * et le module atterrit après la fin du fichier de test : Vitest compte alors
 * une erreur non rattrapée et la porte tombe, tous les tests au vert. Le
 * fichier qui monte une page doit donc moquer les graphiques qu'elle appelle.
 */
const fichiers = (racine, extension) => {
    const trouves = []
    const parcourir = (chemin) => {
        for (const entree of readdirSync(chemin)) {
            const complet = join(chemin, entree)
            if (statSync(complet).isDirectory()) parcourir(complet)
            else if (complet.endsWith(extension)) trouves.push(complet)
        }
    }
    parcourir(racine)
    return trouves
}

const graphiquesDe = new Map()
for (const chemin of fichiers('resources/js', '.vue')) {
    const source = readFileSync(chemin, 'utf8')
    const graphiques = [
        ...source.matchAll(/defineAsyncComponent\(\(\) => import\('(@\/Components\/Stats\/[^']+)'\)\)/g),
    ].map((correspondance) => correspondance[1])
    if (graphiques.length > 0) {
        graphiquesDe.set('@/' + chemin.replace('resources/js/', ''), graphiques)
    }
}

it('moque les graphiques chargés à la demande dans chaque test qui monte leur page', () => {
    const manquants = []

    for (const chemin of fichiers('tests/js', '.test.js')) {
        const source = readFileSync(chemin, 'utf8')

        for (const [page, graphiques] of graphiquesDe) {
            if (!source.includes(`from '${page}'`)) continue

            for (const graphique of graphiques) {
                if (!source.includes(`vi.mock('${graphique}'`)) {
                    manquants.push(`${chemin} monte ${page} sans moquer ${graphique}`)
                }
            }
        }
    }

    expect(manquants).toEqual([])
})
