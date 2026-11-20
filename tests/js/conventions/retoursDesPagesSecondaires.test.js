import { describe, it, expect } from 'vitest'
import { readFileSync } from 'node:fs'

import { jsRoot } from './sourceFiles'

/**
 * Hors des quatre racines (accueil, séances, progrès, profil), une page n'a ni
 * onglet actif ni retour par la barre du bas : sans `show-back`, elle n'offre
 * aucune sortie au pouce (#2434). Chaque page secondaire dit où elle revient ;
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

describe('Workouts/Index sur mobile', () => {
    const source = readFileSync(`${jsRoot}/Pages/Workouts/Index.vue`, 'utf8')

    it('garde le démarrage et le segment Historique / Modèles / Calendrier hors du seul créneau de bureau', () => {
        const contenu = source.slice(source.indexOf('</template>', source.indexOf('<template #header>')))

        expect(contenu).toContain('bureau:hidden')
        expect(contenu).toContain('@click="createWorkout"')
        expect(contenu).toContain("route('templates.index')")
        expect(contenu).toContain("route('calendar.index')")
    })
})

describe('Exercises/Show', () => {
    it('ne trace le volume par séance qu’une fois', () => {
        const source = readFileSync(`${jsRoot}/Pages/Exercises/Show.vue`, 'utf8')

        expect(source).toContain('<VolumeTrendChart')
        expect(source).not.toContain('SessionVolumeLineChart')
    })
})
