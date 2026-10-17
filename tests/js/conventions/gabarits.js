import { readFileSync } from 'node:fs'

/**
 * La lecture des gabarits et des listes de classes, que les gardes partagent.
 *
 * Écrite d'abord pour les zones sûres (#2043), reprise pour ce que le survol
 * révèle (#2082) : une balise se lit jusqu'au `>` qui n'est pas entre
 * guillemets, `class`, `:class` et `v-bind:class` se lisent ensemble, une
 * valeur arbitraire reste entière. Une garde qui lit les classes d'un
 * gabarit part d'ici plutôt que d'une expression régulière sur `class="…"`,
 * qui ne voit ni `:class` ni une liste répartie entre les deux.
 */

/**
 * Un texte sans ses commentaires HTML.
 *
 * Ils se retirent par découpage et non par un `replace` global, comme dans
 * etatsVides.test.js : CodeQL lit toute suppression de `<!--` par expression
 * régulière comme une désinfection incomplète. Un commentaire jamais refermé
 * emporte la fin du texte, comme dans le navigateur.
 *
 * @param {string} texte
 * @returns {string}
 */
export const sansCommentairesHtml = (texte) => {
    let reste = texte
    let garde = ''

    for (let debut = reste.indexOf('<!--'); debut !== -1; debut = reste.indexOf('<!--')) {
        garde += reste.slice(0, debut)

        const fin = reste.indexOf('-->', debut + 4)

        if (fin === -1) {
            return garde
        }

        reste = reste.slice(fin + 3)
    }

    return garde + reste
}

/** Une source sans ses commentaires : un commentaire qui cite `fixed` ne place rien. */
export const sansCommentaires = (source) =>
    sansCommentairesHtml(source)
        .replace(/\/\*[\s\S]*?\*\//g, '')
        .replace(/(^|[^:])\/\/.*$/gm, '$1')

/** Le gabarit, sans ses commentaires. */
export const gabarit = (source) => {
    const debut = source.indexOf('<template>')
    const fin = source.lastIndexOf('</template>')

    return debut === -1 ? '' : sansCommentairesHtml(source.slice(debut, fin))
}

/**
 * Les éléments HTML sans fermante, écrits en minuscules : `<Link>` est le
 * composant d'Inertia, qui se referme, et non l'élément `<link>`.
 */
export const ELEMENTS_VIDES = new Set([
    'area',
    'base',
    'br',
    'col',
    'embed',
    'hr',
    'img',
    'input',
    'link',
    'meta',
    'source',
    'track',
    'wbr',
])

/**
 * La liste de classes d'une balise : `class`, `:class` et `v-bind:class`
 * réunis. Lus à part, un élément dont `fixed` vient de l'un et `top-4` de
 * l'autre passait : c'est pourtant l'écriture ordinaire d'une notification ou
 * d'un collant conditionnels.
 *
 * @param {string} attributs
 * @returns {string}
 */
export const listeDeLaBalise = (attributs) =>
    [...attributs.matchAll(/(?:^|\s)(?::|v-bind:)?class\s*=\s*(?:"([^"]*)"|'([^']*)')/g)]
        .map((trouve) => trouve[1] ?? trouve[2])
        .join(' ')

/**
 * Les éléments d'un gabarit, balise par balise, chacun avec sa liste de
 * classes et son parent : une balise se lit jusqu'au `>` qui n'est pas entre
 * guillemets (`:class="a > b ? …"`). Un gabarit de Vue referme chaque balise :
 * une fermante qui ne trouve pas son ouvrante (`orphelines`), ou qui referme
 * en passant une balise restée ouverte (`ouverts`), dit que la lecture s'est
 * trompée de parent.
 *
 * Chaque élément porte aussi ses attributs, tels qu'écrits, et son contenu :
 * le texte entre son ouvrante et sa fermante, vide pour une balise qui se
 * referme seule (`<div />`, `<img>`), `null` pour une balise restée ouverte.
 *
 * @param {string} texte
 * @returns {{elements: {nom: string, liste: string, attributs: string, contenu: string|null, parent: object|null}[], ouverts: string[], orphelines: string[]}}
 */
export const elementsDuGabarit = (texte) => {
    const elements = []
    const pile = []
    const ouverts = []
    const orphelines = []
    const debutsDuContenu = new Map()
    let position = texte.indexOf('<')

    while (position !== -1) {
        const fermante = texte[position + 1] === '/'
        const debutDuNom = position + (fermante ? 2 : 1)
        const nom = /^[A-Za-z][\w.:-]*/.exec(texte.slice(debutDuNom))?.[0]

        if (nom === undefined) {
            position = texte.indexOf('<', position + 1)

            continue
        }

        let fin = debutDuNom + nom.length
        let guillemet = null

        for (; fin < texte.length; fin += 1) {
            const caractere = texte[fin]

            if (guillemet !== null) {
                guillemet = caractere === guillemet ? null : guillemet
            } else if (caractere === '"' || caractere === "'") {
                guillemet = caractere
            } else if (caractere === '>') {
                break
            }
        }

        const attributs = texte.slice(debutDuNom + nom.length, fin)

        if (fermante) {
            const rang = pile.findLastIndex((ouvert) => ouvert.nom === nom)

            if (rang === -1) {
                orphelines.push(nom)
            } else {
                ouverts.push(...pile.slice(rang + 1).map((ouvert) => ouvert.nom))
                pile[rang].contenu = texte.slice(debutsDuContenu.get(pile[rang]), position)
                pile.length = rang
            }
        } else {
            const element = {
                nom,
                liste: listeDeLaBalise(attributs),
                attributs,
                contenu: '',
                parent: pile.at(-1) ?? null,
            }

            elements.push(element)

            if (!attributs.trimEnd().endsWith('/') && !ELEMENTS_VIDES.has(nom)) {
                element.contenu = null
                debutsDuContenu.set(element, fin + 1)
                pile.push(element)
            }
        }

        position = texte.indexOf('<', fin + 1)
    }

    return { elements, ouverts: [...ouverts, ...pile.map((ouvert) => ouvert.nom)], orphelines }
}

/** Les chaînes d'un script : une classe calculée, ou la page « hors ligne » que le worker sert. */
export const chainesDuScript = (script) =>
    [...sansCommentaires(script).matchAll(/'([^'\n]*)'|"([^"\n]*)"|`([^`]*)`/g)].map(
        (trouve) => trouve[1] ?? trouve[2] ?? trouve[3],
    )

/**
 * Le script d'un composant, ou tout le module.
 *
 * Les blocs `<script>` se découpent par `indexOf`, et non par une expression
 * régulière : CodeQL lit toute expression qui reconnaît `<script>` comme un
 * filtre de balises HTML, et refuse celle qui ne reconnaît pas `<SCRIPT>`. Un
 * composant Vue n'écrit que `<script>` et `<script setup>`, en minuscules.
 *
 * @param {string} source
 * @param {boolean} vue
 * @returns {string}
 */
export const scriptDe = (source, vue) => {
    if (!vue) {
        return source
    }

    const blocs = []
    let debut = source.indexOf('<script')

    while (debut !== -1) {
        const suivant = source.charAt(debut + '<script'.length)
        const corps = source.indexOf('>', debut) + 1
        const fin = corps === 0 ? -1 : source.indexOf('</script>', corps)

        if (fin === -1) {
            break
        }

        if (/\w/.test(suivant)) {
            debut = source.indexOf('<script', debut + 1)

            continue
        }

        blocs.push(source.slice(corps, fin))
        debut = source.indexOf('<script', fin + '</script>'.length)
    }

    return blocs.join('\n')
}

/**
 * Les listes de classes écrites dans une source : une par élément du gabarit,
 * et une par chaîne des scripts et des modules.
 *
 * @param {string} source
 * @param {boolean} vue
 * @returns {string[]}
 */
export const listesDeLaSource = (source, vue) => [
    ...(vue ? elementsDuGabarit(gabarit(source)).elements.map(({ liste }) => liste) : []),
    ...chainesDuScript(scriptDe(source, vue)),
]

export const listesDeClasses = (chemin) => listesDeLaSource(readFileSync(chemin, 'utf8'), chemin.endsWith('.vue'))

/**
 * Un mot d'une liste de classes : des caractères ordinaires, une valeur
 * arbitraire entre crochets (`max-h-[calc(100vh-4rem)]` reste entier) ou une
 * variable entre parenthèses après un tiret (`h-(--hauteur)`).
 */
export const MOT = /(?:[^\s'"`,{}()?[\]]|\[[^\]\s]*\]|(?<=-)\([^)\s]*\))+/g

/**
 * Les mots d'une liste de classes, variantes comprises (`sm:sticky` compte).
 * Une clé d'objet (`{ sticky: colle }`) perd ses deux-points : aucune classe
 * de Tailwind ne finit par eux.
 */
export const classes = (liste) => (liste.match(MOT) ?? []).map((mot) => mot.replace(/:+$/, '')).filter(Boolean)

/** `sm:top-0` → `{ variante: 'sm', base: 'top-0' }`. Un deux-points entre crochets reste dans la base. */
export const decomposer = (classe) => {
    const morceaux = classe.split(/:(?![^[]*\])/)
    const base = morceaux.pop().replace(/^!|!$/g, '')

    return { variante: morceaux.join(':'), base }
}

export const sansVariante = (classe) => decomposer(classe).base
