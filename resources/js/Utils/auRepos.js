/**
 * Fait passer une tâche quand la page est au repos (#2048).
 *
 * Les copies hors ligne s'écrivent ainsi après la page qu'elles gardent : la
 * copie, le chiffrement et IndexedDB ne retardent ni l'affichage ni la saisie.
 * Par `requestIdleCallback` quand le navigateur l'a, avec une échéance : une
 * page jamais au repos (une animation, un minuteur) finit par écrire.
 *
 * Safari et Safari iOS ne l'ont pas (désactivé jusqu'à la version 27.2 selon
 * caniuse), jsdom non plus : un appel direct y lève une `ReferenceError`, et
 * plus rien de ce qui suit ne tourne. Ce module est donc le seul à le nommer,
 * toujours sous la forme `globalThis.requestIdleCallback ?? …`, et retombe sur
 * un `setTimeout` sans délai. `tests/js/conventions/auRepos.test.js` refuse
 * tout autre appel.
 */

/** L'attente au plus avant que la tâche passe, même si la page n'est jamais au repos. */
export const ECHEANCE_AU_REPOS_MS = 2000

/**
 * @param {() => void} tache
 */
export const auRepos = (tache) => {
    const planifier = globalThis.requestIdleCallback ?? ((rappel) => setTimeout(rappel, 0))

    planifier.call(globalThis, () => tache(), { timeout: ECHEANCE_AU_REPOS_MS })
}
