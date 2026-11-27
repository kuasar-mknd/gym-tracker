import { describe, it, expect } from 'vitest'
import { elastique, projeter, vitesseDuGeste, cibleAuRelachement, pasDuRessort } from '@/Utils/glissement'

const CRANS = [-80, 0, 80]

describe('elastique', () => {
    it('suit le doigt de plus en plus mollement, sans jamais dépasser la dimension', () => {
        let precedent = 0
        let pente = 1

        for (let depassement = 1; depassement <= 2000; depassement += 1) {
            const valeur = elastique(depassement, 160)

            expect(valeur).toBeGreaterThan(precedent)
            expect(valeur - precedent).toBeLessThanOrEqual(1)
            expect(valeur - precedent).toBeLessThanOrEqual(pente + 1e-9)
            expect(valeur).toBeLessThan(160)

            pente = valeur - precedent
            precedent = valeur
        }
    })

    it('garde le signe du dépassement et vaut zéro à zéro', () => {
        expect(elastique(0, 160)).toBe(0)
        expect(elastique(-40, 160)).toBeCloseTo(-elastique(40, 160), 10)
    })
})

describe('projeter', () => {
    it('ajoute la distance que l’élan parcourrait encore : 1000 px/s, environ 499 px', () => {
        expect(projeter(1000)).toBeCloseTo(499, 5)
        expect(projeter(0)).toBe(0)
        expect(projeter(-1000)).toBeCloseTo(-499, 5)
    })
})

describe('vitesseDuGeste', () => {
    it('lit les 100 dernières millisecondes, en pixels par seconde', () => {
        const echantillons = [
            { t: 0, x: 0 },
            { t: 300, x: 100 },
            { t: 350, x: 110 },
            { t: 400, x: 120 },
        ]

        expect(vitesseDuGeste(echantillons)).toBeCloseTo(200, 5)
    })

    it('rend zéro sans mouvement récent, et quand le doigt s’est arrêté avant le relâchement', () => {
        expect(vitesseDuGeste([])).toBe(0)
        expect(vitesseDuGeste([{ t: 0, x: 0 }])).toBe(0)
        expect(
            vitesseDuGeste(
                [
                    { t: 0, x: 0 },
                    { t: 50, x: 40 },
                ],
                500,
            ),
        ).toBe(0)
    })
})

describe('cibleAuRelachement', () => {
    it('ouvre sur un coup sec : 25 px à +500 px/s depuis la rangée fermée', () => {
        expect(cibleAuRelachement({ x: 25, vitesse: 500, crans: CRANS, cranDeDepart: 0 })).toBe(80)
    })

    it('referme sur un retour vif : 45 px à -300 px/s depuis la rangée ouverte', () => {
        expect(cibleAuRelachement({ x: 45, vitesse: -300, crans: CRANS, cranDeDepart: 80 })).toBe(0)
    })

    it('prend le cran le plus proche quand le doigt est posé', () => {
        expect(cibleAuRelachement({ x: 50, vitesse: 0, crans: CRANS, cranDeDepart: 80 })).toBe(80)
        expect(cibleAuRelachement({ x: 30, vitesse: 0, crans: CRANS, cranDeDepart: 80 })).toBe(0)
        expect(cibleAuRelachement({ x: -60, vitesse: 0, crans: CRANS, cranDeDepart: 0 })).toBe(-80)
    })

    it('ne va jamais d’un côté à l’autre, si fort que ce soit', () => {
        expect(cibleAuRelachement({ x: 45, vitesse: -3000, crans: CRANS, cranDeDepart: 80 })).toBe(0)
        expect(cibleAuRelachement({ x: -45, vitesse: 3000, crans: CRANS, cranDeDepart: -80 })).toBe(0)
    })

    it('ne propose que les crans qui existent', () => {
        expect(cibleAuRelachement({ x: 60, vitesse: 800, crans: [-80, 0], cranDeDepart: 0 })).toBe(0)
    })
})

describe('pasDuRessort', () => {
    const avancer = (etat, cible, reglage, secondes = 2) => {
        const trace = [etat]

        for (let t = 0; t < secondes; t += 1 / 60) {
            etat = pasDuRessort(etat, cible, 1 / 60, reglage)
            trace.push(etat)
        }

        return trace
    }

    it('converge vers la cible', () => {
        const trace = avancer({ x: 0, v: 0 }, 80, { reponse: 0.34, amortissement: 0.86 })
        const fin = trace.at(-1)

        expect(fin.x).toBeCloseTo(80, 1)
        expect(Math.abs(fin.v)).toBeLessThan(1)
    })

    it('ne dépasse pas la cible à l’amortissement critique, vitesse nulle', () => {
        const trace = avancer({ x: 0, v: 0 }, 80, { reponse: 0.34, amortissement: 1 })

        expect(Math.max(...trace.map((e) => e.x))).toBeLessThanOrEqual(80 + 1e-6)
    })

    it('dépasse un peu en dessous de 1, ce qui est le ressort', () => {
        const trace = avancer({ x: 0, v: 0 }, 80, { reponse: 0.34, amortissement: 0.86 })

        expect(Math.max(...trace.map((e) => e.x))).toBeGreaterThan(80)
    })

    it('part avec la vitesse qu’on lui donne', () => {
        const [, premier] = avancer({ x: 30, v: 900 }, 80, { reponse: 0.34, amortissement: 0.86 }, 1 / 60)

        expect(premier.x).toBeGreaterThan(30 + 900 / 60 / 2)
        expect(premier.v).toBeGreaterThan(0)
    })

    it('ne bouge pas quand il y est déjà', () => {
        expect(pasDuRessort({ x: 80, v: 0 }, 80, 1 / 60, { reponse: 0.34, amortissement: 1 })).toEqual({ x: 80, v: 0 })
    })
})
