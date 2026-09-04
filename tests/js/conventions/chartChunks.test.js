import { describe, it, expect } from 'vitest'

import { filesMatching } from './sourceFiles'

/**
 * Chart.js pèse davantage que tout le reste du paquet réuni. Il n'a donc le
 * droit d'entrer que par un morceau séparé, chargé quand un graphique est
 * réellement affiché — ce que fait `defineAsyncComponent`.
 *
 * Le tableau de bord tirait `ActiveGoalsChart` en import direct, seul parmi ses
 * neuf graphiques. La première page après connexion payait donc la bibliothèque
 * entière, et rien ne le disait : le patron était partout ailleurs respecté.
 */
describe('les graphiques restent dans leur propre morceau', () => {
    it("n'importe chart.js que depuis un composant de graphique", () => {
        const offenders = filesMatching(/from '(?:chart\.js|vue-chartjs)'/).filter(
            (file) => !file.startsWith('resources/js/Components/Stats/'),
        )

        expect(offenders).toEqual([])
    })

    it("n'enregistre Chart.js qu'une fois, dans BaseChart", () => {
        // Quarante-huit cartes recopiaient le même `ChartJS.register(...)`, en
        // huit listes divergentes. Une seule entrée, désormais : une carte qui
        // réimporte la bibliothèque a recopié un patron qui n'existe plus.
        expect(filesMatching(/from '(?:chart\.js|vue-chartjs)'/)).toEqual([
            'resources/js/Components/Stats/BaseChart.vue',
        ])
        expect(filesMatching(/ChartJS\.register\(/)).toEqual(['resources/js/Components/Stats/BaseChart.vue'])
    })

    it('ne tire un graphique que par defineAsyncComponent', () => {
        expect(filesMatching(/^import\s+[^\n]*\bfrom '@\/Components\/Stats\/\w*Chart\.vue'/m)).toEqual([])
    })
})
