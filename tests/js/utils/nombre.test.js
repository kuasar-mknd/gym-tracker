import { describe, it, expect } from 'vitest'
import { nombre, poids, volume, variation, pourcentage, entier, compte } from '@/Utils/nombre'

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
