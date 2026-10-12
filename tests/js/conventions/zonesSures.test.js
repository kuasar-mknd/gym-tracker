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
 * Ce que chaque utilitaire de la feuille fait des barres, lu dans ses
 * déclarations et non dans son nom : `place` les côtés dont la position
 * (`top`…) compte la marge, `remplit` ceux dont le remplissage ou la marge
 * extérieure la comptent. `hauteur-sure-*` borne une hauteur : il n'écarte
 * aucun côté, et ne protège donc rien.
 */
const PROTECTIONS = UTILITAIRES.map(({ nom, corps }) => {
    const compte = (propriete, cote) =>
        new RegExp(`(?:^|[\\s;{])${propriete}\\s*:[^;]*var\\(--safe-area-${cote}\\)`).test(corps)

    return {
        motif: new RegExp(nom.endsWith('*') ? `^${nom.slice(0, -1)}\\d+$` : `^${nom}$`),
        place: COTES.filter((cote) => compte(cote, cote)),
        remplit: COTES.filter((cote) => compte(`(?:padding|margin)-${cote}`, cote)),
    }
}).filter(({ place, remplit }) => place.length + remplit.length > 0)

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
    ...COTES.map((cote) => [new RegExp(`^-?${cote}-${VALEUR}`), [cote]]),
]

/** Les remplissages ordinaires, qui remplaceraient un remplissage sûr du même côté. */
const REMPLISSAGES = [
    [new RegExp(`^p-${VALEUR}`), COTES],
    [new RegExp(`^px-${VALEUR}`), ['left', 'right']],
    [new RegExp(`^py-${VALEUR}`), ['top', 'bottom']],
    [new RegExp(`^pt-${VALEUR}`), ['top']],
    [new RegExp(`^pb-${VALEUR}`), ['bottom']],
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

/** Une source sans ses commentaires : un commentaire qui cite `fixed` ne place rien. */
const sansCommentaires = (source) =>
    source
        .replace(/<!--[\s\S]*?-->/g, '')
        .replace(/\/\*[\s\S]*?\*\//g, '')
        .replace(/(^|[^:])\/\/.*$/gm, '$1')

/** Le gabarit, sans ses commentaires. */
const gabarit = (source) => {
    const debut = source.indexOf('<template>')
    const fin = source.lastIndexOf('</template>')

    return debut === -1 ? '' : source.slice(debut, fin).replace(/<!--[\s\S]*?-->/g, '')
}

/**
 * Les listes de classes écrites dans les sources : attributs `class` et
 * `:class` des gabarits, chaînes des scripts et des modules (une classe
 * calculée, ou la page « hors ligne » que le worker sert).
 */
const listesDeClasses = (chemin) => {
    const source = readFileSync(chemin, 'utf8')
    const listes = []

    if (chemin.endsWith('.vue')) {
        for (const trouve of gabarit(source).matchAll(/(?:^|\s)(?::|v-bind:)?class\s*=\s*"([^"]*)"/g)) {
            listes.push(trouve[1])
        }
    }

    const script = chemin.endsWith('.vue')
        ? [...source.matchAll(/<script\b[^>]*>([\s\S]*?)<\/script>/g)].map((bloc) => bloc[1]).join('\n')
        : source

    for (const trouve of sansCommentaires(script).matchAll(/'([^'\n]*)'|"([^"\n]*)"|`([^`]*)`/g)) {
        listes.push(trouve[1] ?? trouve[2] ?? trouve[3])
    }

    return listes
}

/** Les mots d'une liste de classes, variantes comprises (`sm:sticky` compte). */
const classes = (liste) => liste.split(/[\s'"`,{}()?]+/).filter(Boolean)

/** `sm:top-0` → `{ variante: 'sm', base: 'top-0' }`. */
const decomposer = (classe) => {
    const morceaux = classe.split(':')
    const base = morceaux.pop().replace(/^!|!$/g, '')

    return { variante: morceaux.join(':'), base }
}

const sansVariante = (classe) => decomposer(classe).base

/** La classe protège-t-elle un côté au moins ? */
const protege = (classe) => PROTECTIONS.some(({ motif }) => motif.test(sansVariante(classe)))

/**
 * Les défauts d'une liste de classes qui place un élément sur l'écran.
 *
 * Un côté placé par un utilitaire de Tailwind (`top-0`, `inset-x-0`…) exige un
 * remplissage sûr de ce même côté, sans variante ou de la même variante, et
 * aucun remplissage ordinaire de ce côté qui le remplacerait. Un côté placé
 * par un utilitaire sûr (`top-sure-20`) l'est déjà. Un côté que rien ne place
 * n'exige rien : `pt-sure-4` ne sauve pas une barre posée à `bottom-0`, et
 * `hauteur-sure-*` ne sauve aucun côté.
 *
 * @param {string} liste
 * @returns {string[]}
 */
const defautsDeLaListe = (liste) => {
    const mots = classes(liste).map((classe) => ({ classe, ...decomposer(classe) }))

    if (!mots.some(({ base }) => ['fixed', 'sticky'].includes(base))) {
        return []
    }

    const placements = mots.flatMap((mot) =>
        PLACEMENTS.filter(([motif]) => motif.test(mot.base)).flatMap(([, cotes]) =>
            cotes.map((cote) => ({ ...mot, cote })),
        ),
    )
    const remplissagesSurs = mots.flatMap((mot) =>
        PROTECTIONS.filter(({ motif }) => motif.test(mot.base)).flatMap(({ remplit }) =>
            remplit.map((cote) => ({ ...mot, cote })),
        ),
    )
    const remplissagesOrdinaires = mots.flatMap((mot) =>
        REMPLISSAGES.filter(([motif]) => motif.test(mot.base)).flatMap(([, cotes]) =>
            cotes.map((cote) => ({ ...mot, cote })),
        ),
    )

    return COTES.flatMap((cote) => {
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

const fichiers = () => collectSourceFiles({ extensions: ['.vue', '.js'] })

describe('les zones sûres', () => {
    it('écarte des barres chaque côté placé d’un élément fixe ou collant, hors les fonds qui couvrent l’écran à dessein', () => {
        const fautifs = fichiers().flatMap((chemin) =>
            listesDeClasses(chemin)
                .filter(
                    (liste) =>
                        !PLEIN_ECRAN.some(
                            (exception) => exception.fichier === relatif(chemin) && exception.classes === liste.trim(),
                        ),
                )
                .flatMap((liste) => defautsDeLaListe(liste).map((defaut) => `${relatif(chemin)} : ${defaut}`)),
        )

        expect(
            fautifs,
            'Chaque côté placé d’un élément fixe ou collant compte la marge de ce côté : top-sure-*, ou top-0 avec ' +
                'pt-sure-* (app.css). Sans elle, il passe sous la barre d’état ou l’indicateur d’accueil de ' +
                'l’application installée.',
        ).toEqual([])
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
                .flatMap(classes)
                .filter((classe) => /^(?:max-h|h)-(?:screen|dvh|svh|lvh|\[[^\]]*vh\])$/.test(sansVariante(classe)))
                .map((classe) => `${relatif(chemin)} : ${classe}`),
        )

        expect(bornes, 'Une fenêtre ou une liste se borne par hauteur-sure-*, pas par la hauteur de l’écran.').toEqual(
            [],
        )
    })

    it('écarte des barres les utilitaires de la feuille qui se placent sur l’écran', () => {
        const fautifs = UTILITAIRES.filter(({ corps }) => /position:\s*(?:fixed|sticky)/.test(corps))
            .filter(({ nom }) => !(nom in UTILITAIRES_PLEIN_ECRAN))
            .flatMap(({ nom, corps }) =>
                COTES.filter((cote) => new RegExp(`^\\s*${cote}:`, 'm').test(corps))
                    .filter((cote) => !new RegExp(`^\\s*${cote}:[^;]*var\\(--safe-area-${cote}\\)`, 'm').test(corps))
                    .map((cote) => `${nom} : ${cote}`),
            )

        expect(fautifs).toEqual([])

        const horsUtilitaires = appCss
            .replace(/^@utility [a-z0-9-]+\*? \{[\s\S]*?^\}/gm, '')
            .match(/position:\s*(?:fixed|sticky)/g)

        expect(horsUtilitaires, 'Un placement sur l’écran s’écrit dans un @utility, que cette garde lit.').toBeNull()
    })

    it('ne borne aucune fenêtre de la feuille par la hauteur de l’écran', () => {
        const bornes = UTILITAIRES.filter(({ nom }) => !nom.startsWith('hauteur-sure-'))
            .filter(({ corps }) => /max-height:[^;]*\b\d+(?:d|s|l)?vh\b/.test(corps))
            .map(({ nom }) => nom)

        expect(bornes).toEqual([])
    })
})

/**
 * La garde refuse ce qu'elle doit refuser : sans ces cas, une lecture trop
 * large passerait pour une garde, comme celle qui acceptait toute liste
 * contenant un utilitaire `*-sure-*`, quel que soit le côté qu'il protégeait.
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
})
