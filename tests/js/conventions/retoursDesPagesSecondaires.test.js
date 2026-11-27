import { describe, it, expect } from 'vitest'
import { readFileSync } from 'node:fs'

import { jsRoot } from './sourceFiles'

/**
 * Hors des quatre racines (accueil, séances, progrès, profil), une page n'a ni
 * onglet actif ni retour par la barre du bas : sans `show-back`, elle n'offre
 * aucune sortie au pouce (#2434). Exception : Modèles et Calendrier, dont la
 * rangée des séances (`OngletsDesSeances`) tient lieu de sortie sur téléphone ;
 * leur `show-back` ne sert alors qu'au bureau (`retour-de-bureau-seul`, plus bas). Chaque page secondaire dit où elle revient ;
 * chacune nomme sa route : sans `back-route`, le retour remonte l'historique, qui peut être vide.
 */
const RETOURS = {
    'Achievements/Index': 'stats.index',
    'Calendar/Index': 'workouts.index',
    'Exercises/Index': 'stats.index',
    'Exercises/Show': 'exercises.index',
    'Goals/Edit': 'goals.index',
    'Goals/Index': 'stats.index',
    'Habits/Index': 'profile.index',
    'Journal/Index': 'profile.index',
    'Corps/Index': 'stats.index',
    'Measurements/Index': 'corps.index',
    'Measurements/Parts/Index': 'corps.index',
    // Sans historique (ouverture à froid, clic sur une notification push), `history.back()` ne ferait rien.
    'Notifications/Index': 'dashboard',
    'Supplements/Index': 'profile.index',
}

const balise = (page) =>
    /<AuthenticatedLayout\b(?<attributs>(?:[^>"']|"[^"]*"|'[^']*')*?)>/s.exec(
        readFileSync(`${jsRoot}/Pages/${page}.vue`, 'utf8'),
    ).groups.attributs

describe('les pages secondaires', () => {
    it.each(Object.entries(RETOURS))('%s offre un retour', (page, route) => {
        const attributs = balise(page)

        expect(attributs).toMatch(/\sshow-back[\s=>]/)

        expect(attributs).toContain(`back-route="${route}"`)
    })
})

/**
 * Modèles et Calendrier sont des pages secondaires de Séances, mais sur
 * téléphone elles reprennent la rangée d'Historique (le segment et la cloche) à
 * la place de la flèche et du titre : la flèche n'y mènerait qu'à l'onglet d'à
 * côté. Leur retour reste, mais sur bureau seulement (`retour-de-bureau-seul`),
 * où l'en-tête n'a pas de segment. Calendar/Index garde donc `show-back` dans
 * RETOURS ci-dessus : c'est le retour de bureau.
 */
describe('Modèles et Calendrier sur mobile', () => {
    it.each(['Workouts/Templates/Index', 'Calendar/Index'])(
        '%s met la rangée des séances à la place de la flèche, et garde le retour sur bureau',
        (page) => {
            const attributs = balise(page)
            const source = readFileSync(`${jsRoot}/Pages/${page}.vue`, 'utf8')

            expect(attributs).toMatch(/\sshow-back[\s=>]/)
            expect(attributs).toMatch(/\sretour-de-bureau-seul[\s=>]/)
            expect(attributs).toMatch(/\stitre-masque[\s=>]/)
            expect(attributs).toContain('back-route="workouts.index"')
            expect(source).toContain('<OngletsDesSeances actif=')
            expect(source).not.toContain('<Cloche')
        },
    )
})

describe('Workouts/Index sur mobile', () => {
    const source = readFileSync(`${jsRoot}/Pages/Workouts/Index.vue`, 'utf8')
    const rangee = readFileSync(`${jsRoot}/Components/Workout/OngletsDesSeances.vue`, 'utf8')

    it('garde le démarrage sous la rangée des séances, qui ne se montre pas sur bureau', () => {
        const contenu = source.slice(source.indexOf('</template>', source.indexOf('<template #header>')))

        expect(contenu).toContain('<OngletsDesSeances actif="historique">')
        expect(contenu).toContain('@click="createWorkout"')
        expect(rangee).toContain('bureau:hidden')
        expect(rangee).toContain("route: 'templates.index'")
        expect(rangee).toContain("route: 'calendar.index'")
    })
})

describe('Exercises/Show', () => {
    it('ne trace le volume par séance qu’une fois', () => {
        const source = readFileSync(`${jsRoot}/Pages/Exercises/Show.vue`, 'utf8')

        expect(source).toContain('<VolumeTrendChart')
        expect(source).not.toContain('SessionVolumeLineChart')
    })
})
