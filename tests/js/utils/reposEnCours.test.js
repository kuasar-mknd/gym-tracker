import { afterEach, describe, expect, it } from 'vitest'

import {
    deposerLeRepos,
    effacerLeRepos,
    reposDuCompte,
    reprendreLeRepos,
    secondesRestantes,
} from '@/Utils/reposEnCours'

/*
 * Le repos que la page de séance laisse en partant (#2434) : une échéance, jamais
 * un compte à rebours en mémoire de page. Ce que le temps fait ailleurs se lit
 * sur l'horloge.
 */
const MAINTENANT = 1_800_000_000_000

const depot = (surcharge = {}) => ({
    duree: 90,
    echeance: MAINTENANT + 72_000,
    restantEnPause: null,
    adresse: '/workouts/5',
    intitule: 'Pousser',
    compte: 7,
    ...surcharge,
})

afterEach(effacerLeRepos)

describe('secondesRestantes', () => {
    it('lit l’écart à l’échéance, arrondi au-dessus, et jamais sous zéro', () => {
        expect(secondesRestantes(depot(), MAINTENANT)).toBe(72)
        expect(secondesRestantes(depot(), MAINTENANT + 500)).toBe(72)
        expect(secondesRestantes(depot(), MAINTENANT + 71_001)).toBe(1)
        expect(secondesRestantes(depot(), MAINTENANT + 72_000)).toBe(0)
        expect(secondesRestantes(depot(), MAINTENANT + 999_000)).toBe(0)
    })

    it('rend le reste figé d’un repos en pause, quelle que soit l’horloge', () => {
        const enPause = depot({ echeance: null, restantEnPause: 45 })

        expect(secondesRestantes(enPause, MAINTENANT)).toBe(45)
        expect(secondesRestantes(enPause, MAINTENANT + 600_000)).toBe(45)
    })
})

describe('reprendreLeRepos', () => {
    it('rend le repos de ce compte et de cette séance, une fois, et l’efface', () => {
        deposerLeRepos(depot({ echeance: Date.now() + 30_000 }))

        const repris = reprendreLeRepos({ compte: 7, adresse: '/workouts/5' })

        expect(repris).toMatchObject({ duree: 90, enPause: false })
        expect(repris.restant).toBeGreaterThan(28)
        expect(repris.restant).toBeLessThanOrEqual(30)
        expect(reposDuCompte(7)).toBeNull()
    })

    it('reprend un repos en pause avec son reste', () => {
        deposerLeRepos(depot({ echeance: null, restantEnPause: 41 }))

        expect(reprendreLeRepos({ compte: 7, adresse: '/workouts/5' })).toEqual({
            duree: 90,
            restant: 41,
            enPause: true,
        })
    })

    it('ne rend rien d’un repos fini, et l’efface', () => {
        deposerLeRepos(depot({ echeance: Date.now() - 1000 }))

        expect(reprendreLeRepos({ compte: 7, adresse: '/workouts/5' })).toBeNull()
        expect(reposDuCompte(7)).toBeNull()
    })

    it('laisse le repos à qui il est : une autre séance, un autre compte', () => {
        deposerLeRepos(depot({ echeance: Date.now() + 30_000 }))

        expect(reprendreLeRepos({ compte: 7, adresse: '/workouts/6' })).toBeNull()
        expect(reprendreLeRepos({ compte: 8, adresse: '/workouts/5' })).toBeNull()
        expect(reposDuCompte(8)).toBeNull()
        expect(reposDuCompte('7')).not.toBeNull()
    })
})
