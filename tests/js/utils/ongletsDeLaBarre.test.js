import { describe, expect, it } from 'vitest'
import { readdirSync, readFileSync, statSync } from 'node:fs'
import { join } from 'node:path'

import { ONGLETS, ongletDeLaRoute } from '@/Utils/ongletsDeLaBarre'

/*
 * La table des onglets est celle des retours (#2434) : la page qui déclare un
 * `back-route` et la cible de ce retour allument le même onglet.
 */
const PAGES = join(process.cwd(), 'resources/js/Pages')

const fichiersVue = (dossier) =>
    readdirSync(dossier).flatMap((nom) => {
        const chemin = join(dossier, nom)

        return statSync(chemin).isDirectory() ? fichiersVue(chemin) : chemin.endsWith('.vue') ? [chemin] : []
    })

/** Chaque `back-route="…"` écrit dans une page, avec la page qui l'écrit. */
const retoursDeclares = fichiersVue(PAGES).flatMap((fichier) =>
    [...readFileSync(fichier, 'utf8').matchAll(/back-route="([^"]+)"/g)].map(([, cible]) => ({
        page: fichier.replace(`${PAGES}/`, ''),
        cible,
    })),
)

describe('les onglets de la barre', () => {
    it('sont quatre, dans l’ordre de la maquette, chacun avec sa page', () => {
        expect(ONGLETS.map(({ nom, route }) => [nom, route])).toEqual([
            ['Accueil', 'dashboard'],
            ['Séances', 'workouts.index'],
            ['Progrès', 'stats.index'],
            ['Profil', 'profile.index'],
        ])
    })

    it('ne se partagent aucune famille de routes', () => {
        const familles = ONGLETS.flatMap((onglet) => onglet.familles)

        expect(new Set(familles).size).toBe(familles.length)
    })

    it('allument leur propre page', () => {
        for (const onglet of ONGLETS) {
            expect(ongletDeLaRoute(onglet.route)?.cle).toBe(onglet.cle)
        }
    })
})

describe('l’onglet d’une route', () => {
    it.each([
        ['calendar.index', 'seances'],
        ['templates.edit', 'seances'],
        ['workouts.show', 'seances'],
        ['goals.index', 'progres'],
        ['achievements.index', 'progres'],
        ['body-measurements.index', 'progres'],
        ['body-parts.show', 'progres'],
        ['exercises.index', 'progres'],
        ['exercises.show', 'progres'],
        ['habits.index', 'profil'],
        ['daily-journals.index', 'profil'],
        ['supplements.index', 'profil'],
        ['tools.index', 'profil'],
        ['plates.index', 'profil'],
    ])('%s allume %s', (route, cle) => {
        expect(ongletDeLaRoute(route)?.cle).toBe(cle)
    })

    it('n’en allume aucun pour les notifications, ni pour une route sans nom', () => {
        expect(ongletDeLaRoute('notifications.index')).toBeNull()
        expect(ongletDeLaRoute(null)).toBeNull()
        expect(ongletDeLaRoute(undefined)).toBeNull()
        expect(ongletDeLaRoute('')).toBeNull()
    })
})

describe('la cible de chaque retour déclaré par une page', () => {
    it('en trouve, pour que la garde ne passe pas à vide', () => {
        expect(retoursDeclares.length).toBeGreaterThan(20)
    })

    it('résout vers exactement un onglet, sauf les notifications, qui n’en ont aucun', () => {
        const sansOnglet = retoursDeclares.filter(({ cible }) => ongletDeLaRoute(cible) === null)

        expect(sansOnglet).toEqual([])
        expect(ongletDeLaRoute('notifications.index')).toBeNull()
        expect(
            retoursDeclares.filter(({ page }) => page.startsWith('Notifications/')).map(({ cible }) => cible),
        ).toEqual(['dashboard'])
    })

    /** La page des notifications est la seule dont le retour mène à un onglet qu'elle n'allume pas. */
    it('allume, pour les pages secondaires, l’onglet où mène leur retour', () => {
        const PAGES_ET_LEUR_ROUTE = {
            'Calendar/Index.vue': 'calendar.index',
            'Goals/Index.vue': 'goals.index',
            'Goals/Edit.vue': 'goals.edit',
            'Achievements/Index.vue': 'achievements.index',
            'Measurements/Index.vue': 'body-measurements.index',
            'Measurements/Parts/Index.vue': 'body-parts.index',
            'Exercises/Index.vue': 'exercises.index',
            'Exercises/Show.vue': 'exercises.show',
            'Habits/Index.vue': 'habits.index',
            'Journal/Index.vue': 'daily-journals.index',
            'Supplements/Index.vue': 'supplements.index',
            'Workouts/Templates/Index.vue': 'templates.index',
            'Workouts/Templates/Edit.vue': 'templates.edit',
            'Profile/Edit.vue': 'profile.edit',
            'Tools/Index.vue': 'tools.index',
            'Tools/OneRepMax.vue': 'tools.1rm',
            'Raccourcis.vue': 'shortcuts.index',
        }

        for (const [page, route] of Object.entries(PAGES_ET_LEUR_ROUTE)) {
            const retour = retoursDeclares.find((declaration) => declaration.page === page)

            expect(retour, `${page} déclare un retour`).toBeDefined()
            expect(ongletDeLaRoute(route)?.cle, `${page} (${route}) mène à ${retour.cible}`).toBe(
                ongletDeLaRoute(retour.cible)?.cle,
            )
        }
    })
})
