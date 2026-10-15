import { describe, it, expect } from 'vitest'
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'

import { collectSourceFiles, jsRoot } from './sourceFiles'

/**
 * Ce qui se place par rapport à l'écran s'écarte des barres du téléphone (#2043).
 *
 * L'application s'étend sous la barre d'état, l'encoche et l'indicateur
 * d'accueil (`viewport-fit=cover`). Un élément `fixed` ou `sticky` posé à
 * `top-0`, une fenêtre posée à `py-6` ou bornée à `85vh` ignorent ces barres :
 * le titre de « Ajouter un exercice » passait sous la barre d'état de l'iPhone,
 * et un grand vide restait sous la fenêtre. Les utilitaires `*-sure-*` de
 * `resources/css/app.css` ajoutent la marge du côté visé ; cette garde exige
 * qu'on les emploie, côté par côté.
 *
 * Le contrôle lit les sources : le parcours
 * `tests/Browser/LesFenetresRestentDansLaZoneSureTest.php` mesure le rendu.
 */
const appCss = readFileSync(resolve(jsRoot, '../css/app.css'), 'utf8')

const COTES = ['top', 'bottom', 'left', 'right']

/** Les `@utility` de la feuille, fonctionnels compris (`pt-sure-*`). */
const UTILITAIRES = [...appCss.matchAll(/^@utility ([a-z0-9-]+\*?) \{([\s\S]*?)^\}/gm)].map(([, nom, corps]) => ({
    nom,
    corps,
}))

/** Les familles que la feuille déclare : `pt` pour `@utility pt-sure-*`. */
const FAMILLES_SURES = UTILITAIRES.map(({ nom }) => nom.match(/^([a-z]+)-sure-\*$/)?.[1]).filter(Boolean)

/** Un utilitaire de zone sûre déclaré, avec ses variantes (`sm:`) et une valeur entière. */
const UTILITAIRE_SUR = new RegExp(`^(?:[a-z0-9-]+:)*(?:${FAMILLES_SURES.join('|')})-sure-\\d+$`)

/** Tout mot qui se réclame de la famille, juste ou non. */
const MOT_DE_LA_FAMILLE = /-sure(?:-|$)/

/**
 * Les valeurs d'une déclaration, séparées au premier niveau seulement :
 * `calc(1rem + x) 0` en donne deux, pas cinq.
 *
 * @param {string} valeur
 * @returns {string[]}
 */
const valeursDe = (valeur) => {
    const valeurs = []
    let profondeur = 0
    let courante = ''

    for (const caractere of valeur.trim()) {
        if (caractere === '(') {
            profondeur += 1
        } else if (caractere === ')') {
            profondeur -= 1
        }

        if (/\s/.test(caractere) && profondeur === 0) {
            if (courante !== '') {
                valeurs.push(courante)
            }
            courante = ''
        } else {
            courante += caractere
        }
    }

    return courante === '' ? valeurs : [...valeurs, courante]
}

const unCote = (cote) => (valeurs) => ({ [cote]: valeurs[0] })

/** `inset: 0`, `padding: 1rem 0`… : haut, droite, bas, gauche, comme en CSS. */
const quatreCotes = (valeurs) => {
    const [haut, droite = haut, bas = haut, gauche = droite] = valeurs

    return { top: haut, right: droite, bottom: bas, left: gauche }
}

const deuxCotes = (debut, fin) => (valeurs) => {
    const [premier, second = premier] = valeurs

    return { [debut]: premier, [fin]: second }
}

/** Les propriétés logiques d'une famille, pour une écriture de gauche à droite. */
const logiques = (famille) => ({
    [`${famille}-block`]: deuxCotes('top', 'bottom'),
    [`${famille}-inline`]: deuxCotes('left', 'right'),
    [`${famille}-block-start`]: unCote('top'),
    [`${famille}-block-end`]: unCote('bottom'),
    [`${famille}-inline-start`]: unCote('left'),
    [`${famille}-inline-end`]: unCote('right'),
})

const longues = (famille) => Object.fromEntries(COTES.map((cote) => [`${famille}-${cote}`, unCote(cote)]))

/**
 * Les propriétés qui posent un côté, raccourcis compris : un `inset: 0` place
 * les quatre côtés comme `top`, `right`, `bottom` et `left` réunis.
 */
const PROPRIETES = {
    placement: {
        ...Object.fromEntries(COTES.map((cote) => [cote, unCote(cote)])),
        inset: quatreCotes,
        ...logiques('inset'),
    },
    remplissage: {
        ...longues('padding'),
        padding: quatreCotes,
        ...logiques('padding'),
        ...longues('margin'),
        margin: quatreCotes,
        ...logiques('margin'),
    },
}

/**
 * Les côtés que pose le corps d'un utilitaire, avec la valeur de chacun.
 *
 * @param {string} corps
 * @param {'placement'|'remplissage'} genre
 * @returns {{cote: string, valeur: string}[]}
 */
const cotesDuCorps = (corps, genre) =>
    [...corps.replace(/\/\*[\s\S]*?\*\//g, '').matchAll(/(?:^|[\s;{])([a-z-]+)\s*:\s*([^;{}]+)/g)]
        .filter(([, propriete]) => propriete in PROPRIETES[genre])
        .flatMap(([, propriete, valeur]) =>
            Object.entries(PROPRIETES[genre][propriete](valeursDe(valeur))).map(([cote, valeurDuCote]) => ({
                cote,
                valeur: valeurDuCote,
            })),
        )

/** La valeur compte-t-elle la marge de son côté ? */
const compteLaMarge = ({ cote, valeur }) => valeur.includes(`var(--safe-area-${cote})`)

/**
 * Ce que chaque utilitaire de la feuille fait des barres, lu dans ses
 * déclarations et non dans son nom : `place` les côtés dont la position
 * (`top`, `inset`…) compte la marge, `remplit` ceux dont le remplissage ou la
 * marge extérieure la comptent. `hauteur-sure-*` borne une hauteur : il
 * n'écarte aucun côté, et ne protège donc rien.
 */
const PROTECTIONS = UTILITAIRES.map(({ nom, corps }) => {
    const surs = (genre) => {
        const comptes = cotesDuCorps(corps, genre).filter(compteLaMarge)

        return COTES.filter((cote) => comptes.some((compte) => compte.cote === cote))
    }

    return {
        motif: new RegExp(nom.endsWith('*') ? `^${nom.slice(0, -1)}\\d+$` : `^${nom}$`),
        place: surs('placement'),
        remplit: surs('remplissage'),
    }
}).filter(({ place, remplit }) => place.length + remplit.length > 0)

/**
 * Les défauts d'un utilitaire de la feuille qui se place sur l'écran : chaque
 * côté qu'il pose, raccourcis compris, lit la marge de ce côté.
 *
 * @param {{nom: string, corps: string}} utilitaire
 * @returns {string[]}
 */
const defautsDeLUtilitaire = ({ nom, corps }) =>
    /position:\s*(?:fixed|sticky)/.test(corps)
        ? cotesDuCorps(corps, 'placement')
              .filter(({ valeur }) => valeur !== 'auto')
              .filter((declaration) => !compteLaMarge(declaration))
              .map(({ cote, valeur }) => `${nom} : ${cote} vaut ${valeur} sans la marge de ce côté`)
        : []

/**
 * Une valeur de Tailwind qui pose un bord près de celui de l'écran : `0`, `4`,
 * `px`, `[…]` ou `(…)`. Ni `auto`, ni `sure-4`, ni une fraction : `left-1/2`
 * centre l'élément, loin de l'encoche.
 */
const VALEUR = String.raw`(?:\d+(?:\.\d+)?(?![\d./])|px\b|\[|\()`

/** Les utilitaires de Tailwind qui placent un élément, et les côtés qu'ils placent. */
const PLACEMENTS = [
    [new RegExp(`^-?inset-${VALEUR}`), COTES],
    [new RegExp(`^-?inset-x-${VALEUR}`), ['left', 'right']],
    [new RegExp(`^-?inset-y-${VALEUR}`), ['top', 'bottom']],
    [new RegExp(`^-?(?:start|inset-s)-${VALEUR}`), ['left']],
    [new RegExp(`^-?(?:end|inset-e)-${VALEUR}`), ['right']],
    [new RegExp(`^-?inset-bs-${VALEUR}`), ['top']],
    [new RegExp(`^-?inset-be-${VALEUR}`), ['bottom']],
    ...COTES.map((cote) => [new RegExp(`^-?${cote}-${VALEUR}`), [cote]]),
]

/** Les remplissages ordinaires, qui remplaceraient un remplissage sûr du même côté. */
const REMPLISSAGES = [
    [new RegExp(`^p-${VALEUR}`), COTES],
    [new RegExp(`^px-${VALEUR}`), ['left', 'right']],
    [new RegExp(`^py-${VALEUR}`), ['top', 'bottom']],
    [new RegExp(`^(?:pt|pbs)-${VALEUR}`), ['top']],
    [new RegExp(`^(?:pb|pbe)-${VALEUR}`), ['bottom']],
    [new RegExp(`^p[ls]-${VALEUR}`), ['left']],
    [new RegExp(`^p[re]-${VALEUR}`), ['right']],
]

/**
 * Les éléments qui couvrent TOUT l'écran à dessein, barres comprises.
 *
 * Chacun ne porte ni texte ni commande à lire : l'écarter des barres laisserait
 * voir la page à travers, ce qui est précisément ce qu'il doit cacher ou
 * habiller. Repérés par leur liste de classes exacte : une modification les
 * fait revenir devant cette garde.
 */
const PLEIN_ECRAN = [
    {
        fichier: 'resources/js/Components/UI/LiquidBackground.vue',
        classes: 'pointer-events-none fixed inset-0 z-0 overflow-hidden',
        raison: "le fond décoratif de l'application, qui doit aussi colorer le dessous des barres",
    },
    {
        fichier: 'resources/js/Components/UI/Modal.vue',
        classes: 'fixed inset-0 transform transition',
        raison: 'le voile derrière une fenêtre : il masque toute la page, et un toucher dessus ferme la fenêtre',
    },
    {
        fichier: 'resources/js/Components/UI/Dropdown.vue',
        classes: 'z-nav fixed inset-0',
        raison: "la surface transparente qui ferme un menu au toucher, n'importe où sur l'écran",
    },
    {
        fichier: 'resources/js/Components/Achievements/CelebrationModal.vue',
        classes: 'animate-fade-in bg-overlay absolute inset-0 backdrop-blur-sm',
        raison: 'le voile derrière la carte de succès, posé dans son plein écran : un toucher dessus ferme la carte',
    },
]

/** Les utilitaires de la feuille qui couvrent tout l'écran, pour la même raison. */
const UTILITAIRES_PLEIN_ECRAN = {
    'glass-overlay': 'le voile flouté derrière une fenêtre',
}

/**
 * Les fichiers qui ouvrent leur propre `<dialog>`.
 *
 * Une fenêtre passe par `Modal.vue`. `CelebrationModal` garde le sien pour sa
 * mise en scène centrée, et se borne de la même façon.
 */
const FICHIERS_A_DIALOGUE = [
    'resources/js/Components/UI/Modal.vue',
    'resources/js/Components/Achievements/CelebrationModal.vue',
]

const relatif = (chemin) => chemin.replace(jsRoot, 'resources/js')

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
const sansCommentairesHtml = (texte) => {
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
const sansCommentaires = (source) =>
    sansCommentairesHtml(source)
        .replace(/\/\*[\s\S]*?\*\//g, '')
        .replace(/(^|[^:])\/\/.*$/gm, '$1')

/** Le gabarit, sans ses commentaires. */
const gabarit = (source) => {
    const debut = source.indexOf('<template>')
    const fin = source.lastIndexOf('</template>')

    return debut === -1 ? '' : sansCommentairesHtml(source.slice(debut, fin))
}

/**
 * Les éléments HTML sans fermante, écrits en minuscules : `<Link>` est le
 * composant d'Inertia, qui se referme, et non l'élément `<link>`.
 */
const ELEMENTS_VIDES = new Set([
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
const listeDeLaBalise = (attributs) =>
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
 * @param {string} texte
 * @returns {{elements: {nom: string, liste: string, parent: object|null}[], ouverts: string[], orphelines: string[]}}
 */
const elementsDuGabarit = (texte) => {
    const elements = []
    const pile = []
    const ouverts = []
    const orphelines = []
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
                pile.length = rang
            }
        } else {
            const element = { nom, liste: listeDeLaBalise(attributs), parent: pile.at(-1) ?? null }

            elements.push(element)

            if (!attributs.trimEnd().endsWith('/') && !ELEMENTS_VIDES.has(nom)) {
                pile.push(element)
            }
        }

        position = texte.indexOf('<', fin + 1)
    }

    return { elements, ouverts: [...ouverts, ...pile.map((ouvert) => ouvert.nom)], orphelines }
}

/** Les chaînes d'un script : une classe calculée, ou la page « hors ligne » que le worker sert. */
const chainesDuScript = (script) =>
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
const scriptDe = (source, vue) => {
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
const listesDeLaSource = (source, vue) => [
    ...(vue ? elementsDuGabarit(gabarit(source)).elements.map(({ liste }) => liste) : []),
    ...chainesDuScript(scriptDe(source, vue)),
]

const listesDeClasses = (chemin) => listesDeLaSource(readFileSync(chemin, 'utf8'), chemin.endsWith('.vue'))

/**
 * Un mot d'une liste de classes : des caractères ordinaires, une valeur
 * arbitraire entre crochets (`max-h-[calc(100vh-4rem)]` reste entier) ou une
 * variable entre parenthèses après un tiret (`h-(--hauteur)`).
 */
const MOT = /(?:[^\s'"`,{}()?[\]]|\[[^\]\s]*\]|(?<=-)\([^)\s]*\))+/g

/**
 * Les mots d'une liste de classes, variantes comprises (`sm:sticky` compte).
 * Une clé d'objet (`{ sticky: colle }`) perd ses deux-points : aucune classe
 * de Tailwind ne finit par eux.
 */
const classes = (liste) => (liste.match(MOT) ?? []).map((mot) => mot.replace(/:+$/, '')).filter(Boolean)

/** `sm:top-0` → `{ variante: 'sm', base: 'top-0' }`. Un deux-points entre crochets reste dans la base. */
const decomposer = (classe) => {
    const morceaux = classe.split(/:(?![^[]*\])/)
    const base = morceaux.pop().replace(/^!|!$/g, '')

    return { variante: morceaux.join(':'), base }
}

const sansVariante = (classe) => decomposer(classe).base

/** La classe protège-t-elle un côté au moins ? */
const protege = (classe) => PROTECTIONS.some(({ motif }) => motif.test(sansVariante(classe)))

/** Les mots d'une liste, et ce que chacun place ou remplit. */
const lireLaListe = (liste) => {
    const mots = classes(liste).map((classe) => ({ classe, ...decomposer(classe) }))
    const cotesDe = (regles, cotesDeLaRegle) =>
        mots.flatMap((mot) =>
            regles
                .filter((regle) => regle.motif.test(mot.base))
                .flatMap((regle) => cotesDeLaRegle(regle).map((cote) => ({ ...mot, cote }))),
        )

    return {
        mots,
        placements: cotesDe(
            PLACEMENTS.map(([motif, cotes]) => ({ motif, cotes })),
            ({ cotes }) => cotes,
        ),
        remplissagesSurs: cotesDe(PROTECTIONS, ({ remplit }) => remplit),
        remplissagesOrdinaires: cotesDe(
            REMPLISSAGES.map(([motif, cotes]) => ({ motif, cotes })),
            ({ cotes }) => cotes,
        ),
    }
}

/**
 * Les défauts des côtés donnés d'une liste de classes.
 *
 * Un côté placé par un utilitaire de Tailwind (`top-0`, `inset-x-0`…) exige un
 * remplissage sûr de ce même côté, sans variante ou de la même variante, et
 * aucun remplissage ordinaire de ce côté qui le remplacerait. Un côté placé
 * par un utilitaire sûr (`top-sure-20`) l'est déjà. Un côté que rien ne place
 * n'exige rien : `pt-sure-4` ne sauve pas une barre posée à `bottom-0`, et
 * `hauteur-sure-*` ne sauve aucun côté.
 *
 * @param {string} liste
 * @param {string[]} cotesExamines
 * @returns {string[]}
 */
const defautsDesCotes = (liste, cotesExamines) => {
    const { placements, remplissagesSurs, remplissagesOrdinaires } = lireLaListe(liste)

    return cotesExamines.flatMap((cote) => {
        const places = placements.filter((placement) => placement.cote === cote)

        if (places.length === 0) {
            return []
        }

        const surs = remplissagesSurs.filter((remplissage) => remplissage.cote === cote)
        const sansRemplissage = places
            .filter(({ variante }) => !surs.some((sur) => sur.variante === '' || sur.variante === variante))
            .map(({ classe }) => `${classe} place le côté ${cote} sans remplissage sûr de ce côté`)
        const remplaces = surs.length
            ? remplissagesOrdinaires
                  .filter((remplissage) => remplissage.cote === cote)
                  .map(({ classe }) => `${classe} remplace le remplissage sûr du côté ${cote}`)
            : []

        return [...sansRemplissage, ...remplaces]
    })
}

const estFixeOuCollant = (liste) => classes(liste).some((classe) => ['fixed', 'sticky'].includes(sansVariante(classe)))

/**
 * Les défauts d'une liste de classes qui place un élément sur l'écran.
 *
 * @param {string} liste
 * @returns {string[]}
 */
const defautsDeLaListe = (liste) => (estFixeOuCollant(liste) ? defautsDesCotes(liste, COTES) : [])

const POSITIONNES = ['relative', 'absolute', 'fixed', 'sticky']

/**
 * Le bloc où se place un élément `absolute` : son plus proche parent
 * positionné du même gabarit. Un `<dialog>` ouvert en modale est fixe et
 * couvre l'écran. Au-delà d'un `Teleport`, le parent n'est plus celui du
 * gabarit : l'élément n'est pas suivi.
 */
const blocDePlacement = (element) => {
    for (let parent = element.parent; parent !== null; parent = parent.parent) {
        if (parent.nom === 'Teleport') {
            return null
        }

        if (
            parent.nom === 'dialog' ||
            classes(parent.liste).some((classe) => POSITIONNES.includes(sansVariante(classe)))
        ) {
            return parent
        }
    }

    return null
}

/**
 * Les côtés d'un bloc fixe ou collant posés au bord de l'écran par un
 * utilitaire ordinaire : son remplissage sûr écarte son contenu des barres,
 * mais un enfant `absolute` se place sur le bord de ce remplissage, donc sur
 * celui de l'écran.
 */
const cotesAuBordDeLEcran = (bloc) => {
    if (bloc.nom === 'dialog') {
        return COTES
    }

    if (!estFixeOuCollant(bloc.liste)) {
        return []
    }

    const { placements } = lireLaListe(bloc.liste)

    return COTES.filter((cote) => placements.some((placement) => placement.cote === cote))
}

/**
 * Les défauts d'un élément `absolute` posé dans un bloc fixe ou collant qui
 * touche le bord de l'écran : sur chacun de ces côtés, il se place comme un
 * élément fixe (`top-sure-2`, ou `top-0` avec `pt-sure-*`). Ce que contient
 * un fond plein écran exempté en fait partie : les taches de couleur du fond
 * décoratif débordent de l'écran à dessein.
 *
 * @param {{liste: string, parent: object|null}} element
 * @param {string[]} exceptions
 * @returns {string[]}
 */
const defautsDeLAbsolu = (element, exceptions) => {
    if (!classes(element.liste).some((classe) => sansVariante(classe) === 'absolute')) {
        return []
    }

    const bloc = blocDePlacement(element)

    return bloc === null || exceptions.includes(bloc.liste.trim())
        ? []
        : defautsDesCotes(element.liste, cotesAuBordDeLEcran(bloc)).map(
              (defaut) => `${defaut}, dans un bloc fixe posé au bord de l'écran`,
          )
}

/**
 * Tous les défauts d'une source, hors les listes exactes de ses exceptions.
 *
 * @param {string} source
 * @param {{vue?: boolean, exceptions?: string[]}} options
 * @returns {string[]}
 */
const defautsDeLaSource = (source, { vue = true, exceptions = [] } = {}) => {
    const retenue = (liste) => !exceptions.includes(liste.trim())
    const elements = vue ? elementsDuGabarit(gabarit(source)).elements : []

    return [
        ...elements
            .filter(({ liste }) => retenue(liste))
            .flatMap((element) => [...defautsDeLaListe(element.liste), ...defautsDeLAbsolu(element, exceptions)]),
        ...chainesDuScript(scriptDe(source, vue)).filter(retenue).flatMap(defautsDeLaListe),
    ]
}

/**
 * Un placement sur l'écran écrit en style, que la lecture des classes ne voit
 * pas : attribut `style` ou `:style`, bloc `<style>`, propriété posée par un
 * script (`element.style.position = 'fixed'`), propriété arbitraire
 * (`[position:fixed]`).
 *
 * @param {string} source
 * @returns {string[]}
 */
const placementsEnStyle = (source) =>
    [...sansCommentaires(source).matchAll(/\bposition['"`]?\s*[:=,]\s*['"`]?\s*(?:fixed|sticky)\b/g)].map(
        (trouve) => trouve[0],
    )

/**
 * Une hauteur bornée par celle de l'écran, qui compte les barres : `h-screen`,
 * `max-h-dvh`, ou toute valeur arbitraire en `vh`, `dvh`, `svh` ou `lvh`,
 * calculée ou non (`max-h-[calc(100vh-4rem)]`).
 */
const HAUTEUR_DE_L_ECRAN = /^(?:max-h|h)-(?:screen|[dsl]?vh|\[[^\]]*\d[dsl]?vh\b[^\]]*\])$/

const bornesParLEcran = (liste) => classes(liste).filter((classe) => HAUTEUR_DE_L_ECRAN.test(sansVariante(classe)))

const fichiers = () => collectSourceFiles({ extensions: ['.vue', '.js'] })

const exceptionsDe = (chemin) =>
    PLEIN_ECRAN.filter((exception) => exception.fichier === relatif(chemin)).map(({ classes: liste }) => liste)

describe('les zones sûres', () => {
    it('écarte des barres chaque côté placé d’un élément fixe ou collant, hors les fonds qui couvrent l’écran à dessein', () => {
        const fautifs = fichiers().flatMap((chemin) =>
            defautsDeLaSource(readFileSync(chemin, 'utf8'), {
                vue: chemin.endsWith('.vue'),
                exceptions: exceptionsDe(chemin),
            }).map((defaut) => `${relatif(chemin)} : ${defaut}`),
        )

        expect(
            fautifs,
            'Chaque côté placé d’un élément fixe ou collant compte la marge de ce côté : top-sure-*, ou top-0 avec ' +
                'pt-sure-* (app.css). Sans elle, il passe sous la barre d’état ou l’indicateur d’accueil de ' +
                'l’application installée. Un enfant absolute d’un plein écran fixe se place de même.',
        ).toEqual([])
    })

    it('lit chaque gabarit balise par balise, jusqu’au bout', () => {
        const malLus = collectSourceFiles({ extensions: ['.vue'] })
            .map((chemin) => ({ chemin, ...elementsDuGabarit(gabarit(readFileSync(chemin, 'utf8'))) }))
            .filter(({ ouverts, orphelines }) => ouverts.join(',') !== 'template' || orphelines.length > 0)
            .map(
                ({ chemin, ouverts, orphelines }) =>
                    `${relatif(chemin)} : ouvertes ${ouverts.join(', ')} ; sans ouvrante ${orphelines.join(', ')}`,
            )

        expect(malLus, 'Une balise mal lue fausse le parent de tout ce qui suit.').toEqual([])
    })

    it('n’écrit aucun placement sur l’écran en style, où la garde ne le lirait pas', () => {
        const fautifs = fichiers().flatMap((chemin) =>
            placementsEnStyle(readFileSync(chemin, 'utf8')).map((trouve) => `${relatif(chemin)} : ${trouve}`),
        )

        expect(fautifs, 'Un élément fixe ou collant se place par les classes `fixed`/`sticky`.').toEqual([])
    })

    it('ne garde que des exceptions qui existent encore', () => {
        const disparues = PLEIN_ECRAN.filter(
            (exception) =>
                !listesDeClasses(resolve(jsRoot, '..', '..', exception.fichier)).some(
                    (liste) => liste.trim() === exception.classes,
                ),
        ).map((exception) => `${exception.fichier} : « ${exception.classes} »`)

        expect(disparues).toEqual([])
    })

    it('n’emploie que des utilitaires de zone sûre qui existent, avec une valeur entière', () => {
        const inconnus = fichiers().flatMap((chemin) =>
            listesDeClasses(chemin)
                .flatMap(classes)
                .filter(
                    (classe) =>
                        MOT_DE_LA_FAMILLE.test(classe) && !classe.startsWith('--') && !UTILITAIRE_SUR.test(classe),
                )
                .map((classe) => `${relatif(chemin)} : ${classe}`),
        )

        expect(inconnus, 'Une classe inconnue ne fait rien, et le navigateur ne le signale pas.').toEqual([])

        expect(FAMILLES_SURES, 'app.css déclare les utilitaires `*-sure-*`').toEqual(
            expect.arrayContaining(['pt', 'px', 'py', 'top', 'bottom', 'left', 'right', 'hauteur']),
        )
    })

    it('fait passer toute fenêtre par Modal, bornée à la zone sûre', () => {
        const avecDialogue = fichiers()
            .filter((chemin) => /<dialog\b/.test(gabarit(readFileSync(chemin, 'utf8'))))
            .map(relatif)

        expect(avecDialogue.sort()).toEqual([...FICHIERS_A_DIALOGUE].sort())

        for (const fichier of FICHIERS_A_DIALOGUE) {
            const toutes = listesDeClasses(resolve(jsRoot, '..', '..', fichier)).flatMap(classes)

            expect(toutes, `${fichier} borne sa fenêtre à la zone sûre`).toEqual(
                expect.arrayContaining([expect.stringMatching(/^hauteur-sure-\d+$/)]),
            )
            expect(toutes, `${fichier} écarte sa fenêtre de la barre d’état`).toEqual(
                expect.arrayContaining([expect.stringMatching(/^(?:py|pt)-sure-\d+$/)]),
            )

            const ecart = Number(toutes.find((classe) => /^py-sure-\d+$/.test(classe))?.slice('py-sure-'.length))
            const borne = Number(
                toutes.find((classe) => /^hauteur-sure-\d+$/.test(classe)).slice('hauteur-sure-'.length),
            )

            expect(
                borne,
                `${fichier} : hauteur-sure-N compte l’écart du dessus et du dessous, deux fois celui de py-sure-*`,
            ).toBe(2 * ecart)
        }
    })

    it('n’emploie un Teleport que pour un contenu écarté des barres', () => {
        const sansZoneSure = fichiers()
            .filter((chemin) => /<Teleport\b/.test(gabarit(readFileSync(chemin, 'utf8'))))
            .filter((chemin) => !listesDeClasses(chemin).flatMap(classes).some(protege))
            .map(relatif)

        expect(sansZoneSure).toEqual([])
    })

    it('ne borne aucune hauteur par celle de l’écran, qui compte les barres', () => {
        const bornes = fichiers().flatMap((chemin) =>
            listesDeClasses(chemin)
                .flatMap(bornesParLEcran)
                .map((classe) => `${relatif(chemin)} : ${classe}`),
        )

        expect(bornes, 'Une fenêtre ou une liste se borne par hauteur-sure-*, pas par la hauteur de l’écran.').toEqual(
            [],
        )
    })

    it('écarte des barres les utilitaires de la feuille qui se placent sur l’écran', () => {
        const fautifs = UTILITAIRES.filter(({ nom }) => !(nom in UTILITAIRES_PLEIN_ECRAN)).flatMap(defautsDeLUtilitaire)

        expect(fautifs).toEqual([])

        const horsUtilitaires = appCss
            .replace(/^@utility [a-z0-9-]+\*? \{[\s\S]*?^\}/gm, '')
            .match(/position:\s*(?:fixed|sticky)/g)

        expect(horsUtilitaires, 'Un placement sur l’écran s’écrit dans un @utility, que cette garde lit.').toBeNull()
    })

    it('ne garde que des utilitaires plein écran qui en ont besoin', () => {
        const inutiles = Object.keys(UTILITAIRES_PLEIN_ECRAN).filter((nom) => {
            const utilitaire = UTILITAIRES.find((candidat) => candidat.nom === nom)

            return utilitaire === undefined || defautsDeLUtilitaire(utilitaire).length === 0
        })

        expect(inutiles).toEqual([])
    })

    it('ne borne aucune fenêtre de la feuille par la hauteur de l’écran', () => {
        const bornes = UTILITAIRES.filter(({ nom }) => !nom.startsWith('hauteur-sure-'))
            .filter(({ corps }) => /max-height:[^;]*\b\d+(?:d|s|l)?vh\b/.test(corps))
            .map(({ nom }) => nom)

        expect(bornes).toEqual([])
    })
})

/** Ce qui montre ou cache un élément selon la largeur. */
const BASCULE = /^(?:sm|bureau):(?:hidden|block|flex|inline-flex|grid)$/

/** La requête de la variante `bureau`, telle que app.css la déclare. */
const REQUETE_DU_BUREAU = /@custom-variant bureau \(@media (.+)\);/.exec(appCss)?.[1] ?? ''

/**
 * La requête de la variante `bureau` vaut-elle pour un écran donné ?
 *
 * Elle se lit par ses jetons : des conditions `(width >= Nrem)` et
 * `(height >= Nrem)`, réunies par `and` ou `or` entre parenthèses. Une forme
 * que cette lecture ne connaît pas la fait échouer plutôt que de passer pour
 * fausse.
 *
 * @param {string} requete
 * @param {{width: number, height: number}} ecran en pixels CSS
 * @returns {boolean}
 */
const requeteValable = (requete, ecran) => {
    const jetons = requete.match(/\(|\)|\band\b|\bor\b|\b(?:width|height) >= [\d.]+rem\b/g) ?? []

    if (jetons.join('').replace(/\s/g, '') !== requete.replace(/\s/g, '')) {
        throw new Error(`Requête de la variante bureau illisible : ${requete}`)
    }

    let rang = 0

    const terme = () => {
        const jeton = jetons[rang]

        rang += 1

        if (jeton === '(') {
            const valeur = condition()

            rang += 1

            return valeur
        }

        const [, dimension, rem] = /^(width|height) >= ([\d.]+)rem$/.exec(jeton)

        return ecran[dimension] >= Number(rem) * 16
    }

    const condition = () => {
        let valeur = terme()

        while (jetons[rang] === 'and' || jetons[rang] === 'or') {
            const operateur = jetons[rang]

            rang += 1

            const suivante = terme()

            valeur = operateur === 'and' ? valeur && suivante : valeur || suivante
        }

        return valeur
    }

    return condition()
}

/** Une valeur de `display`, que `hidden` cache et les autres montrent. */
const AFFICHAGE = /^(?:hidden|block|inline|inline-block|flex|inline-flex|grid|inline-grid|table|flow-root|contents)$/

/** Un point de rupture de Tailwind, dans un sens ou dans l'autre ; `bureau:` n'en est pas un. */
const POINT_DE_RUPTURE = /^(?:max-)?(?:sm|md|lg|xl|2xl)$/

/**
 * Ce qu'une liste de classes montre ou cache selon la largeur, hors `bureau:` :
 * `hidden sm:flex`, `sm:hidden`… `null` quand elle ne cache rien ainsi :
 * `flex sm:grid` change d'affichage sans cacher, `sm:flex-row` de direction.
 *
 * @param {string} liste
 * @returns {string|null}
 */
const basculeParLaLargeur = (liste) => {
    const mots = classes(liste).map(decomposer)
    const cacheAuDepart = mots.some(({ variante, base }) => variante === '' && base === 'hidden')
    const basculants = mots
        .filter(({ variante, base }) => POINT_DE_RUPTURE.test(variante) && AFFICHAGE.test(base))
        .filter(({ base }) => cacheAuDepart || base === 'hidden')
        .map(({ variante, base }) => `${variante}:${base}`)
        .sort()

    return basculants.length === 0 ? null : [...(cacheAuDepart ? ['hidden'] : []), ...basculants].join(' ')
}

/**
 * Les bascules par la largeur d'une source, comptées : `{ 'hidden sm:flex': 2 }`.
 *
 * @param {string} source
 * @param {boolean} vue
 * @returns {Record<string, number>}
 */
const basculesDeLaSource = (source, vue = true) =>
    listesDeLaSource(source, vue)
        .map(basculeParLaLargeur)
        .filter((bascule) => bascule !== null)
        .reduce((comptes, bascule) => ({ ...comptes, [bascule]: (comptes[bascule] ?? 0) + 1 }), {})

/**
 * Les bascules par la largeur qui ne suivent pas la barre de bureau, à
 * dessein : chacune change la mise en page d'un même composant, ses deux
 * moitiés, s'il y en a deux, sur la même variante, et rien ne la double dans
 * l'en-tête mobile. Le nombre compte : une bascule de plus dans le même
 * fichier revient devant la garde, et une bascule disparue doit sortir de la
 * liste.
 */
const BASCULES_DE_MISE_EN_PAGE = [
    {
        fichier: 'resources/js/Components/Journal/JournalList.vue',
        bascule: 'sm:hidden',
        nombre: 1,
        raison: 'l’humeur d’une entrée, dans la colonne de la date tant que celle-ci est une ligne',
    },
    {
        fichier: 'resources/js/Components/Journal/JournalList.vue',
        bascule: 'hidden sm:block',
        nombre: 1,
        raison: 'la même humeur, à côté des étiquettes quand la colonne de la date passe à gauche',
    },
    {
        fichier: 'resources/js/Components/Workout/ExerciseCard.vue',
        bascule: 'sm:hidden',
        nombre: 1,
        raison: 'le bouton « Modifier » d’un exercice au doigt, qui supprime d’un glissement',
    },
    {
        fichier: 'resources/js/Components/Workout/ExerciseCard.vue',
        bascule: 'hidden sm:inline-flex',
        nombre: 2,
        raison: '« Modifier » et « Supprimer » côte à côte dès sm, à la place du précédent',
    },
    {
        fichier: 'resources/js/Components/Workout/RangeeDeSerie.vue',
        bascule: 'hidden sm:block',
        nombre: 1,
        raison: 'la suppression d’une série pour la souris, que le glissement remplace au doigt',
    },
    {
        fichier: 'resources/js/Components/Exercises/FiltresDeLaBibliotheque.vue',
        bascule: 'hidden sm:flex',
        nombre: 1,
        raison: 'l’indication du raccourci ⌘K de la recherche, décorative',
    },
    {
        fichier: 'resources/js/Pages/Habits/Index.vue',
        bascule: 'hidden sm:flex',
        nombre: 1,
        raison: 'la vignette de l’icône d’une habitude, qui ne tient pas dans la rangée d’un téléphone',
    },
]

/**
 * Un téléphone couché garde l'en-tête mobile et la barre du bas (#2043) : la
 * barre de bureau n'y tient pas, et sa cloche et son menu du compte passaient
 * sous l'encoche ou hors de l'écran. Elle ne s'ouvre qu'à `bureau:` (large et
 * assez haut, ou assez large pour qu'aucun téléphone ne l'atteigne), et ce qui
 * bascule avec elle suit la même variante : à `sm:`, un téléphone couché
 * montrerait le titre de la page deux fois.
 */
describe('la barre de bureau', () => {
    it.each([
        ['un téléphone debout', 390, 844, false],
        ['un iPhone SE couché', 667, 375, false],
        ['un iPhone couché', 844, 390, false],
        ['le plus grand iPhone couché', 956, 440, false],
        ['un grand Android couché', 998, 448, false],
        ['juste sous les deux seuils', 1023, 499, false],
        ['une tablette debout', 768, 1024, true],
        ['une tablette couchée', 1024, 768, true],
        ['juste au seuil de hauteur', 640, 500, true],
        ['un écran de bureau', 1280, 800, true],
        ['une fenêtre de bureau large et basse', 1280, 480, true],
        ['un portable mis à l’échelle, fenêtre basse', 1093, 460, true],
    ])('%s (%i × %i) : barre de bureau %s', (_ecran, largeur, hauteur, ouverte) => {
        expect(requeteValable(REQUETE_DU_BUREAU, { width: largeur, height: hauteur })).toBe(ouverte)
    })

    it('lit la requête de la variante par ses jetons, et refuse ce qu’elle ne connaît pas', () => {
        expect(requeteValable('(width >= 40rem) and (height >= 31.25rem)', { width: 1280, height: 480 })).toBe(false)
        expect(requeteValable('(width >= 40rem) or (height >= 31.25rem)', { width: 390, height: 844 })).toBe(true)
        expect(() => requeteValable('(min-width: 40rem)', { width: 1280, height: 800 })).toThrow()
        expect(() => requeteValable('(width >= 40rem) and (pointer: fine)', { width: 1280, height: 800 })).toThrow()
    })

    it('ne s’ouvre qu’à bureau:, comme l’en-tête mobile, la barre du bas et l’en-tête de page basculent', () => {
        const { elements } = elementsDuGabarit(
            gabarit(readFileSync(resolve(jsRoot, 'Layouts/AuthenticatedLayout.vue'), 'utf8')),
        )
        const bascules = elements
            .filter(({ nom }) => ['nav', 'header', 'BottomNav'].includes(nom))
            .map(({ nom, liste }) => [nom, classes(liste).filter((classe) => BASCULE.test(classe))])
            .filter(([, variantes]) => variantes.length > 0)

        expect(bascules).toEqual([
            ['nav', ['bureau:block']],
            ['header', ['bureau:hidden']],
            ['header', ['bureau:block']],
            ['BottomNav', ['bureau:hidden']],
        ])
        expect(REQUETE_DU_BUREAU, 'app.css déclare la variante bureau').not.toBe('')
    })

    it('ne montre le titre de bureau d’une page qu’avec la barre de bureau', () => {
        const cacheJusquASm = (element) => {
            const mots = classes(element.liste)

            return mots.includes('hidden') && mots.some((mot) => /^sm:(?:block|flex|inline|grid)/.test(mot))
        }
        const fautifs = collectSourceFiles({ extensions: ['.vue'] })
            .filter((chemin) => chemin.includes('/Pages/'))
            .flatMap((chemin) =>
                elementsDuGabarit(gabarit(readFileSync(chemin, 'utf8')))
                    .elements.filter(({ nom }) => nom === 'h1')
                    .filter((titre) => {
                        for (let element = titre; element !== null; element = element.parent) {
                            if (cacheJusquASm(element)) {
                                return true
                            }
                        }

                        return false
                    })
                    .map(() => relatif(chemin)),
            )

        expect(
            fautifs,
            'L’en-tête mobile porte le titre jusqu’à bureau: ; un titre caché jusqu’à sm: se montrerait en double ' +
                'sur un téléphone couché (hidden bureau:block).',
        ).toEqual([])
    })

    /**
     * Les boutons qu'une page double entre les deux en-têtes, et l'espace
     * qu'elle réserve à la barre du bas, basculent avec la barre de bureau.
     * Repassés à `sm:`, « Ajouter » des compléments et « Nouvel Exercice » de
     * la bibliothèque se montraient deux fois sur un téléphone couché, une
     * fois dans l'en-tête mobile et une fois dans la page, sans qu'aucun test
     * ne le voie. Toute bascule par la largeur s'écrit donc `bureau:`, hors la
     * mise en page d'un même composant, listée avec sa raison.
     */
    it('ne cache ni ne montre rien selon la largeur hors bureau:, sauf la mise en page d’un même composant', () => {
        const trouvees = fichiers()
            .flatMap((chemin) =>
                Object.entries(basculesDeLaSource(readFileSync(chemin, 'utf8'), chemin.endsWith('.vue'))).map(
                    ([bascule, nombre]) => `${relatif(chemin)} : ${bascule} × ${nombre}`,
                ),
            )
            .sort()
        const permises = BASCULES_DE_MISE_EN_PAGE.map(
            ({ fichier, bascule, nombre }) => `${fichier} : ${bascule} × ${nombre}`,
        ).sort()

        expect(
            trouvees,
            'Ce qui bascule avec la barre de bureau s’écrit bureau: (hidden bureau:flex, bureau:hidden). ' +
                'Une bascule sm:, md:… s’ouvre aussi sur un téléphone couché, où l’en-tête mobile reste : ' +
                'une commande doublée s’y montre deux fois, ou nulle part. Seule la mise en page d’un même ' +
                'composant s’écrit autrement, dans BASCULES_DE_MISE_EN_PAGE avec sa raison.',
        ).toEqual(permises)
    })

    it.each([
        ['hidden sm:flex', 'hidden sm:flex'],
        ['h-24 sm:hidden', 'sm:hidden'],
        ['hidden md:block', 'hidden md:block'],
        ['flex max-sm:hidden', 'max-sm:hidden'],
        ['hidden sm:flex sm:items-center', 'hidden sm:flex'],
        ['bureau:flex hidden size-11', null],
        ['bureau:hidden h-24', null],
        ['flex flex-col sm:flex-row', null],
        ['flex sm:grid', null],
        ['hidden', null],
    ])('lit « %s » comme la bascule %s', (liste, bascule) => {
        expect(basculeParLaLargeur(liste)).toBe(bascule)
    })

    it('refuse un bouton de page doublé à sm:, écrit en class ou en :class', () => {
        expect(
            basculesDeLaSource(
                '<template><GlassButton class="hidden size-11 sm:flex" @click="ajouter">Ajouter</GlassButton><div class="h-24 sm:hidden"></div></template>',
            ),
        ).toEqual({ 'hidden sm:flex': 1, 'sm:hidden': 1 })
        expect(
            basculesDeLaSource(`<template><GlassButton :class="['hidden', ouvert ? 'sm:flex' : '']" /></template>`),
        ).toEqual({ 'hidden sm:flex': 1 })
        expect(basculesDeLaSource(`const classes = computed(() => 'hidden lg:block')`, false)).toEqual({
            'hidden lg:block': 1,
        })
    })
})

/**
 * La garde refuse ce qu'elle doit refuser : sans ces cas, une lecture trop
 * large passerait pour une garde, comme celle qui acceptait toute liste
 * contenant un utilitaire `*-sure-*`, quel que soit le côté qu'il protégeait,
 * ou celle qui lisait `class` et `:class` chacun de son côté.
 */
describe('la garde des zones sûres', () => {
    it.each([
        ['fixed inset-x-0 bottom-0 px-sure-4', 'une barre du bas posée sous l’indicateur d’accueil'],
        ['fixed inset-x-0 bottom-0 pt-sure-4', 'un remplissage sûr du mauvais côté'],
        ['fixed inset-x-0 top-0 hauteur-sure-0', 'une hauteur bornée, qui n’écarte aucun côté'],
        ['sticky top-0', 'un en-tête collé sous la barre d’état'],
        ['fixed inset-0 px-sure-4', 'un plein écran écarté des seuls côtés'],
        ['fixed top-sure-20 right-sure-4 left-sure-4 sm:top-4', 'une variante qui retire la marge du haut'],
        ['sticky top-0 pt-sure-4 sm:pt-4', 'un remplissage ordinaire qui remplace le remplissage sûr'],
        ['fixed top-0 sm:pt-sure-0', 'un remplissage sûr réservé à une variante'],
        ['fixed top-[3px] pt-sure-0 pt-2', 'un remplissage ordinaire du même côté'],
        ['fixed inset-bs-0 px-sure-4', 'un placement logique en haut'],
    ])('refuse « %s » : %s', (liste) => {
        expect(defautsDeLaListe(liste)).not.toEqual([])
    })

    it.each([
        'fixed inset-x-0 bottom-sure-0 px-sure-4',
        'z-modale px-sure-4 py-sure-6 sm:px-sure-0 fixed inset-0',
        'sticky top-0 pt-sure-4 pb-4',
        'z-toast top-sure-20 right-sure-4 left-sure-4 sm:right-sure-6 fixed sm:left-auto sm:w-80',
        'titre-carte top-sous-entete sticky z-10',
        'fixed bottom-sure-36 left-sure-4 right-sure-4 inset-ring-2',
        'fixed left-1/2 top-sure-4 -translate-x-1/2',
        'fixed',
    ])('accepte « %s »', (liste) => {
        expect(defautsDeLaListe(liste)).toEqual([])
    })

    it.each([
        [
            `<template><div class="z-toast right-sure-4 left-sure-4 fixed" :class="enHaut ? 'top-4' : 'bottom-4'"></div></template>`,
            'une notification dont class et :class se partagent le placement',
        ],
        [
            '<template><h3 class="top-0 z-10" :class="{ sticky: colle }"></h3></template>',
            'un collant conditionnel écrit en objet',
        ],
        [
            `<template><h3 class="top-0" v-bind:class="{ 'sticky': colle }"></h3></template>`,
            'la même chose en v-bind:class, clé entre guillemets',
        ],
        [
            '<template><div class="z-modale px-sure-4 py-sure-6 fixed inset-0"><button class="absolute top-2 right-2"></button></div></template>',
            'un bouton « Fermer » placé sur le bord du remplissage d’un plein écran',
        ],
        [
            '<template><header class="sticky top-0 pt-sure-4"><span class="absolute top-0 right-0"></span></header></template>',
            'un enfant absolu d’un en-tête collé en haut',
        ],
        [
            '<template><dialog><div><p class="absolute inset-x-0 top-0"></p></div></dialog></template>',
            'un enfant absolu d’une fenêtre modale du navigateur',
        ],
        [
            `<template><div class="fixed inset-0 px-sure-4 py-sure-6" :class="a > b ? 'x' : 'y'"><i class="absolute top-0 left-0"></i></div></template>`,
            'un > entre guillemets ne ferme pas la balise',
        ],
    ])('refuse le gabarit %s : %s', (source) => {
        expect(defautsDeLaSource(source)).not.toEqual([])
    })

    it.each([
        `<template><div class="fixed" :class="enHaut ? 'top-sure-4' : 'bottom-sure-4'"></div></template>`,
        '<template><div class="fixed inset-0 px-sure-4 py-sure-6"><div class="relative"><span class="absolute -top-1 -right-1"></span></div></div></template>',
        '<template><div class="fixed inset-0 px-sure-4 py-sure-6"><button class="absolute top-sure-2 right-sure-2"></button></div></template>',
        '<template><div class="z-toast top-sure-20 right-sure-4 left-sure-4 fixed"><button class="absolute top-2 right-2"></button></div></template>',
        '<template><div class="fixed inset-x-0 bottom-sure-0 px-sure-4"><span class="absolute top-0 left-1/2"></span></div></template>',
        '<template><div class="relative"><span class="absolute top-0 left-0"></span></div><div class="fixed inset-0 py-sure-6 px-sure-4"></div></template>',
        '<template><div class="fixed inset-0 py-sure-6 px-sure-4"><Teleport to="body"><span class="absolute top-0"></span></Teleport></div></template>',
    ])('accepte le gabarit %s', (source) => {
        expect(defautsDeLaSource(source)).toEqual([])
    })

    it('lit un gabarit balise par balise, parents compris', () => {
        const { elements, ouverts, orphelines } = elementsDuGabarit(
            `<template><div class="a" :class="{ b: c }"><img class="d"><Composant class="e" /><Link class="g"><p class='f'>{{ x < y }}</p></Link></div></template>`,
        )

        expect(elements.map(({ nom, liste, parent }) => [nom, liste, parent?.nom ?? null])).toEqual([
            ['template', '', null],
            ['div', 'a { b: c }', 'template'],
            ['img', 'd', 'div'],
            ['Composant', 'e', 'div'],
            ['Link', 'g', 'div'],
            ['p', 'f', 'Link'],
        ])
        expect([ouverts, orphelines]).toEqual([[], []])
        expect(elementsDuGabarit('<div><span></div></p>')).toMatchObject({ ouverts: ['span'], orphelines: ['p'] })
    })

    it('garde entiers une valeur arbitraire et une variable', () => {
        expect(classes(`max-h-[calc(100vh-4rem)] sm:top-0 h-(--hauteur) { sticky: colle } a ? 'b' : 'c'`)).toEqual([
            'max-h-[calc(100vh-4rem)]',
            'sm:top-0',
            'h-(--hauteur)',
            'sticky',
            'colle',
            'a',
            'b',
            'c',
        ])
        expect(decomposer('[position:fixed]')).toEqual({ variante: '', base: '[position:fixed]' })
    })

    it.each([
        'max-h-[calc(100vh-4rem)]',
        'h-[calc(100dvh-2rem)]',
        'max-h-[60vh]',
        'sm:max-h-[85svh]',
        'h-screen',
        'max-h-dvh',
        'h-lvh',
    ])('refuse la hauteur %s', (classe) => {
        expect(bornesParLEcran(classe)).toEqual([classe])
    })

    it.each(['max-h-full', 'h-[3.5rem]', 'min-h-dvh', 'max-h-96', 'h-(--hauteur)', 'hauteur-sure-12'])(
        'accepte la hauteur %s',
        (classe) => {
            expect(bornesParLEcran(classe)).toEqual([])
        },
    )

    it.each([
        ['position: fixed; inset: auto 0 0 0;', 'une barre du bas posée par le raccourci inset'],
        ['position: fixed; inset: 0;', 'un plein écran posé par inset'],
        ['position: sticky; inset-block-start: 0;', 'un collant posé par une propriété logique'],
        ['position: fixed; bottom: 0; inset-inline: 1rem;', 'des côtés posés par inset-inline'],
        ['position: fixed;\n    @variant sm {\n        top: 1rem;\n    }', 'une variante qui retire la marge'],
    ])('refuse l’utilitaire « %s » : %s', (corps) => {
        expect(defautsDeLUtilitaire({ nom: 'essai', corps })).not.toEqual([])
    })

    it.each([
        'position: fixed; inset: auto calc(1rem + var(--safe-area-right)) calc(1rem + var(--safe-area-bottom)) calc(1rem + var(--safe-area-left));',
        'position: fixed; bottom: calc(1rem + var(--safe-area-bottom)); left: calc(1rem + var(--safe-area-left)); right: calc(1rem + var(--safe-area-right));',
        'position: fixed; inset: auto; /* top: 0 */',
        'position: absolute; inset: 0;',
    ])('accepte l’utilitaire « %s »', (corps) => {
        expect(defautsDeLUtilitaire({ nom: 'essai', corps })).toEqual([])
    })

    it.each([
        '<div style="position: fixed; top: 0; left: 0; right: 0"></div>',
        `<div :style="{ position: 'sticky', top: 0 }"></div>`,
        "element.style.position = 'fixed'",
        "element.style.setProperty('position', 'fixed')",
        '<style scoped>.barre { position: fixed; bottom: 0 }</style>',
        '<div class="[position:fixed] top-0"></div>',
    ])('refuse un placement écrit en style : %s', (source) => {
        expect(placementsEnStyle(source)).not.toEqual([])
    })

    it('ne prend ni un commentaire ni une autre position pour un placement', () => {
        expect(
            placementsEnStyle(
                [
                    '<!-- position: fixed -->',
                    '/* position: sticky */',
                    "const legende = { position: 'top' }",
                    '.roue { position: absolute; }',
                ].join('\n'),
            ),
        ).toEqual([])
    })

    it('lit dans la feuille le côté que protège chaque utilitaire', () => {
        const cotes = (classe) =>
            PROTECTIONS.filter(({ motif }) => motif.test(classe)).flatMap(({ place, remplit }) => [
                ...place,
                ...remplit,
            ])

        expect(cotes('pt-sure-4')).toEqual(['top'])
        expect(cotes('px-sure-0')).toEqual(['left', 'right'])
        expect(cotes('bottom-sure-36')).toEqual(['bottom'])
        expect(cotes('top-sous-entete')).toEqual(['top'])
        expect(cotes('hauteur-sure-12')).toEqual([])
    })

    it('décompose les raccourcis de la feuille côté par côté', () => {
        expect(cotesDuCorps('inset: auto 0 calc(1rem + var(--x)) 2px;', 'placement')).toEqual([
            { cote: 'top', valeur: 'auto' },
            { cote: 'right', valeur: '0' },
            { cote: 'bottom', valeur: 'calc(1rem + var(--x))' },
            { cote: 'left', valeur: '2px' },
        ])
        expect(cotesDuCorps('padding-inline: 1rem 2rem;', 'remplissage')).toEqual([
            { cote: 'left', valeur: '1rem' },
            { cote: 'right', valeur: '2rem' },
        ])
    })
})
