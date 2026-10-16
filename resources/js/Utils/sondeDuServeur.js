import { watch } from 'vue'
import { noterQueLeServeurRepond, useEtatDuReseau } from '@/composables/useEtatDuReseau'

/**
 * Le serveur répond-il de nouveau ? La page le demande (#2048).
 *
 * `joignable` (`useEtatDuReseau`) ne redevenait vrai qu'à la prochaine réponse
 * réussie d'une requête de l'application. Le réseau revenu sans un geste,
 * « Recharger », « Modèle » et les réglages de la séance restaient donc
 * désactivés, sans rien que la personne puisse faire pour les rendre.
 *
 * Tant que `joignable` est faux, ce module demande au serveur la sonde
 * (`/sonde`, `SondeController`) : une réponse vide, hors session, que rien ne
 * garde. Une réponse 2xx rend `joignable` vrai ; tout le reste le laisse faux,
 * une réponse d'erreur comme une absence de réponse. Jamais l'évènement
 * `online` ni `navigator.onLine` seuls : ils décrivent l'interface réseau de
 * l'appareil, pas le serveur. `online` ne fait que demander la sonde tout de
 * suite.
 *
 * Sans retour, la sonde se redemande de loin en loin, page visible seulement,
 * avec un recul qui double à chaque échec (cinq secondes, puis dix, vingt,
 * quarante, et une minute au plus), pour ne pas marteler un serveur qui
 * redémarre. Une page cachée ne demande rien ; revenue au premier plan, elle
 * reprend là où le recul en était, et demande aussitôt si son délai est passé.
 *
 * La requête part sur la même origine (adresse relative), sans cookie, sans
 * jeton ni adresse de la page (`Referer`), sans cache (`no-store`) et bornée
 * dans le temps. Le worker ne la prend pas
 * (`resources/js/sw/cacheDesActifs.js` ne sert que /build/assets/, et elle
 * n'est pas une navigation), et `connect-src 'self'` l'autorise.
 */

/** La route `sonde`, que `SondeDuServeurTest` compare au serveur. */
export const ADRESSE_DE_LA_SONDE = '/sonde'

/** Ce qu'une sonde attend sa réponse avant de compter comme un échec. */
export const DELAI_DE_LA_SONDE_MS = 5000

/** Ce qui sépare un échec de la sonde suivante, puis double à chaque échec. */
export const PREMIER_RECUL_MS = 5000

/** Le recul ne dépasse pas une minute. */
export const RECUL_MAXIMAL_MS = 60000

/**
 * Demande la sonde une fois. Vrai si le serveur a répondu 2xx à temps.
 *
 * @param {{
 *   allerChercher?: (adresse: string, options: RequestInit) => Promise<Response>,
 *   delai?: number,
 * }} options
 * @returns {Promise<boolean>}
 */
export const sonderLeServeur = async ({
    allerChercher = (adresse, options) => fetch(adresse, options),
    delai = DELAI_DE_LA_SONDE_MS,
} = {}) => {
    const arret = new AbortController()
    const echeance = setTimeout(() => arret.abort(), delai)

    try {
        const reponse = await allerChercher(ADRESSE_DE_LA_SONDE, {
            method: 'GET',
            cache: 'no-store',
            credentials: 'omit',
            // Ni l'adresse de la page, qui nomme une séance ou un exercice du compte.
            referrerPolicy: 'no-referrer',
            // Une redirection n'est pas la réponse du serveur : un portail captif, un proxy d'accès.
            redirect: 'error',
            signal: arret.signal,
        })

        return reponse.ok
    } catch {
        return false
    } finally {
        clearTimeout(echeance)
    }
}

/**
 * Branche la sonde sur l'état du réseau. Une fois par page, depuis `main.js`.
 *
 * @param {{
 *   fenetre?: Window,
 *   documentDeLaPage?: Document,
 *   allerChercher?: (adresse: string, options: RequestInit) => Promise<Response>,
 * }} options
 * @returns {() => void} ce qui la débranche
 */
export const installerLaSondeDuServeur = ({
    fenetre = window,
    documentDeLaPage = document,
    allerChercher = (adresse, options) => fetch(adresse, options),
} = {}) => {
    const { joignable } = useEtatDuReseau()

    let recul = PREMIER_RECUL_MS
    let derniereSonde = Date.now()
    let minuteur = null
    let enVol = false

    const pageVisible = () => documentDeLaPage.visibilityState !== 'hidden'

    const arreter = () => {
        clearTimeout(minuteur)
        minuteur = null
    }

    /** La prochaine sonde, quand le recul depuis la dernière est passé. */
    const planifier = () => {
        arreter()

        if (joignable.value || enVol || !pageVisible()) {
            return
        }

        minuteur = setTimeout(sonder, Math.max(0, derniereSonde + recul - Date.now()))
    }

    const sonder = async () => {
        arreter()

        if (joignable.value || enVol) {
            return
        }

        enVol = true
        const repond = await sonderLeServeur({ allerChercher })
        enVol = false
        derniereSonde = Date.now()

        if (repond) {
            noterQueLeServeurRepond()

            return
        }

        recul = Math.min(recul * 2, RECUL_MAXIMAL_MS)
        planifier()
    }

    /**
     * Le serveur répond : plus de sonde. Il vient de ne pas répondre : la
     * première sonde attend le premier recul, puisqu'un échec vient d'arriver.
     */
    const arreterOuReprendre = (estJoignable) => {
        recul = PREMIER_RECUL_MS

        if (estJoignable) {
            arreter()

            return
        }

        derniereSonde = Date.now()
        planifier()
    }

    /** L'interface réseau revient : la sonde part tout de suite, ou dès que la page se montre. */
    const auRetourDuReseau = () => {
        if (joignable.value) {
            return
        }

        recul = PREMIER_RECUL_MS
        derniereSonde = Number.NEGATIVE_INFINITY
        planifier()
    }

    const arreterLaSurveillance = watch(joignable, arreterOuReprendre, { flush: 'sync' })
    fenetre.addEventListener('online', auRetourDuReseau)
    documentDeLaPage.addEventListener('visibilitychange', planifier)

    planifier()

    return () => {
        arreter()
        arreterLaSurveillance()
        fenetre.removeEventListener('online', auRetourDuReseau)
        documentDeLaPage.removeEventListener('visibilitychange', planifier)
    }
}
