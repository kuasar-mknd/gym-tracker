import { getCurrentInstance, onUnmounted } from 'vue'

/**
 * Appelle une méthode du verrou qui rend une promesse (`request`, `release`),
 * sans jamais lever ni laisser de rejet non géré : un verrou refusé laisse
 * l'écran s'éteindre comme avant, un verrou qui ne se relâche pas sera relâché
 * par le navigateur quand la page se cachera.
 *
 * @template T
 * @param {() => Promise<T>|T} appel
 * @returns {Promise<T|null>}
 */
const sansLever = (appel) => {
    try {
        return Promise.resolve(appel()).catch(() => null)
    } catch {
        return Promise.resolve(null)
    }
}

/**
 * Le verrou d'écran d'un composant (#2113) : tant qu'il est gardé, l'écran ne
 * s'éteint pas de lui-même.
 *
 * Un minuteur qu'on ne touche pas laisse l'appareil verrouiller l'écran au bout
 * du délai qu'on y a réglé ; iOS suspend alors la page, minuteries et sortie
 * audio comprises, et plus rien ne sonne. `navigator.wakeLock.request('screen')`
 * le retient. Trois règles de la spécification (Screen Wake Lock) dictent la
 * forme :
 *
 * - la demande n'aboutit que sur une page visible, et peut être refusée
 *   (économie d'énergie, politique de la page) : un refus ne lève pas, et
 *   l'écran s'éteint comme avant ;
 * - le navigateur relâche le verrou dès que la page se cache (un autre onglet,
 *   l'application en arrière-plan, l'écran éteint à la main) : `garder()` le
 *   redemande au retour, tant qu'il est voulu ;
 * - la demande rend une promesse : un `relacher()` qui arrive avant elle
 *   relâche le verrou dès qu'il est rendu.
 *
 * `garder()` s'appelle de préférence dans le geste qui lance ce qui doit rester
 * à l'écran, et `relacher()` à son arrêt ; le verrou se relâche aussi au
 * démontage du composant qui l'a demandé. Sans l'API, rien.
 *
 * @returns {{ garder: () => void, relacher: () => void }}
 */
export const useVerrouDEcran = () => {
    /** Le verrou est-il voulu ? Il le reste quand la page se cache, et se redemande à son retour. */
    let voulu = false

    /** @type {WakeLockSentinel|null} */
    let verrou = null

    /** Une demande partie, pas encore rendue. */
    let enCours = false

    const relacherLeVerrou = (sentinelle) => {
        if (!sentinelle.released) {
            sansLever(() => sentinelle.release())
        }
    }

    const demander = () => {
        const api = globalThis.navigator?.wakeLock

        if (verrou !== null && verrou.released) {
            verrou = null
        }

        if (!voulu || verrou !== null || enCours || !api || document.visibilityState !== 'visible') {
            return
        }

        enCours = true
        sansLever(() => api.request('screen')).then((sentinelle) => {
            enCours = false

            if (!sentinelle) {
                return
            }

            if (!voulu) {
                relacherLeVerrou(sentinelle)

                return
            }

            verrou = sentinelle
            sentinelle.addEventListener?.('release', () => {
                if (verrou === sentinelle) {
                    verrou = null
                }
            })
        })
    }

    /** Au retour de la page, le verrou que le navigateur a relâché en la cachant se redemande. */
    const surLaVisibilite = () => {
        if (document.visibilityState === 'visible') {
            demander()
        }
    }

    const garder = () => {
        if (!globalThis.navigator?.wakeLock) {
            return
        }

        if (!voulu) {
            voulu = true
            document.addEventListener('visibilitychange', surLaVisibilite)
        }

        demander()
    }

    const relacher = () => {
        if (voulu) {
            voulu = false
            document.removeEventListener('visibilitychange', surLaVisibilite)
        }

        const sentinelle = verrou
        verrou = null

        if (sentinelle !== null) {
            relacherLeVerrou(sentinelle)
        }
    }

    if (getCurrentInstance()) {
        onUnmounted(relacher)
    }

    return { garder, relacher }
}
