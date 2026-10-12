import { flushPromises } from '@vue/test-utils'

/**
 * Les pages qu'un fichier de test monte, pour les démonter à la fin de chaque
 * test.
 *
 * Une page laissée montée survit à son test : ses minuteries tombent plus
 * tard, et ses écouteurs de `window` répondent aux évènements des tests
 * suivants. Les mocks d'un fichier étant partagés par tous ses tests, ce
 * qu'elle fait alors se compte dans un autre. `Workouts/Show` attend une
 * seconde avant d'envoyer une valeur tapée : le PATCH d'un test terminé
 * tombait ainsi dans « refuses to move a set past either end », qui n'en
 * attend aucun, selon la vitesse de la machine et l'ordre des tests (#2003).
 *
 * `demonterToutes` démonte les pages encore montées, puis attend ce que leur
 * démontage envoie (la page vide ses saisies en partant) : ces envois
 * aboutissent avant que le test suivant ne remette ses mocks à zéro. L'appeler
 * en tête de l'`afterEach` du fichier, avant tout autre nettoyage.
 *
 * @returns {{
 *   retenir: (page: import('@vue/test-utils').VueWrapper) => void,
 *   demonterToutes: () => Promise<void>,
 * }}
 */
export const suivreLesPagesMontees = () => {
    const montees = []

    return {
        retenir: (page) => {
            montees.push(page)
        },

        demonterToutes: async () => {
            montees
                .splice(0)
                .filter((page) => page.exists())
                .forEach((page) => page.unmount())

            await flushPromises()
        },
    }
}
