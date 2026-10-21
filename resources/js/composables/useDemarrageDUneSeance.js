import { readonly, ref, watch } from 'vue'
import { router, usePage } from '@inertiajs/vue3'
import SyncService, { nouvelleCleDIdempotence } from '@/Utils/SyncService'
import { magasinDesCopies } from '@/Utils/copiesHorsLigne'
import { estUneCopie } from '@/Utils/copiesServies'
import { COMPOSANT_DE_LA_SEANCE } from '@/Utils/pagesGardees'
import { SYNC_AUTH, classifySyncError } from '@/Utils/syncErrors'
import {
    RAISON_SEANCE_DEJA_EN_COURS,
    bibliothequeHorsLigne,
    estCloseParLaFile,
    estUneCreationDeSeance,
    pageDeLaSeanceEnAttente,
    seanceDeLEntree,
} from '@/Utils/seanceEnAttente'
import {
    DELAI_AVANT_UNE_COPIE_MS,
    DEMARRAGE_SANS_RESEAU,
    EVENEMENT_AVANT_UNE_COPIE,
    MODELE_SANS_RESEAU,
    annoncerSansReseau,
    direSansReseau,
} from '@/Utils/visitesHorsLigne'
import { useEtatDuReseau } from '@/composables/useEtatDuReseau'
import { triggerHaptic } from '@/composables/useHaptics'

/**
 * Démarrer une séance, avec ou sans réseau (#2049).
 *
 * Le bouton central de la barre du bas, l'accueil, la liste des séances et
 * leurs états vides démarraient par une visite d'Inertia (`workouts.store`),
 * qui ne disait sans réseau que « Il faut le réseau pour démarrer une
 * séance » (#2048). Ils passent tous par `demarrer()`, qui passe par la file
 * hors ligne : celle-ci se vide d'abord, et une écriture qui attendait
 * encore part avant la création.
 *
 * 1. Une séance démarrée sans réseau dont la création attend encore en file
 *    s'ouvre : un second appui ne crée rien.
 * 2. Sur une copie hors ligne, ou le serveur injoignable, la séance en cours
 *    que la page connaît s'ouvre (`auth.user.active_workout`), comme le
 *    serveur y renverrait.
 *
 *    Une séance terminée sur l'appareil, dont la fin attend la file, n'est
 *    plus en cours : ni l'une ni l'autre ne s'ouvre, et une nouvelle
 *    se crée, derrière cette fin.
 * 3. Sinon, la clé de la séance se tire sur l'appareil, l'heure du début au
 *    geste (`started_at`), et :
 *    - sur une copie ou le serveur injoignable, la création entre en file sans
 *      être tentée, et la séance s'ouvre aussitôt, construite depuis son entrée
 *      (`ouvrirLaSeanceEnAttente`) ;
 *    - sinon, elle part (`POST /api/v1/workouts`), cinq secondes au plus
 *      (`DELAI_AVANT_UNE_COPIE_MS`, la décision 8 du propriétaire pour
 *      l'application ouverte) : la séance créée s'ouvre par une visite, celle
 *      qui attendait en cours aussi (409 `seance-deja-en-cours`, comme le
 *      serveur y renvoie) ; sans réponse, la création est en file, et la
 *      séance en attente s'ouvre ; la session expirée (401, 419), la page se
 *      redemande au serveur, qui mène à la connexion et y revient ensuite.
 *
 * Démarrer reste permis sur une copie hors ligne : rien n'y est tiré de la
 * copie, ni la clé, ni l'heure, ni la séance (`.ai/rules/js.md`). Un verrou
 * de l'onglet, au niveau du module, tient pour tous les boutons ensemble :
 * deux appuis rapprochés ne démarrent qu'une séance.
 *
 * **Lancer un modèle** passe par la même fonction, `demarrer(modele)`, depuis
 * la page des modèles, qui porte de quoi le recopier sans réseau
 * (`lancements`, le contenu et les clés que le serveur dérive) :
 *
 * - une séance déjà en cours, que la page ou la file connaissent (1 et 2), ou
 *   que le serveur nomme (409), s'ouvre, et la page dit que le modèle n'a pas
 *   été démarré, comme le serveur le disait (`messageDuModeleNonDemarre`) ;
 * - sans réseau et sans le contenu du modèle (une copie qui ne le porte pas),
 *   rien ne part : `MODELE_SANS_RESEAU` ;
 * - sur une copie ou le serveur injoignable, la séance, ses lignes et ses
 *   séries entrent en file d'un seul coup, sous les clés du serveur, sans
 *   tentative (`mettreEnFileLaSeanceDUnModele`), et la séance s'ouvre ;
 * - en ligne, une seule requête, `POST /api/v1/workouts` avec `template_id`,
 *   que la file ne garde pas (`garderEnFile: false`) : la séance créée
 *   s'ouvre ; sans réponse, la séance du modèle entre en file comme sans
 *   réseau, notée tentée quand la requête est partie, et s'ouvre. Le serveur
 *   qui l'aurait exécutée retrouve chaque écriture rejouée sous sa clé.
 *
 * Lancer reste permis sur une copie de la page des modèles : ce qui part est
 * le modèle tel que la copie le montre, et c'est le geste de la personne ; le
 * serveur borne déjà ses valeurs, et ses clés convergent avec une exécution
 * qu'il aurait faite (`.ai/rules/js.md`).
 */

/** Ce que dit un démarrage que le serveur a refusé sans nommer de séance en cours. */
export const DEMARRAGE_REFUSE = "La séance n'a pas pu démarrer. Réessaie."

/**
 * Ce que dit un modèle qui ne démarre pas parce qu'une séance est déjà en
 * cours : le message que le serveur donnait à la page des modèles
 * (`WorkoutTemplateController::execute`), que la page dit désormais elle-même,
 * l'API ne nommant pas le modèle.
 *
 * @param {string} nom
 * @returns {string}
 */
export const messageDuModeleNonDemarre = (nom) =>
    `Le modèle « ${nom} » n'a pas été démarré : une séance est déjà en cours. Terminez-la avant d'en commencer une autre.`

/** Combien de temps la page de séance qui s'ouvre peut se faire attendre pour recevoir son message. */
const ATTENTE_DE_LA_SEANCE_MS = 20_000

/** Un démarrage à la fois dans l'onglet, de quelque bouton qu'il parte. */
const demarrageEnCours = ref(false)

/** Une copie hors ligne, lue par le magasin de la page (`copiesHorsLigne.js`), qui vérifie clé, titulaire et contrat. */
const lireUneCopieDuMagasin = (adresse) => magasinDesCopies().lireUneCopie(adresse)

/**
 * Ouvre la séance démarrée sans réseau, construite depuis l'entrée de sa
 * création (`entree`), ou depuis la séance reçue du serveur quand la visite
 * qui devait l'ouvrir échoue (`seance`).
 *
 * Les exercices d'un modèle lancé sans réseau (`exercicesEnPlus`, ceux de
 * `lancements`) s'ajoutent à la bibliothèque quand elle ne les a pas : la
 * séance les nomme, et on peut en ajouter de nouveau.
 *
 * C'est une visite côté client (`router.push`, ou `replace`) : elle n'émet pas
 * `beforeUpdate`, sur lequel une séance ouverte fige ce qu'elle garde dans
 * l'historique (`useSeanceEtSaFile`). `EVENEMENT_AVANT_UNE_COPIE` part donc
 * d'abord, avec la page qui arrive. La bibliothèque d'exercices et les
 * plafonds d'une série viennent de la page courante, sinon des copies hors
 * ligne (`bibliothequeHorsLigne`).
 *
 * @param {{
 *   routeur?: { push: (page: object) => void, replace: (page: object) => void },
 *   entree?: object,
 *   seance?: object,
 *   remplacer?: boolean,
 *   page?: object,
 *   lireUneCopie?: (adresse: string) => Promise<object | null>,
 *   fenetre?: Window,
 *   exercicesEnPlus?: Array<{ id: number, name: string|null, type: string }>,
 * }} options
 * @returns {Promise<object>} la page poussée
 */
export const ouvrirLaSeanceEnAttente = async ({
    routeur = router,
    entree,
    seance,
    remplacer = false,
    page = usePage(),
    lireUneCopie = lireUneCopieDuMagasin,
    fenetre = window,
    exercicesEnPlus = [],
} = {}) => {
    const { exercices, bornes } = await bibliothequeHorsLigne({ props: page.props, lireUneCopie })
    const manquants = exercicesEnPlus.filter(
        (enPlus, rang) =>
            !exercices.some((exercice) => String(exercice?.id) === String(enPlus.id)) &&
            exercicesEnPlus.findIndex((autre) => String(autre.id) === String(enPlus.id)) === rang,
    )
    const aPousser = pageDeLaSeanceEnAttente({
        entree,
        seance,
        auth: page.props?.auth,
        exercices: [...exercices, ...manquants],
        bornes,
        pageCourante: page,
    })

    fenetre.dispatchEvent(new CustomEvent(EVENEMENT_AVANT_UNE_COPIE, { detail: { page: aPousser } }))

    if (remplacer) {
        routeur.replace(aPousser)
    } else {
        routeur.push(aPousser)
    }

    return aPousser
}

/**
 * La création d'une séance encore en file, ni retirée ni refusée, ni close par
 * une fin en file (#2049) : celle que démarrer ouvre au lieu d'en
 * créer une autre.
 *
 * @param {Array<object>} entrees les écritures du compte en file
 * @returns {object|undefined}
 */
const creationEnFile = (entrees) =>
    entrees.find(
        (entree) =>
            estUneCreationDeSeance(entree) &&
            entree.aAnnuler === undefined &&
            !estCloseParLaFile(seanceDeLEntree(entree), entrees),
    )

/**
 * Le message d'erreur du layout, comme une écriture refusée de la séance
 * (`useRapportDeSynchronisation`).
 *
 * @param {object} page
 * @param {string} message
 */
const direLeRefus = (page, message) => {
    const flash = page.props.flash ?? (page.props.flash = {})

    flash.error = message
    triggerHaptic('error')
}

/**
 * Dit `message` sur la page qui s'ouvre ensuite, si c'est une séance : la
 * séance en cours qu'un modèle ouvre au lieu de démarrer. Dit sur la page des
 * modèles, le message partirait avec elle ; la séance s'ouvre par une visite du
 * serveur, par une copie servie sans réseau ou par une visite côté client
 * (une séance démarrée sans réseau), et seule l'adresse de la page dit, dans
 * les trois cas, qu'elle est arrivée. La première page qui arrive arrête
 * l'attente, quelle qu'elle soit ; une séance qui ne s'ouvre pas (sans réseau
 * ni copie) ne la laisse pas traîner au-delà de `ATTENTE_DE_LA_SEANCE_MS`.
 *
 * @param {object} page la page d'Inertia (`usePage()`)
 * @param {string} message
 */
const direSurLaSeanceQuiSOuvre = (page, message) => {
    const depart = page.url
    let echeance = null
    const arreter = watch(
        () => page.url,
        (url) => {
            if (url === depart) {
                return
            }

            arreter()
            clearTimeout(echeance)

            if (page.component === COMPOSANT_DE_LA_SEANCE) {
                direLeRefus(page, message)
            }
        },
    )

    echeance = setTimeout(arreter, ATTENTE_DE_LA_SEANCE_MS)
}

/**
 * Si `valeur` est un modèle à lancer : un bouton qui démarre par
 * `@click="demarrer"` passe son évènement, qui n'en est pas un.
 *
 * @param {unknown} valeur
 * @returns {boolean}
 */
const estUnModele = (valeur) =>
    valeur !== null &&
    typeof valeur === 'object' &&
    !(valeur instanceof Event) &&
    (typeof valeur.id === 'number' || typeof valeur.id === 'string') &&
    typeof valeur.name === 'string'

/**
 * Les exercices d'un modèle, tels que `lancements` les donne, pour la
 * bibliothèque de la séance qui s'ouvre sans réseau.
 *
 * @param {{ lignes?: Array<{ exercise?: { id: number, name?: string|null, type?: string|null } }> }} lancement
 * @returns {Array<{ id: number, name: string|null, type: string }>}
 */
const exercicesDuLancement = (lancement) =>
    (Array.isArray(lancement?.lignes) ? lancement.lignes : [])
        .map((ligne) => ligne?.exercise)
        .filter((exercice) => exercice !== null && typeof exercice === 'object' && exercice.id !== undefined)
        .map((exercice) => ({ id: exercice.id, name: exercice.name ?? null, type: exercice.type ?? 'strength' }))

/**
 * @returns {{
 *   demarrer: (modele?: { id: number, name: string } | null) => Promise<void>,
 *   enCours: Readonly<import('vue').Ref<boolean>>,
 * }}
 */
export const useDemarrageDUneSeance = () => {
    const page = usePage()
    const { joignable } = useEtatDuReseau()

    /**
     * Ouvre la séance démarrée sans réseau dont la file tient la création.
     *
     * @param {string|null|undefined} queueId
     * @param {Array<object>} exercicesEnPlus ceux d'un modèle, pour la bibliothèque
     */
    const ouvrirLEntree = async (queueId, exercicesEnPlus = []) => {
        const entree = queueId ? SyncService.entreeStockee(queueId) : undefined

        if (entree === undefined) {
            annoncerSansReseau(DEMARRAGE_SANS_RESEAU)

            return
        }

        await ouvrirLaSeanceEnAttente({ entree, page, exercicesEnPlus })
    }

    /**
     * Ouvre la séance déjà en cours, et dit, pour un modèle, qu'il n'a pas
     * été démarré.
     *
     * @param {() => Promise<unknown>|unknown} ouvrir
     * @param {{ name: string } | null} modele
     */
    const ouvrirLaSeanceEnCours = async (ouvrir, modele) => {
        if (modele !== null) {
            direSurLaSeanceQuiSOuvre(page, messageDuModeleNonDemarre(modele.name))
        }

        await ouvrir()
    }

    /**
     * Ce que devient un démarrage qui n'a pas reçu la séance.
     *
     * @param {any} erreur
     * @param {{ name: string } | null} modele
     */
    const apresUnEchec = async (erreur, modele) => {
        if (erreur?.isOffline) {
            await ouvrirLEntree(erreur.queueId)

            return
        }

        if (erreur?.nonGardee) {
            annoncerSansReseau(DEMARRAGE_SANS_RESEAU)

            return
        }

        /*
         * La session a expiré (401), ou son jeton n'est plus le bon (419) : la
         * porte est fermée, le démarrage n'est pas refusé. La page se
         * redemande au serveur, qui mène à la connexion et y reviendra
         * ensuite, comme la visite d'Inertia de la page le faisait avant
         * #2049. « Réessaie » rendait le même refus à chaque appui.
         */
        if (classifySyncError(erreur) === SYNC_AUTH) {
            router.visit(page.url)

            return
        }

        const refus = erreur?.response
        const enCours = refus?.data?.seance?.id

        if (refus?.status === 409 && refus.data?.raison === RAISON_SEANCE_DEJA_EN_COURS && enCours) {
            await ouvrirLaSeanceEnCours(() => router.visit(route('workouts.show', enCours)), modele)

            return
        }

        direLeRefus(page, DEMARRAGE_REFUSE)
    }

    /**
     * @param {{ id: number, name: string } | null} modeleDemande le modèle à lancer,
     *   ou rien pour une séance vide (un évènement de clic n'en est pas un)
     */
    const demarrer = async (modeleDemande = null) => {
        if (demarrageEnCours.value) {
            return
        }

        const modele = estUnModele(modeleDemande) ? modeleDemande : null

        demarrageEnCours.value = true

        /** La visite de la séance créée rend elle-même le verrou, à sa fin. */
        let rendreLeVerrou = true

        try {
            const entrees = SyncService.entreesDuCompte()
            const enFile = creationEnFile(entrees)

            if (enFile !== undefined) {
                await ouvrirLaSeanceEnCours(() => ouvrirLaSeanceEnAttente({ entree: enFile, page }), modele)

                return
            }

            const sansServeur = estUneCopie(page) || !joignable.value
            const duServeur = page.props.auth?.user?.active_workout
            // Terminée sur l'appareil, sa fin en file : une nouvelle séance se crée derrière elle.
            const seanceEnCours = duServeur && !estCloseParLaFile(duServeur, entrees) ? duServeur : null

            if (sansServeur && seanceEnCours?.id) {
                await ouvrirLaSeanceEnCours(() => router.visit(route('workouts.show', seanceEnCours.id)), modele)

                return
            }

            /** Le contenu du modèle et les clés que le serveur dérive, d'après la page des modèles. */
            const lancement = modele === null ? null : (page.props.lancements?.[modele.id] ?? null)

            const ecriture = {
                url: route('api.v1.workouts.store'),
                data: {
                    started_at: new Date().toISOString(),
                    ...(modele === null ? {} : { template_id: modele.id }),
                },
                headers: { 'Idempotency-Key': nouvelleCleDIdempotence() },
            }

            /**
             * La séance du modèle, ses lignes et ses séries, en file d'un seul
             * coup sous les clés du serveur, puis ouverte (`tentee` : le
             * lancement est parti sans réponse).
             *
             * @param {boolean} tentee
             */
            const ouvrirLaSeanceDuModele = async (tentee) => {
                if (lancement === null) {
                    annoncerSansReseau(MODELE_SANS_RESEAU)

                    return
                }

                const queueId = SyncService.mettreEnFileLaSeanceDUnModele({
                    cle: ecriture.headers['Idempotency-Key'],
                    nom: lancement.name ?? modele.name,
                    started_at: ecriture.data.started_at,
                    lignes: lancement.lignes,
                    tentee,
                })

                if (queueId === null) {
                    annoncerSansReseau(DEMARRAGE_SANS_RESEAU)

                    return
                }

                await ouvrirLEntree(queueId, exercicesDuLancement(lancement))
            }

            if (sansServeur && modele !== null) {
                await ouvrirLaSeanceDuModele(false)

                return
            }

            if (sansServeur) {
                const queueId = SyncService.mettreEnFile({ method: 'post', ...ecriture })

                if (queueId === null) {
                    annoncerSansReseau(DEMARRAGE_SANS_RESEAU)

                    return
                }

                await ouvrirLEntree(queueId)

                return
            }

            let reponse

            try {
                reponse = await SyncService.post(ecriture.url, ecriture.data, {
                    headers: ecriture.headers,
                    delaiDeLaTentative: DELAI_AVANT_UNE_COPIE_MS,
                    // Un modèle ne se rejoue jamais tel quel : sans réponse, la file reçoit son contenu.
                    ...(modele === null ? {} : { garderEnFile: false }),
                })
            } catch (erreur) {
                if (modele !== null && erreur?.sansReponse) {
                    await ouvrirLaSeanceDuModele(erreur.tentee === true)

                    return
                }

                await apresUnEchec(erreur, modele)

                return
            }

            const recue = reponse?.data?.data

            if (!recue?.id) {
                direLeRefus(page, DEMARRAGE_REFUSE)

                return
            }

            rendreLeVerrou = false

            router.visit(route('workouts.show', recue.id), {
                /*
                 * La séance existe sur le serveur, mais sa page ne vient pas :
                 * elle s'ouvre construite depuis la séance reçue, qui porte un
                 * identifiant réel, sous l'adresse de sa clé.
                 */
                onNetworkError: direSansReseau(null, () => {
                    ouvrirLaSeanceEnAttente({
                        seance: {
                            ...recue,
                            idempotency_key: ecriture.headers['Idempotency-Key'],
                            user_id: page.props.auth?.user?.id ?? null,
                            workout_volume: 0,
                            workout_lines: [],
                        },
                        page,
                    })
                }),
                onFinish: () => {
                    demarrageEnCours.value = false
                },
            })
        } finally {
            if (rendreLeVerrou) {
                demarrageEnCours.value = false
            }
        }
    }

    return { demarrer, enCours: readonly(demarrageEnCours) }
}
