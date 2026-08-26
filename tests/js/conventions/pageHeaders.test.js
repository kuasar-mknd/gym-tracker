import { describe, it, expect } from 'vitest'
import { readFileSync } from 'node:fs'

import { collectSourceFiles, jsRoot } from './sourceFiles'

/**
 * La barre collante de `AuthenticatedLayout` ne se rend que si la page lui donne
 * un titre ou un retour : `v-if="pageTitle || showBack"`.
 *
 * Or c'est cette barre qui porte la CLOCHE des notifications. Une page qui
 * n'annonce pas son titre n'a donc pas seulement l'air différente des autres :
 * sur téléphone, elle ne donne aucun accès aux notifications, et rien à l'écran
 * ne dit où l'on se trouve puisque l'en-tête de bureau est en `hidden sm:block`.
 *
 * Quatre pages étaient dans ce cas — dont l'accueil, la bibliothèque et les
 * statistiques. Sam l'a vu à l'œil, en comparant deux captures.
 */
const layout = /<AuthenticatedLayout\b(?<attributs>(?:[^>"']|"[^"]*"|'[^']*')*?)>/s

describe('les en-têtes de page', () => {
    it("fait annoncer son titre à chaque page, sinon la cloche n'existe pas", () => {
        const muettes = collectSourceFiles()
            .filter((fichier) => fichier.includes('/Pages/'))
            .flatMap((fichier) => {
                const trouve = layout.exec(readFileSync(fichier, 'utf8'))

                if (trouve === null) {
                    return []
                }

                const annonce = /(?::page-title|page-title|show-back|:show-back)[="\s]/.test(trouve.groups.attributs)

                return annonce ? [] : [fichier.replace(jsRoot, 'resources/js')]
            })

        expect(muettes).toEqual([])
    })
})
