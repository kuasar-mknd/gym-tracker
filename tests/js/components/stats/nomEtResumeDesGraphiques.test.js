import { describe, it, expect, afterEach, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { Chart } from 'chart.js'
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
    vi.restoreAllMocks()
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

/** Deux jours du journal, dans le désordre : la carte les remet dans l'ordre. */
const JOURNAL = [
    { date: '2026-10-02', mood_score: 3, energy_level: 8 },
    { date: '2026-10-01', mood_score: 4, energy_level: 7 },
]

/**
 * Les props propres à une carte, quand `{ data: [] }` ne suffit pas.
 *
 * `ExerciseCategoryChart` ne trace pas une prop `data`. `JournalChart` et
 * `BodyPartDiffChart` montrent leur état vide, sans canevas, tant qu'ils n'ont
 * rien à tracer : sans point, la garde ci-dessous n'aurait rien lu chez eux.
 * Avec ces props, chacune des vingt-huit cartes rend son canevas.
 */
const PROPS_PROPRES = {
    'ExerciseCategoryChart.vue': { exercises: [] },
    'JournalChart.vue': { data: JOURNAL },
    'BodyPartDiffChart.vue': { data: [{ part: 'Taille', diff: -1.5, unit: 'cm' }] },
}

const carte = (nom) => cartes[`/resources/js/Components/Stats/${nom}.vue`]

const boutonNomme = (wrapper, nom) => wrapper.findAll('button').find((bouton) => bouton.text() === nom)

describe('un graphique se lit sans le voir', () => {
    it('trouve les vingt-huit cartes de graphique', () => {
        expect(Object.keys(cartes)).toHaveLength(28)
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
        const wrapper = monter(carte('MuscleDistributionChart'), {
            data: [
                { category: 'Pectoraux', volume: 3 },
                { category: 'Dos', volume: 5 },
            ],
        })

        expect(lectureDuCanevas(wrapper)).toEqual({
            role: 'img',
            nom: 'Répartition du volume par groupe musculaire',
            description: 'Pectoraux : 3 ; Dos : 5.',
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
        const wrapper = monter(carte('MacroHistoryChart'), {
            data: [
                { created_at: '2026-10-08T10:00:00Z', target_calories: 250, tdee: 270 },
                { created_at: '2026-10-01T10:00:00Z', target_calories: 240, tdee: 260 },
            ],
        })

        expect(lectureDuCanevas(wrapper).description).toBe(
            'Cible (kcal) — 01/10 : 240 ; 08/10 : 250. TDEE (kcal) — 01/10 : 260 ; 08/10 : 270.',
        )
    })

    /**
     * Le seul nom calculé : il suit la métrique que la personne choisit. Un nom
     * figé, ou vide, laisserait croire au lecteur d'écran qu'il lit encore
     * l'humeur quand la courbe montre l'énergie.
     */
    it('nomme le journal d’après la métrique choisie', async () => {
        /*
         * jsdom ne donne à Chart.js aucun contexte qu'il accepte : le graphique
         * avorte à sa création (« Failed to create chart »). vue-chartjs le
         * redessine pourtant quand ses séries changent, et ce dessin lèverait
         * sur le graphique avorté. Le canevas, lui, est bien rendu.
         */
        vi.spyOn(Chart.prototype, 'update').mockImplementation(() => {})

        const wrapper = monter(carte('JournalChart'), { data: JOURNAL })

        expect(lectureDuCanevas(wrapper)).toEqual({
            role: 'img',
            nom: 'Humeur au fil des jours, note sur 5',
            description: 'Humeur — 01/10 : 4 ; 02/10 : 3.',
        })

        await boutonNomme(wrapper, 'Énergie').trigger('click')
        await flushPromises()

        expect(lectureDuCanevas(wrapper)).toEqual({
            role: 'img',
            nom: 'Énergie au fil des jours, note sur 10',
            description: 'Énergie — 01/10 : 7 ; 02/10 : 8.',
        })
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
        (fichier, chemin) => {
            const wrapper = monter(cartes[chemin], PROPS_PROPRES[fichier] ?? { data: [] })

            expect(wrapper.find('canvas').exists(), `${fichier} ne rend aucun canevas`).toBe(true)

            const { role, nom, description } = lectureDuCanevas(wrapper)

            expect(role).toBe('img')
            expect(nom?.trim()).toBeTruthy()
            expect(description).toBeTruthy()
        },
    )
})
