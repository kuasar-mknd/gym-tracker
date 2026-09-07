import { it, expect } from 'vitest'
import { readFileSync } from 'node:fs'

import { collectSourceFiles, jsRoot } from './sourceFiles'

/**
 * « Il n'y a rien ici » se disait de trois façons : une carte complète avec
 * bouton, une icône et deux lignes, ou une phrase grise seule (#1791). Un
 * compte neuf voyait les trois sur trois écrans voisins.
 *
 * `GlassEmptyState` porte les deux tailles légitimes : `carte` pour une page,
 * `ligne` pour l'intérieur d'une carte déjà titrée.
 */
const EXCEPTIONS = [
    // Un résultat de recherche vide n'est pas un état vide : la liste existe,
    // c'est la requête qui ne rend rien.
    /Aucun résultat pour/,
    // Le choix « pas de valeur » d'un GlassSelect.
    /Aucune? —/,
    // Un champ vide d'une entrée qui existe : la note d'un jour du calendrier.
    /Aucune note écrite/,
    // Un lien d'inscription, pas un état vide.
    /Pas encore de compte/,
]

/**
 * Le gabarit, sans ses commentaires ni ses états vides déclarés.
 *
 * Les commentaires se retirent par découpage et non par un `replace` global :
 * CodeQL lit toute suppression de `<!--` par expression régulière comme une
 * désinfection incomplète, et il a raison en général — ici il ne s'agit que de
 * lire un fichier source, mais l'alerte bloque la revue, et la boucle dit ce
 * qu'elle fait aussi clairement.
 */
const gabaritNu = (source) => {
    const template = /<template>([\s\S]*)<\/template>/.exec(source)

    if (template === null) {
        return ''
    }

    let reste = template[1]
    let sansCommentaires = ''

    for (let debut = reste.indexOf('<!--'); debut !== -1; debut = reste.indexOf('<!--')) {
        sansCommentaires += reste.slice(0, debut)

        const fin = reste.indexOf('-->', debut)

        if (fin === -1) {
            reste = ''
            break
        }

        reste = reste.slice(fin + 3)
    }

    return (sansCommentaires + reste).replace(/<GlassEmptyState[\s\S]*?(?:\/>|<\/GlassEmptyState>)/g, '')
}

it('ne dit « il n’y a rien » que par GlassEmptyState', () => {
    const fautifs = collectSourceFiles({ extensions: ['.vue'] }).flatMap((fichier) => {
        const nu = gabaritNu(readFileSync(fichier, 'utf8'))

        return [...nu.matchAll(/(Aucune?|Pas encore de|Pas assez de|Rien)\b[^<>{]{0,60}/g)]
            .map((trouve) => trouve[0].trim())
            .filter((texte) => !EXCEPTIONS.some((exception) => exception.test(texte)))
            .map((texte) => `${fichier.replace(jsRoot, 'resources/js')} — « ${texte} »`)
    })

    expect(fautifs, 'passer par <GlassEmptyState> (taille="carte" ou "ligne")').toEqual([])
})
