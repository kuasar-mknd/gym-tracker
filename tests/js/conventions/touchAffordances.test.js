import { describe, it, expect } from 'vitest'
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'

import { chainesDuScript, classes, elementsDuGabarit, gabarit, scriptDe } from './gabarits'
import { collectSourceFiles, jsRoot } from './sourceFiles'

/**
 * Ce que le survol révèle ne se cache que sous une souris, et le focus le
 * révèle aussi (#2082).
 *
 * Tailwind 4 ne pose `hover:` et `group-hover:` que sous `@media (hover:
 * hover)`. Un bouton écrit `opacity-0 group-hover:opacity-100` n'apparaît donc
 * jamais sur un écran tactile : les habitudes, les mensurations et le
 * calculateur de disques avaient ainsi perdu leur suppression sur téléphone.
 * Repris en `sm:opacity-0 sm:group-hover:opacity-100`, ils prenaient la
 * largeur pour une souris : sur une tablette, à partir de 640 px, les boutons
 * « Modifier » et « Supprimer » de six écrans restaient transparents, et un
 * appui à leur place déclenchait l'action.
 *
 * La garde lit chaque gabarit balise par balise (`class`, `:class` et
 * `v-bind:class` ensemble, par `gabarits.js`) et chaque chaîne des scripts. Un
 * élément attend le survol quand une de ses classes le cache (`opacity-0`,
 * `invisible`, `hidden`) et qu'une autre, de la même propriété, le montre sous
 * une variante de survol (`hover:`, `group-hover:`, `peer-hover:`, leurs formes
 * nommées, une variante arbitraire qui nomme `:hover`). Il doit alors :
 * - ne se cacher que sous `souris:` (app.css), `sm:souris:opacity-0` compris :
 *   sans souris, il reste visible ;
 * - être montré par le focus aussi (`focus-within:` sur le conteneur,
 *   `focus-visible:` sur le bouton), dans la même propriété : le clavier
 *   atteint ce que la souris révèle.
 *
 * Un décor y échappe : un `div` ou un `span` sans contenu, sans écouteur ni
 * rôle (un halo, un dégradé qui s'allume au survol), ne cache rien. Tout autre
 * élément qu'elle doit laisser passer va dans `EXEMPTIONS`, avec son fichier,
 * sa liste de classes exacte et sa raison ; une exemption devenue inutile la
 * fait échouer.
 *
 * Ses limites :
 * - elle ne lit que l'opacité, la visibilité et l'affichage : un élément caché
 *   par sa taille (`w-0`, `max-h-0`, `scale-0`), hors de l'écran (`translate-`)
 *   ou par `sr-only` lui échappe ;
 * - elle juge une liste, pas ses ancêtres : un `group-hover:` sans parent
 *   `group` ne révèle rien, et elle ne le voit pas ; une classe passée à un
 *   composant compte pour la balise du composant, pas pour sa racine ;
 * - une liste liée par une variable (`:class="classes"`) se lit dans le
 *   script, comme toute chaîne, sans balise : elle n'y passe jamais pour un
 *   décor ;
 * - elle ne lit que `resources/js` : ni `<style>`, ni les `@utility`
 *   d'app.css, ni les gabarits Blade de `resources/views` ; une règle qui
 *   cacherait au survol en CSS lui échappe ;
 * - un décor se juge à son contenu dans le gabarit : un `div` vide que le
 *   script remplirait (`innerHTML`) passerait pour un décor, `v-html` et
 *   `v-text` exceptés.
 */

const appCss = readFileSync(resolve(jsRoot, '../css/app.css'), 'utf8')

const relatif = (chemin) => chemin.replace(jsRoot, 'resources/js')

/** Les propriétés que la garde lit : la classe qui cache, celles qui montrent. */
const PROPRIETES = [
    { nom: 'opacité', cache: /^opacity-0$/, montre: /^opacity-(?!0$)(?:\d+|\[[^\]]+\])$/ },
    { nom: 'visibilité', cache: /^invisible$/, montre: /^visible$/ },
    {
        nom: 'affichage',
        cache: /^hidden$/,
        montre: /^(?:block|inline|inline-block|flex|inline-flex|grid|inline-grid|table|flow-root|contents)$/,
    },
]

/** Une variante de survol : `hover`, `group-hover`, `peer-hover/carte`, `[&:hover]`… */
const SURVOL = /^(?:(?:group|peer|in)-)?hover(?:\/[\w-]+)?$|^(?:(?:group|peer|in)-)?\[[^\]]*:hover\b[^\]]*\]$/

/** Une variante de focus : `focus-within`, `focus-visible`, `group-focus-within`… */
const FOCUS =
    /^(?:(?:group|peer|in|has)-)?focus(?:-within|-visible)?(?:\/[\w-]+)?$|^(?:(?:group|peer|in|has)-)?\[[^\]]*:focus(?:-within|-visible)?\b[^\]]*\]$/

/** Ce qui fait d'un élément vide autre chose qu'un décor : un écouteur, un rôle, un contenu posé par Vue. */
const PAS_UN_DECOR = /(?:^|\s)(?:@|v-on:|v-html\b|v-text\b|tabindex\b|role\b|:tabindex\b|:role\b)/

/**
 * Ce que la garde laisse passer, avec sa raison.
 *
 * Une entrée nomme le fichier et la liste de classes exacte de l'élément.
 */
const EXEMPTIONS = [
    {
        fichier: 'resources/js/Pages/Achievements/Index.vue',
        classes:
            'bg-text-main/80 pointer-events-none absolute inset-0 z-10 flex flex-col items-center justify-center rounded-xl p-4 text-center opacity-0 transition-opacity group-hover:opacity-100',
        raison: "L'infobulle d'un badge, muette (`aria-hidden`) et sans appui (`pointer-events-none`) : elle redit ce que la carte écrit. Sans souris, la carte ne coupe ni le nom ni la description, et porte la date du déblocage.",
    },
]

/** `sm:souris:group-hover:opacity-100` → `{ variantes: ['sm', 'souris', 'group-hover'], base: 'opacity-100' }`. */
const lireLeMot = (classe) => {
    const morceaux = classe.split(/:(?![^[]*\])/)
    const base = morceaux.pop().replace(/^!|!$/g, '')

    return { classe, variantes: morceaux, base }
}

/**
 * Les défauts d'une liste de classes.
 *
 * @param {string} liste
 * @returns {string[]}
 */
const defautsDeLaListe = (liste) => {
    const mots = classes(liste).map(lireLeMot)

    return PROPRIETES.flatMap(({ nom, cache, montre }) => {
        const caches = mots.filter((mot) => cache.test(mot.base))
        const auSurvol = mots.filter(
            (mot) => montre.test(mot.base) && mot.variantes.some((variante) => SURVOL.test(variante)),
        )

        if (caches.length === 0 || auSurvol.length === 0) {
            return []
        }

        const sansSouris = caches.filter((mot) => !mot.variantes.includes('souris'))

        if (sansSouris.length > 0) {
            return sansSouris.map(
                ({ classe }) => `${classe} cache hors de souris: ce que seul le survol montre (${nom})`,
            )
        }

        const auFocus = mots.some(
            (mot) => montre.test(mot.base) && mot.variantes.some((variante) => FOCUS.test(variante)),
        )

        return auFocus ? [] : [`${auSurvol[0].classe} : le survol le montre, le focus non (${nom})`]
    })
}

/** Un `div` ou un `span` sans contenu, sans écouteur ni rôle. */
const estUnDecor = (element) =>
    ['div', 'span'].includes(element.nom) && element.contenu?.trim() === '' && !PAS_UN_DECOR.test(element.attributs)

const memeListe = (a, b) => classes(a).join(' ') === classes(b).join(' ')

/**
 * Les défauts d'une source, avec les listes que les exemptions ont couvertes.
 *
 * @param {string} source
 * @param {{vue?: boolean, exemptions?: string[]}} options
 * @returns {{defauts: string[], exemptees: string[]}}
 */
const lireLaSource = (source, { vue = true, exemptions = [] } = {}) => {
    const exemptees = []
    const retenue = (liste) => {
        const exemption = exemptions.find((exemptee) => memeListe(exemptee, liste))

        if (exemption !== undefined) {
            exemptees.push(exemption)
        }

        return exemption === undefined
    }
    const elements = vue ? elementsDuGabarit(gabarit(source)).elements : []
    const defauts = [
        ...elements
            .filter((element) => !estUnDecor(element))
            .map(({ liste }) => liste)
            .filter(retenue)
            .flatMap((liste) => defautsDeLaListe(liste).map((defaut) => `« ${liste.trim()} » : ${defaut}`)),
        ...chainesDuScript(scriptDe(source, vue))
            .filter(retenue)
            .flatMap((liste) => defautsDeLaListe(liste).map((defaut) => `« ${liste.trim()} » : ${defaut}`)),
    ]

    return { defauts, exemptees }
}

const fichiers = () => collectSourceFiles({ extensions: ['.vue', '.js'] })

const exemptionsDe = (chemin) =>
    EXEMPTIONS.filter(({ fichier }) => fichier === relatif(chemin)).map(({ classes: liste }) => liste)

const lireLeFichier = (chemin) =>
    lireLaSource(readFileSync(chemin, 'utf8'), { vue: chemin.endsWith('.vue'), exemptions: exemptionsDe(chemin) })

describe('touch affordances', () => {
    it('ne cache derrière le survol que sous une souris, et montre au focus ce que la souris révèle', () => {
        const defauts = fichiers().flatMap((chemin) =>
            lireLeFichier(chemin).defauts.map((defaut) => `${relatif(chemin)} ${defaut}`),
        )

        expect(
            defauts,
            'Cacher jusqu’au survol s’écrit souris:opacity-0 souris:group-hover:opacity-100 souris:focus-within:opacity-100 ' +
                '(focus-visible: sur le bouton lui-même) : sans souris, l’élément reste visible.',
        ).toEqual([])
    })

    it('ne garde que des exemptions qui servent encore', () => {
        const inutiles = EXEMPTIONS.filter(
            ({ fichier, classes: liste }) =>
                !lireLeFichier(resolve(jsRoot, '..', '..', fichier)).exemptees.some((exemptee) => exemptee === liste),
        ).map(({ fichier, classes: liste }) => `${fichier} : « ${liste} »`)

        expect(inutiles).toEqual([])
        expect(EXEMPTIONS.every(({ raison }) => raison.length > 0)).toBe(true)
    })

    it.each([
        ['un bouton caché sans variante', '<button class="opacity-0 group-hover:opacity-100">x</button>'],
        [
            'la largeur prise pour une souris',
            '<div class="opacity-100 sm:opacity-0 sm:group-hover:opacity-100 sm:focus-within:opacity-100"><button>x</button></div>',
        ],
        ['un lien invisible jusqu’au survol', '<div class="invisible group-hover:visible"><a href="/">x</a></div>'],
        [
            'un menu caché jusqu’au survol d’un groupe nommé',
            '<div class="hidden group-hover/carte:flex"><button>x</button></div>',
        ],
        ['le survol de l’élément lui-même', '<span class="opacity-0 hover:opacity-100">Supprimer</span>'],
        ['une variante arbitraire', '<div class="opacity-0 [&:hover]:opacity-100"><button>x</button></div>'],
        [
            'une liste répartie entre class et :class',
            `<div class="sm:opacity-0" :class="{ 'sm:group-hover:opacity-100': actif }"><button>x</button></div>`,
        ],
        [
            'un composant, même refermé seul',
            '<GlassIconButton class="opacity-0 hover:opacity-100" icon="edit" label="x" />',
        ],
        ['un élément vide qui écoute le clic', '<div class="opacity-0 group-hover:opacity-100" @click="ouvrir"></div>'],
        ['un élément vide que Vue remplit', '<span class="opacity-0 group-hover:opacity-100" v-text="nom"></span>'],
        [
            'une souris sans focus',
            '<GlassIconButton class="souris:opacity-0 souris:group-hover:opacity-100" icon="edit" label="x" />',
        ],
        [
            'un focus d’une autre propriété',
            '<div class="souris:opacity-0 souris:group-hover:opacity-100 souris:focus-within:visible"><button>x</button></div>',
        ],
        [
            'une souris doublée d’un masque sans variante',
            '<div class="opacity-0 souris:opacity-0 souris:group-hover:opacity-100 focus-within:opacity-100"><a>x</a></div>',
        ],
    ])('refuse %s', (_cas, balise) => {
        expect(lireLaSource(`<template>${balise}</template>`).defauts).not.toEqual([])
    })

    it('refuse une liste de classes écrite dans le script', () => {
        const source = `<script setup>const masque = 'opacity-0 group-hover:opacity-100'</script><template><div :class="masque"><button>x</button></div></template>`

        expect(lireLaSource(source).defauts).toHaveLength(1)
        expect(
            lireLaSource(`const masque = 'sm:opacity-0 sm:group-hover:opacity-100'`, { vue: false }).defauts,
        ).toHaveLength(1)
    })

    it.each([
        [
            'une souris, son survol et le focus du conteneur',
            '<div class="souris:opacity-0 souris:group-hover:opacity-100 souris:focus-within:opacity-100"><button>x</button></div>',
        ],
        [
            'le focus visible du bouton lui-même',
            '<GlassIconButton class="souris:opacity-0 souris:group-hover:opacity-100 souris:focus-visible:opacity-100" icon="x" label="x" />',
        ],
        [
            'une souris à partir de sm:',
            '<div class="sm:souris:opacity-0 sm:souris:group-hover:opacity-100 sm:souris:focus-within:opacity-100"><a>x</a></div>',
        ],
        ['un halo vide', '<div class="opacity-0 transition-opacity group-hover:opacity-100"></div>'],
        ['un dégradé refermé seul', '<span class="opacity-0 group-hover:opacity-90" />'],
        ['un bouton que le survol ne fait que teinter', '<button class="opacity-60 hover:opacity-100">x</button>'],
        [
            'une transition, dont les classes ne sont pas celles de la balise',
            '<Transition enter-from-class="opacity-0" leave-to-class="opacity-0"><p class="hover:opacity-80">x</p></Transition>',
        ],
        ['une bascule par la largeur, sans survol', '<div class="hidden sm:flex"><button>x</button></div>'],
    ])('accepte %s', (_cas, balise) => {
        expect(lireLaSource(`<template>${balise}</template>`).defauts).toEqual([])
    })

    it('laisse passer une liste exemptée, et elle seule', () => {
        const masque = 'opacity-0 group-hover:opacity-100'
        const source = `<template><div class="${masque}"><p>a</p></div><div class="invisible group-hover:visible"><p>b</p></div></template>`

        expect(lireLaSource(source, { exemptions: [masque] })).toEqual({
            defauts: [
                '« invisible group-hover:visible » : invisible cache hors de souris: ce que seul le survol montre (visibilité)',
            ],
            exemptees: [masque],
        })
    })
})

/**
 * La variante `souris` vaut quand le pointeur principal survole et vise fin.
 *
 * Sa requête se lit dans app.css, et s'évalue ici pour des appareils types :
 * une condition de plus ou de moins y change qui voit les boutons. Elle ne
 * connaît que `hover` et `pointer` ; une requête qu'elle ne sait pas lire la
 * fait échouer, plutôt que de passer pour juste.
 */
const REQUETE_DE_LA_SOURIS = /@custom-variant souris \(@media (.+)\);/.exec(appCss)?.[1] ?? ''

/**
 * @param {string} requete
 * @param {{hover: string, pointer: string}} appareil
 * @returns {boolean}
 */
const requeteValable = (requete, appareil) =>
    requete.split(/\s+and\s+/).every((condition) => {
        const lue = /^\((hover|pointer):\s*([a-z]+)\)$/.exec(condition.trim())

        if (lue === null) {
            throw new Error(`Requête de la variante souris illisible : ${requete}`)
        }

        return appareil[lue[1]] === lue[2]
    })

describe('la variante souris', () => {
    it('est déclarée dans app.css', () => {
        expect(REQUETE_DE_LA_SOURIS).not.toBe('')
    })

    it.each([
        ['une souris', { hover: 'hover', pointer: 'fine' }, true],
        ['une tablette ou un téléphone, au doigt', { hover: 'none', pointer: 'coarse' }, false],
        ['Chrome sans tête, sans pointeur', { hover: 'none', pointer: 'none' }, false],
        ['un pointeur grossier qui annoncerait le survol', { hover: 'hover', pointer: 'coarse' }, false],
        ['un pointeur fin qui ne survole pas', { hover: 'none', pointer: 'fine' }, false],
    ])('%s : %o, boutons cachés jusqu’au survol : %s', (_appareil, appareil, cache) => {
        expect(requeteValable(REQUETE_DE_LA_SOURIS, appareil)).toBe(cache)
    })

    it('refuse une requête qu’elle ne sait pas lire', () => {
        expect(() => requeteValable('(width >= 40rem)', { hover: 'hover', pointer: 'fine' })).toThrow(/illisible/)
    })
})
