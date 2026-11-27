import { describe, expect, it } from 'vitest'

/*
 * Une page `titre-masque` n'a plus de barre mobile : la cloche des notifications
 * y vit dans sa première carte (#2434). Une page qui masque son titre sans
 * poser `<Cloche>` retirerait l'accès aux notifications sur téléphone. Modèles
 * et Calendrier (`retour-de-bureau-seul`) ne gardent leur retour que sur bureau :
 * la cloche y vient de la rangée d'onglets des séances (`OngletsDesSeances`).
 */
const pages = import.meta.glob('/resources/js/Pages/**/*.vue', { query: '?raw', import: 'default', eager: true })

const sansBarre = Object.entries(pages).filter(
    ([, source]) =>
        /<AuthenticatedLayout[^>]*\btitre-masque\b/.test(source) &&
        (!/<AuthenticatedLayout[^>]*\bshow-back\b/.test(source) ||
            /<AuthenticatedLayout[^>]*\bretour-de-bureau-seul\b/.test(source)),
)

describe('les pages sans barre mobile', () => {
    it('en compte au moins six : Accueil, Séances, Progrès, Profil, Modèles, Calendrier', () => {
        expect(sansBarre.length).toBeGreaterThanOrEqual(6)
    })

    it.each(sansBarre.map(([chemin]) => [chemin]))('%s pose la cloche dans la page', (chemin) => {
        const source = pages[chemin]
        const compose =
            /<Cloche\b/.test(source) || /<DashboardHeader\b/.test(source) || /<OngletsDesSeances\b/.test(source)

        expect(compose).toBe(true)
    })
})
