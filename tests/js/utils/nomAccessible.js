/**
 * Le nom qu'un lecteur d'écran annonce pour un élément rendu (#2115).
 *
 * Une version réduite du calcul du nom accessible (« Accessible Name and
 * Description Computation » du W3C), limitée aux formes que ce dépôt emploie :
 * `aria-labelledby`, puis `aria-label`, puis le texte des descendants. Un
 * descendant `aria-hidden="true"` ou `hidden` ne compte pas : c'est ainsi que
 * `GlassIcon` décorative tait sa ligature (« arrow_back »). Un descendant qui
 * porte un `aria-label` compte pour ce nom, comme une icône nommée
 * (`GlassIcon` avec `label`, rendue `role="img"`). Le titre (`title`) n'est
 * pas lu : il ne se montre qu'au survol, et un appareil tactile n'en a pas.
 *
 * jsdom ne sait pas calculer ce nom, et aucune dépendance du dépôt ne le fait :
 * lire `text()` d'un lien réduit à une icône rendait « arrow_back », qu'aucune
 * technologie d'assistance n'annonce.
 *
 * @param {Element} element
 * @returns {string} Le nom, espaces resserrés ; une chaîne vide s'il n'en a pas.
 */
export const nomAccessible = (element) => {
    const designes = element.getAttribute('aria-labelledby')?.trim()

    if (designes) {
        return resserre(
            designes
                .split(/\s+/)
                .map((id) => elementDesigne(element, id)?.textContent ?? '')
                .join(' '),
        )
    }

    const etiquette = element.getAttribute('aria-label')?.trim()

    return etiquette ? resserre(etiquette) : resserre(texteDesDescendants(element))
}

const resserre = (texte) => texte.replace(/\s+/g, ' ').trim()

/** L'élément qu'un identifiant désigne, dans le document ou dans l'arbre détaché que Test Utils monte. */
const elementDesigne = (element, id) =>
    element.ownerDocument.getElementById(id) ??
    [...(element.getRootNode().querySelectorAll?.('[id]') ?? [])].find((candidat) => candidat.id === id)

const texteDesDescendants = (noeud) =>
    [...noeud.childNodes]
        .map((enfant) => {
            if (enfant.nodeType === enfant.TEXT_NODE) {
                return enfant.textContent
            }

            if (enfant.nodeType !== enfant.ELEMENT_NODE) {
                return ''
            }

            if (enfant.getAttribute('aria-hidden') === 'true' || enfant.hidden) {
                return ''
            }

            const etiquette = enfant.getAttribute('aria-label')?.trim()

            if (etiquette) {
                return ` ${etiquette} `
            }

            return ` ${texteDesDescendants(enfant)} `
        })
        .join('')
