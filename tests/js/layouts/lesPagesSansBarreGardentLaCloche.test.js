import { describe, expect, it } from 'vitest'

/*
 * Une page `titre-masque` n'a plus de barre mobile : la cloche des notifications
 * y vit dans sa première carte (#2434). Une page qui masque son titre sans
 * poser `<Cloche>` retirerait l'accès aux notifications sur téléphone.
 */
const pages = import.meta.glob('/resources/js/Pages/**/*.vue', { query: '?raw', import: 'default', eager: true })

const sansBarre = Object.entries(pages).filter(
    ([, source]) =>
        /<AuthenticatedLayout[^>]*\btitre-masque\b/.test(source) &&
        !/<AuthenticatedLayout[^>]*\bshow-back\b/.test(source),
)

describe('les pages sans barre mobile', () => {
    it('en compte au moins quatre : Accueil, Séances, Progrès, Profil', () => {
        expect(sansBarre.length).toBeGreaterThanOrEqual(4)
    })

    it.each(sansBarre.map(([chemin]) => [chemin]))('%s pose la cloche dans la page', (chemin) => {
        const source = pages[chemin]
        const compose = /<Cloche\b/.test(source) || /<DashboardHeader\b/.test(source)

        expect(compose).toBe(true)
    })
})
