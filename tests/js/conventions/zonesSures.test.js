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
 * qu'on les emploie.
 *
 * Le contrôle lit les sources : le parcours
 * `tests/Browser/LesFenetresRestentDansLaZoneSureTest.php` mesure le rendu.
 */
const appCss = readFileSync(resolve(jsRoot, '../css/app.css'), 'utf8')

/** Les familles que la feuille déclare : `pt` pour `@utility pt-sure-*`. */
const FAMILLES_SURES = [...appCss.matchAll(/^@utility ([a-z]+)-sure-\* \{/gm)].map((trouve) => trouve[1])

/** Un utilitaire de zone sûre déclaré, avec ses variantes (`sm:`) et une valeur entière. */
const UTILITAIRE_SUR = new RegExp(`^(?:[a-z0-9-]+:)*(?:${FAMILLES_SURES.join('|')})-sure-\\d+$`)

/** Tout mot qui se réclame de la famille, juste ou non. */
const MOT_DE_LA_FAMILLE = /-sure(?:-|$)/

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

/** Le gabarit, sans ses commentaires : un commentaire qui cite `fixed` ne place rien. */
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
    const sansCommentaires = script.replace(/\/\*[\s\S]*?\*\//g, '').replace(/(^|[^:])\/\/.*$/gm, '$1')

    for (const trouve of sansCommentaires.matchAll(/'([^'\n]*)'|"([^"\n]*)"|`([^`]*)`/g)) {
        listes.push(trouve[1] ?? trouve[2] ?? trouve[3])
    }

    return listes
}

/** Les mots d'une liste de classes, variantes comprises (`sm:sticky` compte). */
const classes = (liste) => liste.split(/[\s'"`,{}()?]+/).filter(Boolean)

const sansVariante = (classe) => classe.split(':').pop().replace(/^!/, '')

const fichiers = () => collectSourceFiles({ extensions: ['.vue', '.js'] })

describe('les zones sûres', () => {
    it('écarte des barres tout élément fixe ou collant, hors les fonds qui couvrent l’écran à dessein', () => {
        const fautifs = fichiers().flatMap((chemin) =>
            listesDeClasses(chemin)
                .filter((liste) => classes(liste).some((classe) => ['fixed', 'sticky'].includes(sansVariante(classe))))
                .filter((liste) => !classes(liste).some((classe) => UTILITAIRE_SUR.test(classe)))
                .filter(
                    (liste) =>
                        !PLEIN_ECRAN.some(
                            (exception) => exception.fichier === relatif(chemin) && exception.classes === liste.trim(),
                        ),
                )
                .map((liste) => `${relatif(chemin)} : « ${liste.trim()} »`),
        )

        expect(
            fautifs,
            'Un élément fixe ou collant prend un utilitaire de zone sûre (top-sure-*, pt-sure-*, px-sure-*… dans app.css) : ' +
                'sans lui, il passe sous la barre d’état ou l’indicateur d’accueil de l’application installée.',
        ).toEqual([])
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
            .filter(
                (chemin) =>
                    !listesDeClasses(chemin)
                        .flatMap(classes)
                        .some((classe) => UTILITAIRE_SUR.test(classe)),
            )
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
        const blocs = [...appCss.matchAll(/^@utility ([a-z0-9-]+\*?) \{([\s\S]*?)^\}/gm)]

        const fautifs = blocs
            .filter(([, , corps]) => /position:\s*(?:fixed|sticky)/.test(corps))
            .filter(([, nom]) => !(nom in UTILITAIRES_PLEIN_ECRAN))
            .flatMap(([, nom, corps]) =>
                ['top', 'bottom', 'left', 'right']
                    .filter((cote) => new RegExp(`^\\s*${cote}:`, 'm').test(corps))
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
        const bornes = [...appCss.matchAll(/^@utility ([a-z0-9-]+\*?) \{([\s\S]*?)^\}/gm)]
            .filter(([, nom]) => !nom.startsWith('hauteur-sure-'))
            .filter(([, , corps]) => /max-height:[^;]*\b\d+(?:d|s|l)?vh\b/.test(corps))
            .map(([, nom]) => nom)

        expect(bornes).toEqual([])
    })
})
