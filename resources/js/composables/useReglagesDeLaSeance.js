import { getCurrentInstance, onMounted, onUnmounted, ref } from 'vue'
import { router, useForm, usePage } from '@inertiajs/vue3'
import { attendreAuPlus } from '@/Utils/attendreAuPlus'
import { estUneCopie } from '@/Utils/copiesServies'
import { formatToLocalISO, formatToUTC } from '@/Utils/date'
import { PendingIds, isTemporaryId } from '@/Utils/pendingIds'
import { FIN_NON_ENREGISTREE } from '@/Utils/seanceEnAttente'
import { estUneFinDeSeance } from '@/Utils/seanceEtFile'
import SyncService from '@/Utils/SyncService'
import { SYNC_AUTH, classifySyncError } from '@/Utils/syncErrors'
import { DELAI_AVANT_UNE_COPIE_MS } from '@/Utils/visitesHorsLigne'
import { recalerApresLaCopie } from '@/composables/useCopieHorsLigne'
import { useEtatDuReseau } from '@/composables/useEtatDuReseau'
import { triggerHaptic } from '@/composables/useHaptics'

/**
 * Ce que « Terminer » attend, au plus, les écritures qui précèdent la fin.
 * Un vidage n'a pas de délai maximum : une requête par entrée, sur un réseau
 * qui peut être lent. Au-delà, une écriture encore en vol empêche la fin, et
 * la modale le dit (#1961).
 */
export const ATTENTE_MAX_DE_LA_FILE_MS = 8000

/** Les annonces de la file qui disent ce qu'est devenue une fin qui l'attendait (#2049). */
const ANNONCES_DE_LA_FIN = ['sync:replayed', 'sync:failed', 'sync:retired']

/**
 * Les reglages de la seance, sa fin et son enregistrement comme modele.
 *
 * @param {{
 *   localWorkout: import('vue').Ref<object>,
 *   viderLesEcritures: () => Promise<unknown>,
 *   pendingIds?: import('@/Utils/pendingIds').PendingIds,
 * }} page la copie locale de la séance, ce qui envoie les saisies en attente,
 *   et les identifiants provisoires de la page : une séance démarrée sans
 *   réseau se nomme par l'entrée de sa création (#2049)
 */
export const useReglagesDeLaSeance = ({ localWorkout, viderLesEcritures, pendingIds = new PendingIds() }) => {
    const page = usePage()
    const { joignable } = useEtatDuReseau()

    /**
     * L'identifiant que le serveur a donné à la séance, ou null pour une
     * séance démarrée sans réseau qu'il n'a pas encore adoptée (#2049) : ses
     * réglages et « Modèle » sont désactivés, et rien ne part en son nom.
     *
     * @returns {number|string|null}
     */
    const idReelDeLaSeance = () => (isTemporaryId(localWorkout.value.id) ? null : localWorkout.value.id)

    const savingTemplate = ref(false)
    const saveAsTemplate = () => {
        const realWorkoutId = idReelDeLaSeance()

        if (realWorkoutId === null) {
            return
        }

        savingTemplate.value = true
        router.post(
            route('templates.save-from-workout', { workout: realWorkoutId }),
            {},
            {
                preserveScroll: true,
                onFinish: () => (savingTemplate.value = false),
            },
        )
    }

    const showFinishModal = ref(false)

    /** Les écritures encore en vol quand on a voulu terminer : la modale le dit, et la séance reste ouverte. */
    const ecrituresEnAttente = ref(0)

    /**
     * Une clôture attend les écritures, puis la réponse du serveur. Pendant ce
     * temps, la modale le montre, et un nouvel appui sur « Confirmer » ne fait
     * rien : chacun envoyait sa propre clôture une fois le vidage fini.
     */
    const clotureEnCours = ref(false)

    /**
     * Le numéro de la dernière tentative de clôture. L'abandonner (« Annuler »,
     * le fond ou Échap pendant l'attente) le fait avancer : la tentative qui
     * reprend la main après ses attentes ne clôt plus. Elle closait quand
     * même, jusqu'à huit secondes après que la personne avait renoncé, et une
     * clôture ne se défait pas (#1961).
     */
    let tentative = 0

    /** La fin est partie, au serveur ou dans la file : elle ne s'abandonne plus. */
    let cloturePartie = false

    const finishWorkout = () => {
        ecrituresEnAttente.value = 0
        showFinishModal.value = true
    }

    /** Ferme la question, et abandonne la clôture qui attendait encore les écritures. */
    const annulerLaCloture = () => {
        showFinishModal.value = false

        if (cloturePartie) {
            return
        }

        tentative += 1
        clotureEnCours.value = false
    }

    /** Le toast d'erreur du layout, comme une écriture refusée de la séance (`useRapportDeSynchronisation`). */
    const direLeRefus = () => {
        const flash = page.props.flash ?? (page.props.flash = {})

        flash.error = FIN_NON_ENREGISTREE
        triggerHaptic('error')
    }

    /**
     * La fin attend la file (#2049) : la séance se montre close, à l'heure du
     * geste, et dit qu'elle sera enregistrée au retour du réseau
     * (`EtatDeLaSeanceEnAttente.vue`). La personne reste sur elle : l'accueil
     * peut ne pas avoir de copie sur l'appareil.
     *
     * @param {string} fin l'heure du geste
     * @param {string} queueId l'entrée de file de la fin
     */
    const montrerLaFinEnAttente = (fin, queueId) => {
        localWorkout.value.ended_at = fin
        localWorkout.value._finEnAttente = queueId
        showFinishModal.value = false
        triggerHaptic('success')
    }

    /**
     * Le serveur a clos la séance : l'accueil, et l'invitation aux
     * notifications quand la réponse le dit. Une donnée flash posée par la
     * page (`router.flash`), qu'Inertia ne range jamais dans l'historique :
     * Retour puis Avance ne la remontrent pas, et l'API, que la file rejoue,
     * n'en pose aucune dans la session.
     *
     * @param {object|undefined} reponse
     * @param {string} fin l'heure du geste
     */
    const apresLaFin = (reponse, fin) => {
        localWorkout.value.ended_at = reponse?.data?.data?.ended_at ?? fin
        delete localWorkout.value._finEnAttente
        showFinishModal.value = false
        triggerHaptic('success')

        const proposer = reponse?.data?.meta?.proposer_les_notifications === true

        router.visit(route('dashboard'), {
            onSuccess: () => {
                if (proposer) {
                    router.flash('proposerLesNotifications', true)
                }
            },
        })
    }

    /**
     * « Terminer » (#2049) : l'heure du geste, prise au « Confirmer » avant
     * toute attente, puis la fin, par la file hors ligne.
     *
     * 1. Les saisies en attente partent (`viderLesEcritures`) : une valeur
     *    tapée juste avant doit précéder la fin.
     * 2. Les écritures qui précèdent la fin sont attendues, huit secondes au
     *    plus (#1961). Le serveur joignable, la file et ce que son vidage
     *    envoie hors d'elle (`attendreLesEcritures`) : une saisie que
     *    l'adoption d'une série rejouée renvoie partirait sinon en même temps
     *    que la fin, et le serveur la refuserait sur une séance close. Sans
     *    serveur (une copie, ou `joignable` faux), les seules écritures en vol
     *    (`attendreLesEcrituresDirectes`) : ce qui attend en file part avant la
     *    fin par l'ordre de la file, et ne partirait pas maintenant de toute
     *    façon. Une écriture encore en vol au bout de l'attente empêche la
     *    fin : la modale le dit.
     * 3. La séance se nomme par sa référence (`pendingIds.reference`) : son
     *    identifiant, ou l'entrée de sa création pour une séance démarrée sans
     *    réseau (`{ enAttenteDe }`). La fin entre en file sans tentative quand
     *    la séance attend sa création, quand rien ne répond, ou quand la file
     *    garde encore des écritures : derrière elles, jamais fondue dans la
     *    création, qui naîtrait close et refuserait ses séries. Sinon elle part
     *    (`PATCH /api/v1/workouts/finish`), cinq secondes au plus
     *    (`DELAI_AVANT_UNE_COPIE_MS`) avant de tomber en file.
     *
     * Acceptée, l'accueil ; en file, la séance close sur place ; refusée, un
     * toast, et la séance reste ouverte. La session expirée (401, 419), la fin
     * attend en file, à l'heure du geste, et la page se redemande au serveur,
     * qui mène à la connexion : la fin repart après elle.
     */
    const confirmFinishWorkout = async () => {
        if (clotureEnCours.value || localWorkout.value.ended_at) {
            return
        }

        /*
         * L'heure du geste, celle que la personne a vue en appuyant : les
         * attentes qui suivent n'y changent rien. Le serveur n'en garde que
         * l'âge au départ de la requête (`ENTETE_DE_L_HEURE_DE_L_APPAREIL`) :
         * une horloge d'appareil décalée ne déplace pas la fin.
         */
        const fin = new Date().toISOString()

        clotureEnCours.value = true
        ecrituresEnAttente.value = 0

        tentative += 1
        const cetteTentative = tentative
        const abandonnee = () => cetteTentative !== tentative

        try {
            /**
             * Awaited, not merely started. Closing the session revokes the right to
             * write to its sets, so the last value typed has to be accepted before the
             * workout is finished — otherwise it arrives at a closed session, is refused
             * 403, and is reverted on a page that has already navigated away.
             */
            await viderLesEcritures()

            if (abandonnee()) {
                return
            }

            const sansServeur = estUneCopie(page) || !joignable.value

            await attendreAuPlus(
                sansServeur ? SyncService.attendreLesEcrituresDirectes() : SyncService.attendreLesEcritures(),
                ATTENTE_MAX_DE_LA_FILE_MS,
            )

            if (abandonnee()) {
                return
            }

            ecrituresEnAttente.value = SyncService.ecrituresEnCours()

            if (ecrituresEnAttente.value > 0) {
                return
            }

            const realWorkoutId = await pendingIds.reference(localWorkout.value.id)

            if (abandonnee() || realWorkoutId === null) {
                return
            }

            cloturePartie = true

            const ecriture = {
                url: route('api.v1.workouts.finish'),
                data: { workout_id: realWorkoutId, ended_at: fin },
            }

            if (sansServeur || typeof realWorkoutId === 'object' || SyncService.enAttente() > 0) {
                const queueId = SyncService.mettreEnFile({ method: 'patch', ...ecriture })

                if (queueId === null) {
                    showFinishModal.value = false
                    direLeRefus()

                    return
                }

                montrerLaFinEnAttente(fin, queueId)

                return
            }

            let reponse

            try {
                reponse = await SyncService.patch(ecriture.url, ecriture.data, {
                    delaiDeLaTentative: DELAI_AVANT_UNE_COPIE_MS,
                })
            } catch (erreur) {
                if (erreur?.isOffline) {
                    montrerLaFinEnAttente(fin, erreur.queueId)

                    return
                }

                /*
                 * La session a expiré (401), ou son jeton n'est plus le bon
                 * (419) : la porte est fermée, la fin n'est pas refusée. Elle
                 * attend en file, à l'heure du geste, comme la file garde ce
                 * qu'elle rejoue sur une porte fermée, et la page se redemande
                 * au serveur, qui mène à la connexion puis y revient : la fin
                 * repart alors. Le toast « elle reste ouverte » se répétait à
                 * chaque essai, sans chemin vers la connexion.
                 */
                if (classifySyncError(erreur) === SYNC_AUTH) {
                    const queueId = SyncService.mettreEnFile({ method: 'patch', ...ecriture })

                    if (queueId === null) {
                        showFinishModal.value = false
                    } else {
                        montrerLaFinEnAttente(fin, queueId)
                    }

                    router.visit(page.url)

                    return
                }

                showFinishModal.value = false
                direLeRefus()

                return
            }

            apresLaFin(reponse, fin)
        } finally {
            cloturePartie = false

            // Abandonnée, elle a déjà rendu la main.
            if (!abandonnee()) {
                clotureEnCours.value = false
            }
        }
    }

    /**
     * Ce qu'est devenue la fin que la séance attend dans la file (#2049), la
     * page restée ouverte : rejouée, la séance est close pour le serveur, à
     * l'heure qu'il a gardée ; refusée (la séance supprimée ailleurs, par
     * exemple) ou retirée de la file, elle se rouvre, et un refus se dit,
     * sauf celui que la séance démarrée sans réseau emporte (`parentRefuse`),
     * que le layout annonce. Sans cela, la fusion des props gardait la fin
     * locale pour toujours, et la séance restait close à l'écran, ouverte sur
     * le serveur.
     *
     * @param {CustomEvent} event
     */
    const suivreLaFin = (event) => {
        const enAttente = localWorkout.value._finEnAttente
        const detail = event.detail ?? {}
        const laFin =
            event.type === 'sync:retired'
                ? (detail.queueIds ?? []).includes(enAttente)
                : detail.queueId === enAttente && estUneFinDeSeance({ method: 'patch', url: detail.url })

        if (enAttente === undefined || !laFin) {
            return
        }

        delete localWorkout.value._finEnAttente

        if (event.type === 'sync:replayed') {
            localWorkout.value.ended_at = detail.data?.ended_at ?? localWorkout.value.ended_at

            return
        }

        localWorkout.value.ended_at = null

        if (event.type === 'sync:failed' && detail.parentRefuse !== true) {
            direLeRefus()
        }
    }

    if (getCurrentInstance()) {
        onMounted(() => ANNONCES_DE_LA_FIN.forEach((annonce) => window.addEventListener(annonce, suivreLaFin)))
        onUnmounted(() => ANNONCES_DE_LA_FIN.forEach((annonce) => window.removeEventListener(annonce, suivreLaFin)))
    }

    const showSettingsModal = ref(false)

    /** Les réglages de la séance, tels que le formulaire les reprend de la copie locale. */
    const reglagesDeLaSeance = () => ({
        name: localWorkout.value.name,
        started_at: formatToLocalISO(localWorkout.value.started_at),
        notes: localWorkout.value.notes || '',
    })

    const settingsForm = useForm(reglagesDeLaSeance())

    // Servie d'une copie hors ligne puis rechargée au retour du réseau, la séance reprend les réglages du serveur (#2048).
    recalerApresLaCopie(settingsForm, reglagesDeLaSeance)

    /**
     * Le début ne part que si l'utilisateur l'a changé.
     *
     * Le champ n'affiche que la minute : renvoyer une heure intacte écrivait
     * quand même une autre valeur, tronquée de ses secondes, et faisait passer un
     * simple renommage pour un déplacement de la séance (#1952). Une heure
     * changée part en UTC : le navigateur n'est pas forcément dans le fuseau de
     * l'application, et le serveur ramène l'instant au sien.
     */
    const updateSettings = () => {
        const realWorkoutId = idReelDeLaSeance()

        if (realWorkoutId === null) {
            return
        }

        settingsForm
            .transform(({ started_at, ...reglages }) =>
                started_at === formatToLocalISO(localWorkout.value.started_at)
                    ? reglages
                    : { ...reglages, started_at: formatToUTC(started_at) },
            )
            .patch(route('workouts.update', { workout: realWorkoutId }), {
                preserveScroll: true,
                onSuccess: () => {
                    showSettingsModal.value = false
                },
            })
    }

    return {
        savingTemplate,
        saveAsTemplate,
        showFinishModal,
        ecrituresEnAttente,
        clotureEnCours,
        finishWorkout,
        annulerLaCloture,
        confirmFinishWorkout,
        showSettingsModal,
        settingsForm,
        updateSettings,
    }
}
