import { describe, it, expect } from 'vitest'
import { readFileSync } from 'node:fs'

import { collectSourceFiles, filesMatching, jsRoot } from './sourceFiles'

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

/**
 * Un canevas n'est qu'une image. vue-chartjs le rend en `role="img"`, et sans
 * `aria-label` un lecteur d'écran l'annonce « image », ou le tait (#1970).
 * `BaseChart` le nomme par sa prop `description` : aucune carte ne l'oublie.
 */
describe('les graphiques se lisent sans être vus', () => {
    const sources = collectSourceFiles().map((chemin) => ({
        fichier: chemin.replace(jsRoot, 'resources/js'),
        source: readFileSync(chemin, 'utf8'),
    }))

    const balisesBaseChart = sources.flatMap(({ fichier, source }) =>
        [...source.matchAll(/<BaseChart\b((?:[^>"']|"[^"]*"|'[^']*')*)>/g)].map(([, attributs]) => ({
            fichier,
            attributs,
        })),
    )

    // Une balise que l'expression ne saurait pas découper échapperait à la garde
    // sans rien dire : on compte donc aussi les ouvertures brutes.
    it('lit chaque appel de BaseChart', () => {
        const ouvertures = sources.flatMap(({ source }) => [...source.matchAll(/<BaseChart\b/g)])

        expect(balisesBaseChart.length).toBe(ouvertures.length)
        expect(balisesBaseChart.length).toBeGreaterThan(0)
    })

    it('donne une description à chaque graphique', () => {
        const sansNom = balisesBaseChart
            .filter(
                ({ attributs }) =>
                    !/(?:^|\s)(?:description="[^"]*\S[^"]*"|:description="[^"]*\S[^"]*")/.test(attributs),
            )
            .map(({ fichier }) => fichier)

        expect(sansNom, 'passer description="…" à BaseChart : le nom que le lecteur d’écran annonce').toEqual([])
    })

    /**
     * Le nom d'une série est lu tel quel dans la description du graphique :
     * « Habits Completed » ou « Max Reps » s'y prononçaient en anglais.
     */
    it('nomme les séries en français', () => {
        const ANGLAIS =
            /\b(?:reps?|sets?|habits?|completed|measurements?|weights?|workouts?|duration|average|estimated)\b/i

        const enAnglais = collectSourceFiles()
            .filter((chemin) => chemin.includes('/Components/Stats/'))
            .flatMap((chemin) => {
                const source = readFileSync(chemin, 'utf8')
                const noms = [
                    ...[...source.matchAll(/^\s*label:\s*'([^']*)'/gm)].map(([, nom]) => nom),
                    ...[...source.matchAll(/^\s*label:\s*\{[^}]*default:\s*'([^']*)'/gm)].map(([, nom]) => nom),
                ]

                return noms
                    .filter((nom) => ANGLAIS.test(nom))
                    .map((nom) => `${chemin.replace(jsRoot, 'resources/js')} : ${nom}`)
            })

        expect(enAnglais).toEqual([])
    })
})
