import { describe, expect, it } from 'vitest'
import { readFileSync } from 'node:fs'
import { join } from 'node:path'

import { jsRoot } from './sourceFiles'
import { PAGES_GARDEES, PROPS_JAMAIS_GARDEES, propJamaisGardee } from '@/Utils/pagesGardees'

/**
 * La racine de Progrès est gardée hors ligne sans une seule mesure (#2434,
 * lot 4a) : le poids, sa variation, la masse grasse et le groupe différé qui
 * porte leurs historiques ne vont dans aucune copie (`PROPS_JAMAIS_GARDEES`).
 * Le lot 4b les retire de la page elle-même, qui les range sous Corps ; les
 * noms restent dans la liste, pour le jour où un serveur les renverrait.
 *
 * Ce test lit les props que la page déclare. Une nouvelle prop n'y entre pas
 * sans qu'on la classe : une mesure ou un objectif rejoint la liste des props
 * jamais gardées, une prop d'entraînement s'ajoute à celles que la copie
 * garde. Un nom de mesure ne se devine pas (`tourDeTaille`), d'où une liste
 * fermée plutôt qu'un motif.
 */

const PROPS_QUE_LA_COPIE_GARDE = ['exercises', 'selectedPeriod']

const propsDeclareesParLaPage = () => {
    const source = readFileSync(join(jsRoot, 'Pages', 'Stats', 'Index.vue'), 'utf8')
    const debut = source.indexOf('defineProps({')
    const fin = source.indexOf('\n})', debut)

    return [...source.slice(debut, fin).matchAll(/^\s{4}(\w+):/gm)].map(([, nom]) => nom)
}

describe('la racine de Progrès gardée hors ligne', () => {
    it('est une page gardée', () => {
        expect(PAGES_GARDEES).toContainEqual({ composant: 'Stats/Index', route: 'stats.index', chemin: '/stats' })
    })

    it('nomme jamais gardées la pesée, la variation, la masse grasse et le groupe différé des historiques', () => {
        expect(PROPS_JAMAIS_GARDEES['Stats/Index']).toEqual(['latestWeight', 'weightChange', 'bodyFat', 'deferredData'])
    })

    it('ne déclare plus la pesée, la variation ni la masse grasse, passées sur la page Corps (lot 4b)', () => {
        expect(propsDeclareesParLaPage().sort()).toEqual(['deferredData', 'exercises', 'selectedPeriod'])
    })

    it('ne monte aucune carte de poids ni de masse grasse', () => {
        const page = readFileSync(join(jsRoot, 'Pages', 'Stats', 'Index.vue'), 'utf8')

        expect(page).not.toMatch(/WeightEvolutionCard|BodyFatCard|weightHistory|bodyFatHistory/)
    })

    it('ne laisse entrer dans la copie que des props classées : aucune mesure, aucun objectif', () => {
        const gardees = propsDeclareesParLaPage().filter((nom) => !propJamaisGardee('Stats/Index', nom))

        expect(gardees.sort()).toEqual(PROPS_QUE_LA_COPIE_GARDE)
    })

    it('attend, dans chaque carte, la prop différée que le serveur annonce, la seule que la copie dit « indisponible hors ligne »', () => {
        const page = readFileSync(join(jsRoot, 'Pages', 'Stats', 'Index.vue'), 'utf8')
        const cartes = [...page.matchAll(/from '@\/Components\/Stats\/(\w+)\.vue'/g)].map(([, nom]) => nom)
        const attendues = cartes.flatMap((nom) =>
            [
                ...readFileSync(join(jsRoot, 'Components', 'Stats', `${nom}.vue`), 'utf8').matchAll(
                    /<Deferred\s+(:?data=(?:"[^"]*"))/g,
                ),
            ].map(([, attribut]) => attribut),
        )

        expect(attendues.length).toBeGreaterThan(0)
        expect(attendues.filter((attribut) => attribut !== 'data="deferredData"')).toEqual([])
    })
})
