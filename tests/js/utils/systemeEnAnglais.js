import { vi } from 'vitest'

/**
 * Fait comme si l'appareil était réglé en anglais américain.
 *
 * La langue par défaut d'Intl se fixe au démarrage du processus, d'après
 * `LANG`, et ne se change plus ensuite : un test ne peut pas la basculer. On
 * remplace donc, le temps d'un test, les méthodes de `Date` qui la lisent. Un
 * appel sans langue (`undefined`, `[]`) reçoit `en-US`, comme sur un téléphone
 * réglé en anglais ; un appel qui nomme sa langue la garde (#1976).
 *
 * @returns {() => void} Rend les méthodes d'origine.
 */
export const simulerUnSystemeEnAnglais = () => {
    const espions = ['toLocaleDateString', 'toLocaleTimeString', 'toLocaleString'].map((methode) => {
        const originale = Date.prototype[methode]

        return vi.spyOn(Date.prototype, methode).mockImplementation(function (langue, options) {
            const sansLangue = langue === undefined || (Array.isArray(langue) && langue.length === 0)

            return originale.call(this, sansLangue ? 'en-US' : langue, options)
        })
    })

    return () => espions.forEach((espion) => espion.mockRestore())
}
