import { describe, expect, it } from 'vitest'

import {
    CHAMPS_DE_LA_PAGE_SERVIE,
    adressePeutAvoirUneCopie,
    estUneCopie,
    pageServie,
    propsDiffereesAbsentes,
} from '@/Utils/copiesServies'

/*
 * La page qu'une copie devient quand l'application ouverte la sert (#2048,
 * partie E) : chaque champ écrit, rien de la page quittée. Le vrai client
 * d'Inertia la pousse dans `copiesServiesAvecInertia.test.js`.
 */

/** La copie de l'accueil, telle que le magasin la rend. */
const copieDeLAccueil = () => ({
    copieDu: Date.parse('2026-10-12T18:04:00Z'),
    page: {
        component: 'Dashboard',
        url: '/dashboard',
        version: 'version-de-la-copie',
        encryptHistory: true,
        clearHistory: false,
        deferredProps: { default: ['analyticalStats'], autre: ['pointe.prop'] },
        sharedProps: ['auth', 'flash'],
        props: {
            errors: {},
            auth: { user: { id: 7, active_workout: null, latest_achievement: null } },
            flash: { success: null, error: null },
            recentWorkouts: [{ id: 1 }],
        },
    },
})

/** Une page courante dont chaque champ porte une sentinelle. */
const pageQuittee = () => ({
    component: 'Workouts/Show',
    url: '/workouts/12',
    version: 'version-courante',
    encryptHistory: false,
    clearHistory: true,
    preserveFragment: 'sentinelle',
    preserveBigIntegers: 'sentinelle',
    deferredProps: { sentinelle: ['sentinelle'] },
    initialDeferredProps: { sentinelle: ['sentinelle'] },
    rescuedProps: ['sentinelle'],
    mergeProps: ['sentinelle'],
    prependProps: ['sentinelle'],
    deepMergeProps: ['sentinelle'],
    matchPropsOn: ['sentinelle'],
    sharedProps: ['sentinelle'],
    scrollProps: { sentinelle: {} },
    flash: { success: 'sentinelle' },
    onceProps: { sentinelle: { prop: 'sentinelle' } },
    rememberedState: { sentinelle: 'sentinelle' },
    optimisticUpdatedAt: { sentinelle: 1 },
    cleInconnue: 'sentinelle',
    props: { errors: { champ: 'sentinelle' }, sentinelle: 'sentinelle' },
})

const auth = { user: { id: 7, active_workout: { id: 12 }, latest_achievement: { name: 'Fer' } } }

describe('la page servie', () => {
    it('écrit chaque champ de la page, et ne garde de la page quittée que sa version', () => {
        const servie = pageServie({ copie: copieDeLAccueil(), pageCourante: pageQuittee(), auth, raison: 'reseau' })

        expect(servie).toEqual({
            component: 'Dashboard',
            url: '/dashboard',
            version: 'version-courante',
            props: {
                errors: {},
                auth: { user: { id: 7, active_workout: { id: 12 }, latest_achievement: null } },
                flash: { success: null, error: null },
                recentWorkouts: [{ id: 1 }],
                horsLigne: { copieDu: Date.parse('2026-10-12T18:04:00Z'), raison: 'reseau' },
            },
            flash: {},
            deferredProps: undefined,
            initialDeferredProps: undefined,
            rescuedProps: ['analyticalStats', 'pointe.prop'],
            mergeProps: [],
            prependProps: [],
            deepMergeProps: [],
            matchPropsOn: [],
            onceProps: {},
            scrollProps: undefined,
            sharedProps: ['auth', 'flash'],
            rememberedState: {},
            clearHistory: false,
            encryptHistory: true,
            preserveFragment: undefined,
            preserveBigIntegers: undefined,
            optimisticUpdatedAt: undefined,
            cleInconnue: undefined,
        })
        expect(Object.hasOwn(servie, 'cleInconnue')).toBe(true)
        expect(JSON.stringify(servie)).not.toContain('sentinelle')
    })

    it('chiffre l’historique, que la page quittée le chiffre ou non', () => {
        const enClair = { ...pageQuittee(), encryptHistory: false }
        const copie = copieDeLAccueil()
        copie.page.encryptHistory = false

        expect(pageServie({ copie, pageCourante: enClair, auth, raison: 'lent' }).encryptHistory).toBe(true)
    })

    it('liste les props différées que la copie n’a pas, d’après les annonces d’origine', () => {
        const copie = copieDeLAccueil()
        copie.page.props.analyticalStats = { weeklyVolume: {} }
        copie.page.initialDeferredProps = { default: ['analyticalStats'], autre: ['pointe.prop', 'pointe.autre'] }
        copie.page.props.pointe = { prop: 1 }

        expect(propsDiffereesAbsentes(copie.page)).toEqual(['pointe.autre'])
        expect(pageServie({ copie, pageCourante: null, auth, raison: 'reseau' }).rescuedProps).toEqual(['pointe.autre'])
        expect(propsDiffereesAbsentes({ props: {} })).toEqual([])
    })

    it('prend la version de la copie quand aucune page n’est encore affichée', () => {
        expect(pageServie({ copie: copieDeLAccueil(), pageCourante: null, auth, raison: 'reseau' }).version).toBe(
            'version-de-la-copie',
        )
    })

    it('nomme chaque champ qu’elle écrit dans `CHAMPS_DE_LA_PAGE_SERVIE`', () => {
        const servie = pageServie({ copie: copieDeLAccueil(), pageCourante: null, auth, raison: 'reseau' })

        expect(Object.keys(servie).sort()).toEqual([...CHAMPS_DE_LA_PAGE_SERVIE].sort())
    })
})

describe('la marque et les adresses', () => {
    it('reconnaît une copie à sa marque', () => {
        expect(estUneCopie({ props: { horsLigne: { copieDu: 1, raison: 'reseau' } } })).toBe(true)
        expect(estUneCopie({ props: { horsLigne: null } })).toBe(false)
        expect(estUneCopie({ props: {} })).toBe(false)
        expect(estUneCopie(null)).toBe(false)
    })

    it('ne cherche une copie qu’aux adresses de la liste blanche, sans filtre', () => {
        expect(
            ['/dashboard', '/', '/workouts', '/workouts?page=1', '/workouts/42', '/tools/1rm'].map(
                adressePeutAvoirUneCopie,
            ),
        ).toEqual([true, true, true, true, true, true])
        expect(
            [
                '/stats',
                '/workouts?type=jambes',
                '/workouts?page=2',
                '/exercises/3',
                '/journal',
                '/profile/edit',
                null,
            ].map(adressePeutAvoirUneCopie),
        ).toEqual([false, false, false, false, false, false, false])
    })
})
