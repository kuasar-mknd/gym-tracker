import { describe, it, expect, vi } from 'vitest'
import { nombre, poids, volume, variation, pourcentage, entier, compte, accord } from '@/Utils/nombre'

describe('les nombres affichés', () => {
    /**
     * `toFixed()` rend toujours un point décimal, quelle que soit la langue de
     * la page : c'est ce qui écrivait « 78.40 kg » sur Mesures pendant que
     * l'accueil écrivait « 78,4 kg » (#1787).
     */
    it('écrit la décimale à la virgule', () => {
        expect(nombre(78.4)).toBe('78,4')
        expect(poids(78.4)).toBe('78,4 kg')
        expect(poids('78.40')).toBe('78,4 kg')
    })

    /**
     * `toLocaleString()` sans langue suit celle du navigateur : en anglais,
     * 15750 s'écrit « 15,750 », qui se lit quinze virgule sept cent cinquante.
     */
    it('sépare les milliers sans virgule', () => {
        expect(volume(15750)).toBe("15'750 kg")
        expect(nombre(3060, 0)).toBe("3'060")
    })

    it('signe toujours une variation', () => {
        expect(variation(0.3)).toBe('+0,3 kg')
        expect(variation(-1.25)).toBe('-1,3 kg')
        expect(variation(0)).toBe('0 kg')
    })

    it('coupe les décimales inutiles', () => {
        expect(entier(12)).toBe('12')
        expect(entier(12.4)).toBe('12')
        expect(pourcentage(62.5)).toBe('62,5 %')
    })

    it('ne montre jamais NaN', () => {
        for (const vide of [null, undefined, '', 'abc', NaN, Infinity]) {
            expect(poids(vide)).toBe('—')
            expect(volume(vide)).toBe('—')
            expect(variation(vide)).toBe('—')
            expect(nombre(vide)).toBe('—')
        }
    })

    /*
     * Bâtir un `Intl.NumberFormat` coûte bien plus que de s'en servir : un
     * formateur neuf par nombre faisait l'essentiel des dizaines de
     * millisecondes d'un tableau du relevé des temps (#2148).
     */
    it('ne bâtit qu’un formateur par jeu de décimales, quel que soit le nombre de valeurs', () => {
        const formateurs = vi.spyOn(Intl, 'NumberFormat')

        try {
            const ecrits = Array.from({ length: 50 }, (_, rang) => nombre(rang + 0.125, 3, 2))

            expect(ecrits.slice(0, 2)).toEqual(['0,125', '1,125'])
            expect(nombre(2, 3, 2)).toBe('2,00')
            expect(formateurs.mock.calls.length).toBeLessThanOrEqual(1)
        } finally {
            formateurs.mockRestore()
        }
    })
})

describe('compte', () => {
    // « 1 séries », « 1 exos », « 4 exo » : un nombre et un nom figé (#1980).
    it('accorde le nom au singulier jusqu’à deux exclu', () => {
        expect(compte(0, 'série', 'séries')).toBe('0 série')
        expect(compte(1, 'série', 'séries')).toBe('1 série')
        expect(compte(1.5, 'série', 'séries')).toBe('2 séries')
        expect(compte(2, 'série', 'séries')).toBe('2 séries')
        expect(compte('3', 'exo', 'exos')).toBe('3 exos')
    })

    it('sépare les milliers comme le reste de l’application', () => {
        expect(compte(1250, 'exercice', 'exercices')).toBe(`${entier(1250)} exercices`)
    })

    it('rend un tiret pour ce qui n’est pas un nombre', () => {
        expect(compte(null, 'série', 'séries')).toBe('—')
        expect(compte(undefined, 'série', 'séries')).toBe('—')
    })
})

describe('accord', () => {
    // Le nom seul, pour un nombre écrit à part : « 1 séances » sur la carte
    // d'un objectif de fréquence (#1980).
    it('rend le nom accordé selon la même règle que compte()', () => {
        expect(accord(0, 'séance', 'séances')).toBe('séance')
        expect(accord('1', 'séance', 'séances')).toBe('séance')
        expect(accord(1.5, 'séance', 'séances')).toBe('séances')
        expect(accord(2, 'séance', 'séances')).toBe('séances')
    })

    it('garde le pluriel pour ce qui n’est pas un nombre', () => {
        expect(accord(null, 'séance', 'séances')).toBe('séances')
    })
})
