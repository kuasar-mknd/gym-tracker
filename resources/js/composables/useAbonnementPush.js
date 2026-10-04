import { onMounted, ref } from 'vue'
import { http } from '@/Utils/http'

/**
 * Ni serviceWorker.ready ni pushManager.subscribe() ne promettent de se
 * régler ; passé ce délai on abandonne l'étape et on le dit.
 */
const DELAI_ETAPE_MS = 20_000

const avecDelai = (promesse) => {
    let minuteur

    const expiration = new Promise((_, reject) => {
        minuteur = setTimeout(() => reject(new Error('le navigateur n’a pas répondu en 20 s')), DELAI_ETAPE_MS)
    })

    return Promise.race([promesse, expiration]).finally(() => clearTimeout(minuteur))
}

/** Faux dans Safari sur iPhone hors de l'application installée : `Notification` n'y existe pas. */
export const pushPrisEnCharge = () => 'Notification' in window && 'serviceWorker' in navigator

/**
 * Le dernier abonnement que CET appareil a transmis, et pour quel compte.
 *
 * Il dit au rapprochement s'il y a quelque chose à écrire : chaque écriture
 * coûte de 350 ms à 1,7 s sur le disque de production, et l'application
 * s'ouvre souvent.
 *
 * Il nomme le compte parce que le serveur réattribue une adresse au dernier
 * compte qui l'enregistre, en retirant la ligne de l'autre. Sur un téléphone
 * partagé, transmettre pour celui qui vient de se connecter enverrait SES
 * notifications, records et rappels compris, sur l'écran verrouillé du
 * propriétaire, tant qu'il ne se déconnecte pas par `detacherLAppareil` ; et le
 * propriétaire cesserait de recevoir sans le savoir. Un seul mémo par appareil
 * dit donc à qui l'appareil a été donné : l'abonnement reste à ce compte, et
 * seule l'activation, un geste fait sur l'appareil, le fait changer de mains.
 *
 * Le stockage peut être bloqué (navigation privée) ou illisible : on transmet
 * alors à chaque ouverture, une écriture de trop plutôt qu'un abonnement perdu.
 *
 * `aRetransmettre` dit que le serveur a pu retirer l'abonnement depuis : un
 * mot de passe changé retire tous ceux du compte (`User::booted()`). Le mémo
 * dit encore à quel compte l'appareil a été donné, mais ne dispense plus
 * d'écrire (`marquerLAbonnementARetransmettre`).
 */
const CLEF_DU_MEMO = 'gym-tracker:abonnement-push-transmis'

/** @returns {{utilisateur: string, endpoint: string, aRetransmettre?: boolean}|null} */
const lireLeMemo = () => {
    try {
        const memo = JSON.parse(window.localStorage.getItem(CLEF_DU_MEMO) ?? 'null')

        return typeof memo?.utilisateur === 'string' && typeof memo.endpoint === 'string' ? memo : null
    } catch {
        return null
    }
}

/** @param {{utilisateur: string, endpoint: string, aRetransmettre?: boolean}|null} memo */
const ecrireLeMemo = (memo) => {
    try {
        if (memo === null) {
            window.localStorage.removeItem(CLEF_DU_MEMO)
        } else {
            window.localStorage.setItem(CLEF_DU_MEMO, JSON.stringify(memo))
        }
    } catch {
        // Stockage bloqué : l'ouverture suivante transmettra de nouveau, sans plus.
    }
}

/**
 * Ce que le chargement en cours a déjà fait, pour qu'un même chargement
 * n'écrive pas deux fois : le layout se remonte à chaque page, et sur le profil
 * le formulaire rapproche aussi, avant même le layout qui l'entoure.
 */
const rapprochementsDuChargement = new Map()
const transmisPendantLeChargement = new Set()

/** Note qu'un abonnement a atteint le serveur pour ce compte. */
const noterLaTransmission = (utilisateur, endpoint) => {
    transmisPendantLeChargement.add(`${utilisateur} ${endpoint}`)
    ecrireLeMemo({ utilisateur, endpoint })
}

/**
 * Cet appareil a-t-il été donné à un AUTRE compte que celui-ci ?
 *
 * Seule l'activation du profil le fait changer de mains : l'invitation de
 * l'accueil se tait donc sur un tel appareil plutôt que d'y inviter un autre
 * compte d'un seul appui (#1848).
 *
 * @param {number|string|null|undefined} utilisateurId
 */
export const appareilDonneAUnAutreCompte = (utilisateurId) => {
    const memo = lireLeMemo()

    return memo !== null && memo.utilisateur !== String(utilisateurId)
}

/**
 * @param {string} endpoint
 * @param {{signal?: AbortSignal}} options `signal` abandonne la requête.
 */
const oublierSurLeServeur = (endpoint, { signal } = {}) =>
    http.post(route('push-subscriptions.destroy'), { endpoint }, { timeout: DELAI_ETAPE_MS, signal })

/**
 * @param {string} utilisateur
 * @param {boolean} serveurSansAbonnement
 * @returns {Promise<boolean>} Vrai quand le serveur tient l'abonnement de ce
 *   navigateur pour ce compte.
 */
const rapprocher = async (utilisateur, serveurSansAbonnement) => {
    const registration = await avecDelai(navigator.serviceWorker.ready)
    const abonnement = await avecDelai(registration.pushManager.getSubscription())
    const memo = lireLeMemo()

    // Transmis depuis cet appareil pour un autre compte : il reste à ce compte.
    if (memo !== null && memo.utilisateur !== utilisateur) {
        return false
    }

    const dejaTransmis = memo?.endpoint ?? null

    if (!abonnement) {
        // Révoqué par WebKit, permission retirée : le serveur écrirait à chaque
        // notification vers une adresse que plus personne ne lit.
        if (dejaTransmis !== null) {
            await oublierSurLeServeur(dejaTransmis)
            ecrireLeMemo(null)
        }

        return false
    }

    /*
     * Quand le serveur dit n'avoir AUCUN abonnement pour ce compte, le mémo est
     * démenti (base restaurée, ligne supprimée) : seule une transmission faite
     * pendant ce chargement-ci dispense alors d'écrire. Un mémo marqué « à
     * retransmettre » ne dispense de rien : le serveur a pu retirer la ligne.
     */
    const aJour =
        memo?.aRetransmettre !== true &&
        (serveurSansAbonnement
            ? transmisPendantLeChargement.has(`${utilisateur} ${abonnement.endpoint}`)
            : dejaTransmis === abonnement.endpoint)

    if (aJour) {
        return true
    }

    await http.post(route('push-subscriptions.update'), abonnement, { timeout: DELAI_ETAPE_MS })
    noterLaTransmission(utilisateur, abonnement.endpoint)

    // L'adresse que cet appareil avait transmise est morte, puisque le
    // navigateur en tient une autre. Son oubli n'est qu'un ménage.
    if (dejaTransmis !== null && dejaTransmis !== abonnement.endpoint) {
        await oublierSurLeServeur(dejaTransmis).catch(() => {})
    }

    return true
}

/**
 * Rapproche l'abonnement que tient le navigateur de celui que le serveur
 * connaît, à l'ouverture de l'application, sur n'importe quelle page (#1847).
 *
 * Le worker répare de lui-même un abonnement remplacé, quand le navigateur le
 * lui dit. iOS ne le dit pas, selon MDN ; une session expirée ou un proxy peut
 * aussi faire échouer son envoi. Le profil était alors le seul endroit où
 * l'abonnement rejoignait le serveur, et seulement quand le COMPTE n'en avait
 * aucun : un abonnement remplacé sur un appareil dont le compte gardait une
 * autre ligne n'était jamais transmis.
 *
 * Quatre cas : un abonnement que cet appareil n'a pas encore transmis pour ce
 * compte est enregistré, et l'adresse transmise avant lui oubliée ; un
 * abonnement disparu fait oublier l'adresse transmise ; un abonnement inchangé
 * ne coûte aucune écriture ; et un abonnement que cet appareil a transmis pour
 * un AUTRE compte reste à cet autre compte, sans aucune écriture (voir le mémo).
 * Une fois par chargement et par compte ; ne rejette jamais.
 *
 * @param {number|string|null|undefined} utilisateurId
 * @param {{serveurSansAbonnement?: boolean}} options `true` quand le serveur
 *   vient de dire que le compte n'a aucun abonnement : le mémo ne dispense plus
 *   alors d'écrire, mais dit toujours à quel compte l'appareil a été donné.
 * @returns {Promise<boolean>} Vrai quand le serveur tient, à notre connaissance,
 *   l'abonnement de ce navigateur pour ce compte. Faux sinon : pas d'abonnement,
 *   transmission refusée ou injoignable, appareil d'un autre compte, ou aucun
 *   compte à rapprocher.
 */
export const rapprocherLAbonnementPush = (utilisateurId, { serveurSansAbonnement = false } = {}) => {
    if (utilisateurId === null || utilisateurId === undefined || !pushPrisEnCharge()) {
        return Promise.resolve(false)
    }

    const utilisateur = String(utilisateurId)
    const precedent = rapprochementsDuChargement.get(utilisateur)

    if (precedent && !serveurSansAbonnement) {
        return precedent
    }

    // À la suite du précédent, pour qu'il ait déjà noté ce qu'il a transmis.
    const suivant = (precedent ?? Promise.resolve())
        .then(() => rapprocher(utilisateur, serveurSansAbonnement))
        .catch(() => false)

    rapprochementsDuChargement.set(utilisateur, suivant)

    return suivant
}

/**
 * Le serveur a pu retirer l'abonnement de cet appareil : le rapprochement
 * suivant le retransmet, s'il appartient au compte connecté.
 *
 * Un mot de passe changé retire tous les abonnements du compte, quel que soit
 * le chemin du changement (`User::booted()`), et ferme ses autres sessions. Le
 * mémo de l'appareil disait pourtant toujours « déjà transmis » : l'appareil
 * qui se reconnectait au compte ne transmettait plus rien, et le profil
 * montrait les cases « Envoyer aussi en Push » pour des envois qui ne lui
 * parvenaient plus. La marque garde le compte à qui l'appareil a été donné :
 * un appareil donné à un autre compte reste à cet autre compte.
 *
 * Ce que ce chargement a déjà rapproché est oublié aussi : la page de
 * connexion et la page qui la suit se succèdent sans recharger le module.
 */
export const marquerLAbonnementARetransmettre = () => {
    const memo = lireLeMemo()

    if (memo !== null && memo.aRetransmettre !== true) {
        ecrireLeMemo({ ...memo, aRetransmettre: true })
    }

    rapprochementsDuChargement.clear()
    transmisPendantLeChargement.clear()
}

/**
 * Rend au serveur l'abonnement de cet appareil, qui vient de changer le mot de
 * passe depuis le profil.
 *
 * Le serveur a retiré tous les abonnements du compte, celui de cet appareil
 * compris, puisqu'il ne sait pas lequel est le sien. L'appareil qui a changé
 * le mot de passe garde sa session : il retransmet le sien. Si la
 * transmission échoue, la marque reste, et l'ouverture suivante s'en charge.
 *
 * Les rapprochements en vol sont attendus d'abord : l'un d'eux, parti avant le
 * changement, noterait sinon après la marque une transmission que le serveur
 * vient d'effacer, et le mémo dispenserait de nouveau d'écrire.
 *
 * @param {number|string|null|undefined} utilisateurId
 * @returns {Promise<boolean>} Comme `rapprocherLAbonnementPush`. Ne rejette
 *   jamais.
 */
export const retransmettreLAbonnementPush = async (utilisateurId) => {
    if (!pushPrisEnCharge()) {
        return false
    }

    await Promise.allSettled(rapprochementsDuChargement.values())
    marquerLAbonnementARetransmettre()

    return rapprocherLAbonnementPush(utilisateurId)
}

/**
 * Ce que la déconnexion accorde au détachement de l'appareil, au plus.
 *
 * Lire l'abonnement ne coûte que quelques millisecondes ; l'oubli côté serveur
 * est une écriture, de 350 ms à 1,7 s sur le disque de production. Passé ce
 * délai, la déconnexion part : l'oubli en cours est abandonné, et le
 * désabonnement, qui suffit seul à faire taire l'appareil, se fait après coup.
 */
export const DELAI_DE_DETACHEMENT_MS = 600

/**
 * @param {AbortSignal} signal Levé quand la déconnexion part sans attendre.
 * @param {boolean} prevenirLeServeur
 */
const detacher = async (signal, prevenirLeServeur) => {
    // Un rapprochement encore en vol écrirait son mémo APRÈS l'effacement.
    await Promise.allSettled(rapprochementsDuChargement.values())

    let abonnement = null

    try {
        const registration = await navigator.serviceWorker.getRegistration()
        abonnement = (await registration?.pushManager.getSubscription()) ?? null
    } catch {
        // Worker introuvable : il n'y a rien à détacher que l'on puisse voir.
    }

    if (abonnement !== null) {
        /*
         * La session est close ou sur le point de l'être quand le délai est
         * passé : une écriture partie maintenant croiserait la déconnexion, et
         * sa réponse pourrait rendre au navigateur le cookie de la session
         * close.
         */
        if (prevenirLeServeur && !signal.aborted) {
            try {
                await oublierSurLeServeur(abonnement.endpoint, { signal })
            } catch {
                // Le désabonnement qui suit suffit : le service push répondra
                // 410 au prochain envoi, et le canal retirera la ligne.
            }
        }

        try {
            await abonnement.unsubscribe()
        } catch {
            // Le serveur a oublié l'adresse : il n'y enverra plus rien.
        }

        ecrireLeMemo(null)
    }

    // Ce que ce chargement a transmis ne vaut plus pour le compte qui part.
    rapprochementsDuChargement.clear()
    transmisPendantLeChargement.clear()
}

/**
 * Détache cet appareil du compte qui se déconnecte (#1926).
 *
 * Le serveur ne retire aucun abonnement à la déconnexion : il ne sait pas quel
 * appareil se déconnecte. Sans ce détachement, l'appareil continuait de
 * recevoir les records, les rappels et les succès du compte parti, sur l'écran
 * verrouillé ; sur un appareil partagé, la personne suivante les voyait.
 *
 * Trois étapes, dans cet ordre : le serveur oublie l'adresse de CET appareil
 * (il n'oublie que les lignes du compte connecté), le navigateur se désabonne,
 * et le mémo est effacé. Le compte suivant devra donc activer lui-même les
 * notifications, depuis son profil. Le désabonnement a lieu même quand le
 * mémo nomme un autre compte : après une déconnexion, l'appareil ne sert plus
 * personne.
 *
 * Rien de tout cela ne doit retenir la déconnexion : chaque étape avale son
 * échec, la promesse se règle au plus tard après `DELAI_DE_DETACHEMENT_MS`, et
 * un navigateur sans push ou sans abonnement n'appelle rien.
 *
 * @param {{prevenirLeServeur?: boolean}} options `false` quand la session
 *   n'existe déjà plus, après la suppression du compte : l'oubli répondrait 401.
 * @returns {Promise<void>} Ne rejette jamais.
 */
export const detacherLAppareil = ({ prevenirLeServeur = true } = {}) => {
    if (!pushPrisEnCharge()) {
        return Promise.resolve()
    }

    const arret = new AbortController()
    let minuteur

    const echeance = new Promise((resolve) => {
        minuteur = setTimeout(resolve, DELAI_DE_DETACHEMENT_MS)
    })

    const detachement = detacher(arret.signal, prevenirLeServeur).catch(() => {})

    return Promise.race([detachement, echeance]).finally(() => {
        clearTimeout(minuteur)
        // Sans effet sur un détachement fini ; sinon, abandonne l'oubli en vol.
        arret.abort()
    })
}

/**
 * L'abonnement aux notifications push : ce que le serveur en connait, ce que
 * le navigateur en tient, et l'activation etape par etape avec ses echecs
 * nommes. Le formulaire garde ses preferences ; il ne recoit qu'un rappel une
 * fois l'abonnement enregistre.
 *
 * @param {{
 *   vapidPublicKey: string|null|undefined,
 *   dejaAbonne: boolean,
 *   apresAbonnement: () => void,
 *   utilisateurId?: number|string|null,
 * }} page
 */
export const useAbonnementPush = ({ vapidPublicKey, dejaAbonne, apresAbonnement, utilisateurId = null }) => {
    const pushSupported = pushPrisEnCharge()
    const isSubscribing = ref(false)
    const pushError = ref(null)

    /**
     * Étape en cours, affichée sur le bouton et nommée dans le message d'échec :
     * sans elle, une activation qui échoue ne dit pas où, et une étape qui ne
     * répond jamais laisse le bouton tourner sans fin.
     */
    const etapeEnCours = ref(null)

    const messageDEchec = (err) => {
        const etape = etapeEnCours.value ?? 'activation'
        const statut = err?.response?.status

        if (statut) {
            const detail = err.response.data?.message ?? `HTTP ${statut}`

            return `Étape « ${etape} » refusée par le serveur : ${detail}`
        }

        const detail = err?.message ? ` (${err.message})` : ''
        const conseil =
            etape === 'Abonnement'
                ? ' Sur iPhone, ouvre l’app depuis l’écran d’accueil et vérifie que le réseau laisse passer les notifications.'
                : ''

        return `L’activation a échoué à l’étape « ${etape} »${detail}.${conseil} Réessaie.`
    }

    /**
     * Cet appareil est-il abonné ? La valeur du serveur n'en est que la
     * première approximation, corrigée dès le montage.
     *
     * `dejaAbonne` vient de `pushSubscriptions()->exists()`, qui répond pour le
     * COMPTE et non pour l'appareil : un abonnement pris sur un autre navigateur
     * suffit à le rendre vrai partout. Sur le téléphone, le bandeau « Activer »
     * disparaissait alors sans avoir jamais rien demandé, et avec lui le seul
     * bouton de toute l'application qui réclame l'autorisation — un cul-de-sac
     * dont l'utilisateur ne pouvait pas sortir.
     *
     * L'interface a aussi tenu cet état depuis `Notification.permission`, que le
     * navigateur accorde AVANT que l'abonnement n'atteigne le serveur : quand
     * cette requête échouait, le bandeau partait pareillement et les cases
     * « Envoyer aussi en Push » prenaient sa place, pour des envois que personne
     * ne recevait.
     */
    const pushRegistered = ref(dejaAbonne)

    /**
     * Le navigateur a-t-il répondu sur ce que tient CET appareil ? Jusque-là,
     * `pushRegistered` n'est que la valeur du serveur. L'invitation de l'accueil
     * l'attend pour ne pas apparaître puis disparaître aussitôt.
     */
    const appareilVerifie = ref(false)

    /*
     * Le navigateur tranche, parce qu'il est le seul à savoir ce que CET
     * appareil détient. Quatre cas, et aucun ne laisse l'utilisateur sans
     * issue : pas d'abonnement ici, le bandeau s'affiche quoi qu'en dise le
     * serveur ; un abonnement que le serveur ignore, on le lui rend, et s'il le
     * refuse ou ne répond pas, le bandeau revient, sinon les cases « Envoyer
     * aussi en Push » s'afficheraient pour un appareil que le serveur ne
     * connaît pas ; un abonnement que cet appareil a donné à un autre compte, le
     * bandeau s'affiche aussi, et c'est par lui seul que l'appareil change de
     * compte ; et si le worker ne répond pas, on montre le bandeau plutôt que
     * de le cacher. Un bandeau de trop se referme d'un clic, un bandeau
     * manquant ne se rattrape par rien.
     *
     * Rendre l'abonnement au serveur passe par le rapprochement que fait aussi
     * le layout, pour qu'une même ouverture n'écrive qu'une fois. Sans compte
     * connu, il n'y a rien à rapprocher et le navigateur décide seul.
     */
    const verifierLAppareil = async () => {
        if (!pushSupported) {
            return
        }

        let abonnementIci = false

        try {
            const registration = await avecDelai(navigator.serviceWorker.ready)
            const abonnement = await avecDelai(registration.pushManager.getSubscription())

            abonnementIci = abonnement !== null && abonnement !== undefined
        } catch {
            // Le worker ne répond pas : on montre le bandeau.
        }

        if (!abonnementIci || utilisateurId === null || utilisateurId === undefined) {
            pushRegistered.value = abonnementIci

            return
        }

        pushRegistered.value = await rapprocherLAbonnementPush(utilisateurId, { serveurSansAbonnement: !dejaAbonne })
    }

    onMounted(() =>
        verifierLAppareil().finally(() => {
            appareilVerifie.value = true
        }),
    )

    const urlBase64ToUint8Array = (base64String) => {
        if (!base64String) return new Uint8Array(0)
        const padding = '='.repeat((4 - (base64String.length % 4)) % 4)
        const base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/')
        const rawData = window.atob(base64)
        const outputArray = new Uint8Array(rawData.length)
        for (let i = 0; i < rawData.length; ++i) {
            outputArray[i] = rawData.charCodeAt(i)
        }
        return outputArray
    }

    /**
     * Tells the server to forget an endpoint the browser has just dropped.
     *
     * Deliberately swallows its own failure: the caller is either subscribing or
     * recovering from a failed subscription, and neither should be derailed by a
     * cleanup request. A missed one leaves the orphan this exists to prevent, which
     * is no worse than the state before.
     */
    const forgetOnServer = async (endpoint) => {
        await oublierSurLeServeur(endpoint)
            .then(() => {
                // Le rapprochement n'a plus à faire oublier cette adresse. Le
                // serveur n'oublie que les lignes de ce compte : le mémo d'un
                // autre reste.
                const memo = lireLeMemo()

                if (memo?.utilisateur === String(utilisateurId) && memo.endpoint === endpoint) {
                    ecrireLeMemo(null)
                }
            })
            .catch(() => {})
    }

    /**
     * Demande la permission, abonne cet appareil et le transmet au serveur.
     *
     * La permission est demandée AVANT toute attente : iOS n'ouvre l'invite que
     * dans le geste de l'utilisateur, et un `await` placé devant la ferait
     * partir hors du geste. L'appelant ne doit donc rien attendre non plus
     * avant d'appeler.
     *
     * @returns {Promise<'abonne'|'refuse'|'echoue'>} `refuse` quand l'invite n'a
     *   pas accordé la permission, qu'elle ait été refusée ou écartée ;
     *   `echoue` pour une panne, nommée dans `pushError`. L'invitation de
     *   l'accueil ne se représente pas après un refus, et reste après une panne.
     */
    const enablePush = async () => {
        isSubscribing.value = true
        pushError.value = null

        let subscription = null

        try {
            // Pas de délai ici : l'invite du système attend l'utilisateur.
            etapeEnCours.value = 'Permission'
            const permission = await Notification.requestPermission()

            if (permission !== 'granted') {
                // requestPermission() resolves straight to 'denied' once the user has
                // blocked it, so without this the click is another dead end.
                pushError.value =
                    'Ton navigateur a refusé les notifications. Autorise-les dans ses réglages, puis réessaie.'

                return 'refuse'
            }

            etapeEnCours.value = 'Service worker'
            const registration = await avecDelai(navigator.serviceWorker.ready)

            /*
             * Dropped on the server as well as in the browser.
             *
             * Unsubscribing here only tells the browser to stop honouring the
             * endpoint; the row stayed in `push_subscriptions` for good. Every
             * re-activation left another orphan behind, and each one is an endpoint
             * the app keeps pushing to — the provider answers 410 Gone every time,
             * for a subscription nobody can receive.
             */
            etapeEnCours.value = 'Abonnement'
            const existingSub = await avecDelai(registration.pushManager.getSubscription())
            if (existingSub) {
                const staleEndpoint = existingSub.endpoint

                await avecDelai(existingSub.unsubscribe())
                await forgetOnServer(staleEndpoint)
            }

            subscription = await avecDelai(
                registration.pushManager.subscribe({
                    userVisibleOnly: true,
                    applicationServerKey: urlBase64ToUint8Array(vapidPublicKey),
                }),
            )

            // Le jeton CSRF et la session viennent de Utils/http.
            etapeEnCours.value = 'Enregistrement'
            await http.post(route('push-subscriptions.update'), subscription, { timeout: DELAI_ETAPE_MS })

            // Sans cela, l'ouverture suivante transmettrait de nouveau.
            if (utilisateurId !== null && utilisateurId !== undefined) {
                noterLaTransmission(String(utilisateurId), subscription.endpoint)
            }

            pushRegistered.value = true
            apresAbonnement()

            return 'abonne'
        } catch (err) {
            // A browser subscription the server does not hold can never deliver
            // anything, and every later attempt discards it anyway (see the
            // unsubscribe above). Dropping it is the only state that stays true.
            if (subscription) {
                const abandoned = subscription.endpoint

                await subscription.unsubscribe().catch(() => {})
                await forgetOnServer(abandoned)
            }

            pushRegistered.value = false
            pushError.value = messageDEchec(err)

            return 'echoue'
        } finally {
            isSubscribing.value = false
            etapeEnCours.value = null
        }
    }

    return { pushSupported, isSubscribing, pushError, etapeEnCours, pushRegistered, appareilVerifie, enablePush }
}
