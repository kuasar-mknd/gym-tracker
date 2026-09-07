import { it, expect } from 'vitest'
import { filesMatching } from './sourceFiles'

/**
 * `toFixed()` rend toujours un point décimal, quelle que soit la langue de la
 * page, et `toLocaleString()` sans langue suit celle du navigateur : le même
 * poids s'écrivait « 78,4 kg » sur l'accueil et « 78.40 kg » sur Mesures, et un
 * volume de quinze tonnes s'affichait « 15,750 kg » (#1787). Le format d'une
 * grandeur vit dans `Utils/nombre.js`, une fois.
 */
it('ne met en forme un nombre que dans Utils/nombre', () => {
    const fautifs = filesMatching(/\.toFixed\(|\.toLocaleString\(/, { extensions: ['.vue', '.js'] }).filter(
        (fichier) => !fichier.endsWith('Utils/nombre.js'),
    )

    expect(fautifs, 'passer par poids(), volume(), variation(), pourcentage(), entier() ou nombre()').toEqual([])
})
