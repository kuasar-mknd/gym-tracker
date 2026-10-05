import { describe, it, expect, afterEach } from 'vitest'
import { mount } from '@vue/test-utils'
import { nombre } from '@/Utils/nombre'

/**
 * Les graphiques tels qu'un lecteur d'écran les rencontre (#1970).
 *
 * Ici, vue-chartjs n'est PAS remplacé : c'est lui qui rend le `<canvas
 * role="img">`, et c'est sur ce canevas que le nom et la description doivent
 * arriver. Les suites voisines le remplacent par un enregistreur, qui accepte
 * n'importe quel attribut sans rien en faire : elles ne pouvaient pas voir que
 * le canevas restait sans nom.
 */
const cartes = import.meta.glob(['@/Components/Stats/*Chart.vue', '!@/Components/Stats/BaseChart.vue'], {
    eager: true,
    import: 'default',
})

const montes = []

const monter = (composant, props) => {
    const wrapper = mount(composant, { props, attachTo: document.body })
    montes.push(wrapper)

    return wrapper
}

afterEach(() => {
    montes.splice(0).forEach((wrapper) => wrapper.unmount())
})

/** Le nom et la description que l'arbre d'accessibilité calcule pour le canevas. */
const lectureDuCanevas = (wrapper) => {
    const canevas = wrapper.get('canvas').element
    const idDeLaDescription = canevas.getAttribute('aria-describedby')

    return {
        role: canevas.getAttribute('role'),
        nom: canevas.getAttribute('aria-label'),
        description: idDeLaDescription ? document.getElementById(idDeLaDescription)?.textContent.trim() : null,
    }
}

/** Les cartes qui ne tracent pas une prop `data`. */
const PROPS_PROPRES = { 'ExerciseCategoryChart.vue': { exercises: [] } }

const carte = (nom) => cartes[`/resources/js/Components/Stats/${nom}.vue`]

describe('un graphique se lit sans le voir', () => {
    it('trouve les quarante-huit cartes de graphique', () => {
        expect(Object.keys(cartes)).toHaveLength(48)
    })

    it('nomme le canevas des habitudes et écrit les valeurs tracées', () => {
        const wrapper = monter(carte('HabitConsistencyChart'), {
            data: [
                { date: '2026-07-29', count: 1 },
                { date: '2026-07-30', count: 4 },
                { date: '2026-07-31', count: 2 },
            ],
        })

        expect(lectureDuCanevas(wrapper)).toEqual({
            role: 'img',
            nom: 'Habitudes complétées par jour',
            description: 'Habitudes complétées — 29/07 : 1 ; 30/07 : 4 ; 31/07 : 2.',
        })
    })

    it('écrit les répétitions maximales, au format de l’application', () => {
        const wrapper = monter(carte('MaxRepsChart'), {
            data: [
                { date: '01/10', reps: 12 },
                { date: '08/10', reps: 15 },
            ],
        })

        expect(lectureDuCanevas(wrapper)).toEqual({
            role: 'img',
            nom: 'Répétitions maximales par séance',
            description: 'Répétitions max — 01/10 : 12 ; 08/10 : 15.',
        })
    })

    it('sépare les milliers et garde les décimales à la française', () => {
        const wrapper = monter(carte('MonthlyVolumeChart'), {
            data: [
                { month: 'sept.', volume: 15750 },
                { month: 'oct.', volume: 980.5 },
            ],
        })

        // Le séparateur des milliers dépend de la version d'ICU : on le prend où
        // l'application le prend.
        expect(lectureDuCanevas(wrapper).description).toBe(`Volume — sept. : ${nombre(15750)} ; oct. : 980,5.`)
    })

    it('écrit chaque part d’un anneau avec son compte', () => {
        const wrapper = monter(carte('TimeOfDayChart'), {
            data: [
                { label: 'Matin', count: 3 },
                { label: 'Soir', count: 5 },
            ],
        })

        expect(lectureDuCanevas(wrapper)).toEqual({
            role: 'img',
            nom: 'Répartition des séances selon le moment de la journée',
            description: 'Matin : 3 ; Soir : 5.',
        })
    })

    it('lit un nuage de points par les titres de ses axes', () => {
        const wrapper = monter(carte('WeightRepsScatterChart'), {
            data: [
                { x: 80, y: 5 },
                { x: 102.5, y: 3 },
            ],
        })

        expect(lectureDuCanevas(wrapper).description).toBe(
            'Séries — Poids (kg) : 80, Répétitions : 5 ; Poids (kg) : 102,5, Répétitions : 3.',
        )
    })

    it('écrit une phrase par série, chacune sous son nom', () => {
        const wrapper = monter(carte('SessionPerformanceChart'), {
            data: [
                { formatted_date: '08/10/2026', best_1rm: 105, sets: [{ weight: 100, reps: 5 }] },
                { formatted_date: '01/10/2026', best_1rm: 100, sets: [{ weight: 90, reps: 5 }] },
            ],
        })

        expect(lectureDuCanevas(wrapper).description).toBe(
            'Meilleur 1RM (kg) — 01/10 : 100 ; 08/10 : 105. Volume total — 01/10 : 450 ; 08/10 : 500.',
        )
    })

    it('dit qu’il n’y a rien à lire plutôt que de rendre une description vide', () => {
        const wrapper = monter(carte('MaxRepsChart'), { data: [] })

        expect(lectureDuCanevas(wrapper).description).toBe('Aucune donnée.')
    })

    /**
     * La garde de `tests/js/conventions/chartChunks.test.js` lit les sources ;
     * celle-ci monte chaque carte et regarde le canevas réellement rendu.
     */
    it.each(Object.keys(cartes).map((chemin) => [chemin.split('/').pop(), chemin]))(
        '%s donne un nom et une description à son canevas',
        (_, chemin) => {
            const wrapper = monter(cartes[chemin], PROPS_PROPRES[chemin.split('/').pop()] ?? { data: [] })

            if (!wrapper.find('canvas').exists()) {
                // Sans donnée, la carte montre son état vide, et aucune image.
                return
            }

            const { role, nom, description } = lectureDuCanevas(wrapper)

            expect(role).toBe('img')
            expect(nom?.trim()).toBeTruthy()
            expect(description).toBeTruthy()
        },
    )
})
