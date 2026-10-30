import { describe, it, expect, vi, afterEach } from 'vitest'
import { mount } from '@vue/test-utils'
import { readFileSync } from 'node:fs'
import { reactive } from 'vue'

import { elementsDuGabarit, gabarit } from './gabarits'
import { LARGEURS, afficheA, exposeA } from './exposition'
import { collectSourceFiles, jsRoot } from './sourceFiles'

const page = reactive({ props: {}, url: '/', component: 'Page' })

vi.mock('@inertiajs/vue3', () => ({
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
    usePage: () => page,
}))
vi.mock('@/composables/useAbonnementPush', () => ({ rapprocherLAbonnementPush: () => {} }))
vi.mock('@/composables/useDeconnexion', () => ({ seDeconnecter: () => {} }))
vi.mock('@/Utils/http', () => ({ http: () => Promise.reject({ code: 'ERR_NETWORK', request: {} }) }))

import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'

/**
 * Chaque page sous `AuthenticatedLayout` expose exactement un `<h1>`, sur
 * téléphone comme sur bureau (#2120).
 *
 * Le seul `<h1>` du layout était dans l'en-tête mobile, caché à partir de
 * 1 024 px (`bureau:hidden`) : sur bureau, vingt-deux pages n'avaient aucun
 * titre de niveau 1, et la touche 1 de NVDA ou le rotor de VoiceOver n'y
 * trouvaient rien. Treize titraient leur en-tête de bureau (`#header`) par un
 * `<h2>`, neuf n'en avaient aucun.
 *
 * Désormais :
 * - sur téléphone, l'en-tête mobile porte le titre (`page-title`), ou la page
 *   pose le sien à toute largeur (les calculateurs, qui n'ont qu'un retour) ;
 * - sur bureau, l'en-tête de page (`#header`) porte le `<h1>` ; une page qui
 *   n'en a pas reçoit du layout un `<h1>` `sr-only` tiré de `page-title` ; une
 *   page qui pose elle-même son titre de bureau dans son contenu (les
 *   statistiques, la bibliothèque, les compléments) le dit par
 *   `titre-dans-la-page`, et le layout s'en dispense.
 *
 * La garde monte le VRAI layout avec les attributs que chaque page lui passe et
 * des marques dans ses créneaux : elle y lit ses `<h1>` et ce que chaque
 * créneau montre à 390 et à 1 280 px. Elle lit ensuite les `<h1>` de la page,
 * leur créneau et les classes qui les entourent, et additionne.
 *
 * Un `page-title` lié (`:page-title`) peut valoir `null` : le nom d'une séance
 * se vide par ses réglages (`'name' => 'nullable'`), et les deux `<h1>` du
 * layout tiennent à `pageTitle`. La garde compte donc aussi la page sans
 * titre, sauf quand l'expression finit par un repli écrit (`|| 'Séance'`) ou
 * que la page figure dans `TITRES_LIES_JAMAIS_VIDES`, avec sa raison.
 *
 * Un `<h1>` posé par un composant que la page monte échapperait à ce compte :
 * la garde refuse donc un `<h1>` dans tout composant, c'est-à-dire tout
 * fichier `.vue` qui n'est ni un layout ni une page (qui monte
 * `AuthenticatedLayout` ou `GuestLayout`), les partiels d'une page
 * (`Pages/Profile/Partials`) compris. Ce qu'elle ne voit pas : un `<h1>` sous
 * `v-if` compte comme rendu.
 */
const LAYOUT = /<AuthenticatedLayout\b/

/**
 * Les pages dont le `:page-title` lié ne peut pas être vide, et pourquoi.
 */
const TITRES_LIES_JAMAIS_VIDES = new Map([
    [
        'resources/js/Pages/Exercises/Show.vue',
        'exercises.name est NOT NULL, et requis par ExerciseStoreRequest et ExerciseUpdateRequest',
    ],
    [
        'resources/js/Pages/Measurements/Parts/Show.vue',
        'la partie est un segment non vide de l’adresse, et son libellé une chaîne que le serveur tire d’elle',
    ],
])

/** Une expression qui finit par un repli écrit et non vide : `seance.name || 'Séance'`. */
const REPLI_ECRIT = /\|\|\s*(?:'[^']*\S[^']*'|`[^`]*\S[^`]*`)\s*$/

/** Ziggy, réduit à ce que le layout lui demande : une adresse, ou `route().current(…)`. */
const route = (nom) => (nom === undefined ? { current: () => false } : `/${nom}`)
globalThis.route = route

const montes = []

afterEach(() => {
    montes.splice(0).forEach((wrapper) => wrapper.unmount())
})

const ENFANTS_SANS_TITRE = {
    LiquidBackground: true,
    CelebrationModal: true,
    BottomNav: true,
    QuestionDeDeconnexion: true,
    BandeauHorsLigne: true,
    BandeauDeMiseAJour: true,
    ToastsDuLayout: true,
    ActiveWorkoutBanner: true,
    Dropdown: true,
}

/**
 * Ce que le vrai layout expose pour une page : ses propres `<h1>`, et ce que
 * chacun de ses créneaux montre, à chaque largeur.
 *
 * @param {{pageTitle: boolean, showBack: boolean, titreDansLaPage: boolean, creneaux: string[]}} reglages
 * @returns {Record<string, {titres: number, creneaux: Record<string, boolean>}>}
 */
const ceQueLeLayoutExpose = ({ pageTitle, showBack, titreDansLaPage, creneaux }) => {
    page.props = { auth: { user: { name: 'Samuel', unread_notifications_count: 0 } }, flash: {} }

    const wrapper = mount(AuthenticatedLayout, {
        props: { pageTitle: pageTitle ? 'Titre' : '', showBack, titreDansLaPage },
        slots: Object.fromEntries(
            ['default', ...creneaux].map((creneau) => [creneau, `<i data-creneau="${creneau}"></i>`]),
        ),
        global: { directives: { press: {} }, mocks: { route, $page: page }, stubs: ENFANTS_SANS_TITRE },
    })
    montes.push(wrapper)

    return Object.fromEntries(
        Object.entries(LARGEURS).map(([nom, largeur]) => [
            nom,
            {
                titres: wrapper.findAll('h1').filter((titre) => exposeA(titre.element, largeur)).length,
                creneaux: Object.fromEntries(
                    wrapper
                        .findAll('[data-creneau]')
                        .map((marque) => [marque.attributes('data-creneau'), exposeA(marque.element, largeur)]),
                ),
            },
        ]),
    )
}

/** Le nom du créneau qu'un `<template #…>` remplit, ou `null` pour un autre élément. */
const nomDuCreneau = (element) =>
    element.nom === 'template' ? (/(?:^|\s)(?:#|v-slot:)([\w-]+)/.exec(element.attributs)?.[1] ?? null) : null

/** Le créneau du layout où se pose un élément de la page : celui de son `<template #…>`, sinon le créneau par défaut. */
const creneauDe = (element) => {
    for (let parent = element.parent; parent !== null; parent = parent.parent) {
        if (nomDuCreneau(parent) !== null) {
            return nomDuCreneau(parent)
        }
    }

    return 'default'
}

/** L'élément et ses ancêtres dans la page, jusqu'à son créneau, sont-ils affichés à cette largeur ? */
const afficheDansLaPage = (element, largeur) => {
    for (let courant = element; courant !== null && courant.nom !== 'AuthenticatedLayout'; courant = courant.parent) {
        if (!afficheA(courant.liste, largeur) || /(?:^|\s)aria-hidden="true"/.test(courant.attributs)) {
            return false
        }
    }

    return true
}

/**
 * Ce que la page passe au layout pour `page-title` : un titre écrit, un titre
 * lié qui peut être vide, ou rien.
 *
 * @param {string} attributs
 * @param {boolean} titreLieNonVide
 * @returns {'ecrit'|'peutEtreVide'|'absent'}
 */
const titreDuLayout = (attributs, titreLieNonVide) => {
    const lie = /(?:^|\s)(?::|v-bind:)page-title\s*=\s*"([^"]*)"/.exec(attributs)

    if (lie !== null) {
        return titreLieNonVide || REPLI_ECRIT.test(lie[1]) ? 'ecrit' : 'peutEtreVide'
    }

    return /(?:^|\s)page-title\s*=\s*"[^"]*\S[^"]*"/.test(attributs) ? 'ecrit' : 'absent'
}

/**
 * Le nombre de `<h1>` qu'une page expose à 390 et à 1 280 px, ceux du layout
 * compris. Pour un titre lié qui peut être vide, le compte qui s'écarte d'un
 * titre par largeur, sans titre d'abord.
 *
 * @param {string} source
 * @param {{titreLieNonVide?: boolean}} [options] Le titre lié ne peut pas être vide.
 * @returns {{telephone: number, bureau: number}|null} `null` pour une page hors du layout.
 */
export const titresDeLaPage = (source, { titreLieNonVide = false } = {}) => {
    const { elements } = elementsDuGabarit(gabarit(source))
    const layout = elements.find(({ nom }) => nom === 'AuthenticatedLayout')

    if (layout === undefined) {
        return null
    }

    const attribut = (nom) => new RegExp(`(?:^|\\s)(?::|v-bind:)?${nom}(?:[=\\s]|$)`).test(layout.attributs)
    const creneaux = elements
        .filter((element) => element.parent === layout)
        .map(nomDuCreneau)
        .filter((creneau) => creneau !== null && creneau !== 'default')
    const titres = elements.filter(({ nom }) => nom === 'h1')
    const titre = titreDuLayout(layout.attributs, titreLieNonVide)

    const compter = (pageTitle) => {
        const expose = ceQueLeLayoutExpose({
            pageTitle,
            showBack: attribut('show-back'),
            titreDansLaPage: attribut('titre-dans-la-page'),
            creneaux,
        })

        return Object.fromEntries(
            Object.entries(LARGEURS).map(([nom, largeur]) => [
                nom,
                expose[nom].titres +
                    titres.filter((h1) => expose[nom].creneaux[creneauDe(h1)] && afficheDansLaPage(h1, largeur)).length,
            ]),
        )
    }

    const comptes = titre === 'peutEtreVide' ? [compter(false), compter(true)] : [compter(titre === 'ecrit')]

    return comptes.find((compte) => Object.values(compte).some((nombre) => nombre !== 1)) ?? comptes[0]
}

const relatif = (chemin) => chemin.replace(jsRoot, 'resources/js')

/**
 * Un composant : un fichier qui n'est ni un layout ni une page. Une page monte
 * son layout, `AuthenticatedLayout` ou `GuestLayout` ; un partiel de page,
 * monté par elle, n'en monte aucun.
 *
 * @param {string} chemin
 * @param {string} source
 * @returns {boolean}
 */
const estUnComposant = (chemin, source) =>
    !chemin.startsWith(`${jsRoot}/Layouts/`) && !/<(?:Authenticated|Guest)Layout\b/.test(source)

describe('un seul titre de niveau 1 par page, à toute largeur', () => {
    it('donne à chaque page sous le layout un h1 sur téléphone et un sur bureau', () => {
        const ecarts = collectSourceFiles()
            .filter((chemin) => chemin.includes('/Pages/') && LAYOUT.test(readFileSync(chemin, 'utf8')))
            .map((chemin) => [
                relatif(chemin),
                titresDeLaPage(readFileSync(chemin, 'utf8'), {
                    titreLieNonVide: TITRES_LIES_JAMAIS_VIDES.has(relatif(chemin)),
                }),
            ])
            .filter(([, compte]) => compte.telephone !== 1 || compte.bureau !== 1)
            .map(([chemin, compte]) => `${chemin} : ${compte.telephone} sur téléphone, ${compte.bureau} sur bureau`)

        expect(
            ecarts,
            'un h1 dans #header, ou titre-dans-la-page sur le layout quand la page pose son propre titre de bureau',
        ).toEqual([])
    })

    /**
     * Le test ne lisait que `resources/js/Components` : un `<h1>` posé dans un
     * partiel de page (`Pages/Profile/Partials`), que `Profile/Edit` monte
     * dans son créneau par défaut, en donnait deux à la page à chaque largeur,
     * sans qu'aucune des deux gardes ne le voie.
     */
    it('ne laisse aucun composant poser un h1, que la garde ne compterait pas', () => {
        const poseurs = collectSourceFiles()
            .filter((chemin) => estUnComposant(chemin, readFileSync(chemin, 'utf8')))
            .filter((chemin) =>
                elementsDuGabarit(gabarit(readFileSync(chemin, 'utf8'))).elements.some(({ nom }) => nom === 'h1'),
            )
            .map(relatif)

        expect(poseurs, 'un h2 : le titre de niveau 1 appartient à la page').toEqual([])
    })

    it('tient pour composant tout fichier qui n’est ni un layout ni une page', () => {
        const page = '<template><AuthenticatedLayout page-title="Profil"><h1 /></AuthenticatedLayout></template>'

        expect(estUnComposant(`${jsRoot}/Components/UI/Carte.vue`, '<template><div /></template>')).toBe(true)
        expect(estUnComposant(`${jsRoot}/Pages/Profile/Partials/Formulaire.vue`, '<template><form /></template>')).toBe(
            true,
        )
        expect(estUnComposant(`${jsRoot}/Pages/Profile/Edit.vue`, page)).toBe(false)
        expect(estUnComposant(`${jsRoot}/Pages/Auth/Login.vue`, '<template><GuestLayout /></template>')).toBe(false)
        expect(estUnComposant(`${jsRoot}/Layouts/AuthenticatedLayout.vue`, '<template><h1 /></template>')).toBe(false)
    })

    it('compte les titres comme un lecteur d’écran les trouve', () => {
        const pageDe = (attributs, corps) =>
            `<script setup></script>\n<template>\n<AuthenticatedLayout ${attributs}>${corps}</AuthenticatedLayout>\n</template>`

        // Les défauts de #2120 : un en-tête de bureau titré en h2, une page sans titre de bureau.
        expect(
            titresDeLaPage(pageDe('page-title="Séances"', '<template #header><h2>Mes Séances</h2></template><p />')),
        ).toEqual({
            telephone: 1,
            bureau: 0,
        })
        expect(
            titresDeLaPage(pageDe('page-title="Séances"', '<template #header><h1>Mes Séances</h1></template><p />')),
        ).toEqual({
            telephone: 1,
            bureau: 1,
        })
        // Un titre de bureau posé dans la page sans le dire au layout : deux titres sur bureau.
        expect(
            titresDeLaPage(
                pageDe('page-title="Stats"', '<div class="bureau:block hidden"><h1>Mon Évolution</h1></div>'),
            ),
        ).toEqual({
            telephone: 1,
            bureau: 2,
        })
        expect(
            titresDeLaPage(
                pageDe(
                    'page-title="Stats" titre-dans-la-page',
                    '<div class="bureau:block hidden"><h1>Mon Évolution</h1></div>',
                ),
            ),
        ).toEqual({ telephone: 1, bureau: 1 })
        // Un titre à toute largeur sous un en-tête mobile qui porte déjà le sien : deux sur téléphone.
        expect(titresDeLaPage(pageDe('page-title="Outil" titre-dans-la-page', '<h1>Calculateur</h1>'))).toEqual({
            telephone: 2,
            bureau: 1,
        })
        // Les calculateurs : un retour sans titre dans l'en-tête mobile, leur titre à toute largeur.
        expect(
            titresDeLaPage(pageDe('show-back back-route="tools.index"', '<header><h1>Calculatrice 1RM</h1></header>')),
        ).toEqual({
            telephone: 1,
            bureau: 1,
        })
        expect(titresDeLaPage(pageDe('show-back', '<p>Rien</p>'))).toEqual({ telephone: 0, bureau: 0 })
        // Le layout pose seul le titre de bureau d'une page qui n'en a pas.
        expect(titresDeLaPage(pageDe('page-title="Accueil"', '<p>Bonjour</p>'))).toEqual({ telephone: 1, bureau: 1 })
        // Une séance dont on a vidé le nom : sans repli, aucun titre à aucune largeur.
        expect(titresDeLaPage(pageDe(':page-title="seance.name" show-back', '<p />'))).toEqual({
            telephone: 0,
            bureau: 0,
        })
        expect(titresDeLaPage(pageDe(':page-title="seance.name || \'Séance\'" show-back', '<p />'))).toEqual({
            telephone: 1,
            bureau: 1,
        })
        expect(titresDeLaPage(pageDe(':page-title="seance.name || \' \'" show-back', '<p />'))).toEqual({
            telephone: 0,
            bureau: 0,
        })
        expect(
            titresDeLaPage(pageDe(':page-title="exercice.name" show-back', '<p />'), { titreLieNonVide: true }),
        ).toEqual({ telephone: 1, bureau: 1 })
        // Un titre lié vide n'enlève rien à un en-tête de page qui porte le sien, sur bureau.
        expect(
            titresDeLaPage(pageDe(':page-title="nom"', '<template #header><h1>{{ nom }}</h1></template><p />')),
        ).toEqual({ telephone: 0, bureau: 1 })
        expect(titresDeLaPage('<template><div /></template>')).toBeNull()
    })
})
