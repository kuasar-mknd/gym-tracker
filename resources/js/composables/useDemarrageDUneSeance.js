import { readonly, ref } from 'vue'
import { router, usePage } from '@inertiajs/vue3'
import SyncService, { nouvelleCleDIdempotence } from '@/Utils/SyncService'
import { magasinDesCopies } from '@/Utils/copiesHorsLigne'
import { estUneCopie } from '@/Utils/copiesServies'
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
 *      séance en attente s'ouvre.
 *
 * Démarrer reste permis sur une copie hors ligne : rien n'y est tiré de la
 * copie, ni la clé, ni l'heure, ni la séance (`.ai/rules/js.md`). Un verrou
 * de l'onglet, au niveau du module, tient pour tous les boutons ensemble :
 * deux appuis rapprochés ne démarrent qu'une séance.
 */

/** Ce que dit un démarrage que le serveur a refusé sans nommer de séance en cours. */
export const DEMARRAGE_REFUSE = "La séance n'a pas pu démarrer. Réessaie."

/** Un démarrage à la fois dans l'onglet, de quelque bouton qu'il parte. */
const demarrageEnCours = ref(false)

/** Une copie hors ligne, lue par le magasin de la page (`copiesHorsLigne.js`), qui vérifie clé, titulaire et contrat. */
const lireUneCopieDuMagasin = (adresse) => magasinDesCopies().lireUneCopie(adresse)

/**
 * Ouvre la séance démarrée sans réseau, construite depuis l'entrée de sa
 * création (`entree`), ou depuis la séance reçue du serveur quand la visite
 * qui devait l'ouvrir échoue (`seance`).
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
} = {}) => {
    const { exercices, bornes } = await bibliothequeHorsLigne({ props: page.props, lireUneCopie })
    const aPousser = pageDeLaSeanceEnAttente({
        entree,
        seance,
        auth: page.props?.auth,
        exercices,
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
 * @returns {{ demarrer: () => Promise<void>, enCours: Readonly<import('vue').Ref<boolean>> }}
 */
export const useDemarrageDUneSeance = () => {
    const page = usePage()
    const { joignable } = useEtatDuReseau()

    /**
     * Ouvre la séance démarrée sans réseau dont la file tient la création.
     *
     * @param {string|null|undefined} queueId
     */
    const ouvrirLEntree = async (queueId) => {
        const entree = queueId ? SyncService.entreeStockee(queueId) : undefined

        if (entree === undefined) {
            annoncerSansReseau(DEMARRAGE_SANS_RESEAU)

            return
        }

        await ouvrirLaSeanceEnAttente({ entree, page })
    }

    /**
     * Ce que devient un démarrage qui n'a pas reçu la séance.
     *
     * @param {any} erreur
     */
    const apresUnEchec = async (erreur) => {
        if (erreur?.isOffline) {
            await ouvrirLEntree(erreur.queueId)

            return
        }

        if (erreur?.nonGardee) {
            annoncerSansReseau(DEMARRAGE_SANS_RESEAU)

            return
        }

        const refus = erreur?.response
        const enCours = refus?.data?.seance?.id

        if (refus?.status === 409 && refus.data?.raison === RAISON_SEANCE_DEJA_EN_COURS && enCours) {
            router.visit(route('workouts.show', enCours))

            return
        }

        direLeRefus(page, DEMARRAGE_REFUSE)
    }

    const demarrer = async () => {
        if (demarrageEnCours.value) {
            return
        }

        demarrageEnCours.value = true

        /** La visite de la séance créée rend elle-même le verrou, à sa fin. */
        let rendreLeVerrou = true

        try {
            const entrees = SyncService.entreesDuCompte()
            const enFile = creationEnFile(entrees)

            if (enFile !== undefined) {
                await ouvrirLaSeanceEnAttente({ entree: enFile, page })

                return
            }

            const sansServeur = estUneCopie(page) || !joignable.value
            const duServeur = page.props.auth?.user?.active_workout
            // Terminée sur l'appareil, sa fin en file : une nouvelle séance se crée derrière elle.
            const seanceEnCours = duServeur && !estCloseParLaFile(duServeur, entrees) ? duServeur : null

            if (sansServeur && seanceEnCours?.id) {
                router.visit(route('workouts.show', seanceEnCours.id))

                return
            }

            const ecriture = {
                url: route('api.v1.workouts.store'),
                data: { started_at: new Date().toISOString() },
                headers: { 'Idempotency-Key': nouvelleCleDIdempotence() },
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
                })
            } catch (erreur) {
                await apresUnEchec(erreur)

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
