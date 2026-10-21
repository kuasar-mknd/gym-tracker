import { computed, getCurrentInstance, onMounted, onUnmounted, ref, watch } from 'vue'
import { router } from '@inertiajs/vue3'
import SyncService from '@/Utils/SyncService'
import { creerLesAttentesDeRejeu } from '@/Utils/attentesDeRejeu'
import { entreeDeLaRangee } from '@/Utils/seanceEtFile'
import {
    RAISON_SEANCE_DEJA_EN_COURS,
    adresseDUneSeanceEnAttente,
    cleDeLAdresseEnAttente,
} from '@/Utils/seanceEnAttente'
import { surUneReponseDuServeur, useEtatDuReseau } from '@/composables/useEtatDuReseau'

/** Sa création attend la file. */
export const ETAT_EN_ATTENTE = 'en-attente'

/** Le serveur l'a refusée : une autre séance était déjà en cours. */
export const ETAT_REFUSEE = 'refusee'

/** Le serveur l'a refusée pour une autre raison, ou sa création a quitté la file sans rien produire. */
export const ETAT_NON_ENREGISTREE = 'non-enregistree'

/** Sa création n'est plus en file, et la page ne sait pas ce qu'elle est devenue. */
export const ETAT_A_RETROUVER = 'a-retrouver'

/** L'identifiant du texte qui dit l'état de la séance (`EtatDeLaSeanceEnAttente.vue`), que ses boutons désactivés visent. */
export const ID_DE_L_ETAT_DE_LA_SEANCE = 'etat-de-la-seance-en-attente'

/** Ce qui rend la page de la séance en lecture seule. */
const ETATS_SANS_SEANCE = new Set([ETAT_REFUSEE, ETAT_NON_ENREGISTREE, ETAT_A_RETROUVER])

/** Les annonces de la file, à chacune desquelles l'adoption se réessaie. */
const ANNONCES_DE_LA_FILE = ['sync:queued', 'sync:replayed', 'sync:failed', 'sync:retired']

/** Si la personne a le doigt dans un champ : une valeur à moitié tapée ne se perd pas dans une visite. */
const champEnSaisie = () => {
    const actif = globalThis.document?.activeElement

    return actif instanceof HTMLElement && actif.matches('input, textarea, select')
}

/**
 * Si une fenêtre est ouverte (`Modal.vue`, un `<dialog>`) : la question de
 * « Terminer » surtout, que la séance en attente propose aussi (#2049). La
 * visite remonterait la page et fermerait la question sous le doigt.
 */
const fenetreOuverte = () => globalThis.document?.querySelector?.('dialog[open]') != null

/**
 * La séance démarrée sans réseau, sur sa page (#2049).
 *
 * La page `Workouts/Show` d'une séance en attente vient de la file
 * (`Utils/seanceEnAttente.js`) : la séance porte l'identifiant provisoire de sa
 * création, `temp-q<queueId>`. Elle s'y suit comme une ligne en file : notée
 * en file (`pendingIds.noterEnFile`), ses écritures la nomment par cette entrée
 * (`{ enAttenteDe }`) et s'y rangent derrière elle ; suivie jusqu'au rejeu
 * (`pendingIds.track`, `attentesDeRejeu`), celles qui attendent son
 * identifiant (l'ordre des exercices) le reçoivent alors.
 *
 * - **Adoption.** Le serveur a créé la séance, la file du compte est vide,
 *   rien ne vole, et la personne ne fait rien qu'une visite interromprait (un
 *   repos, une saisie, une modale ouverte : `occupee`, une fenêtre ouverte,
 *   et un champ qui a le focus) : la page passe à la séance réelle, par une
 *   visite qui remplace
 *   l'entrée d'historique. Elle se réessaie à chaque annonce de la file, et
 *   quand la personne cesse d'être occupée.
 * - **Refus.** Le serveur refuse sa création : la page passe en lecture
 *   seule, et dit pourquoi (`etat`). Ses écritures, refusées avec elle faute
 *   de parent, ne disent rien d'elles-mêmes : le layout annonce le refus une
 *   fois.
 * - **Entrée périmée.** Rouverte par l'historique quand sa création n'est plus
 *   en file : refusée, elle le dit ; rejouée dans cet onglet, la séance réelle
 *   s'ouvre ; sinon, le serveur joignable, son adresse s'ouvre, et le serveur
 *   mène à la séance que sa clé désigne ; sinon, elle le dit, en lecture
 *   seule.
 *
 * - **Séance reçue sous sa clé.** Démarrée en ligne, la séance a été créée,
 *   mais la visite qui devait l'ouvrir s'est perdue : elle s'ouvre construite
 *   depuis la séance reçue, sous l'adresse de sa clé (`useDemarrageDUneSeance`),
 *   avec son identifiant réel et sans ses exercices, que le serveur a (ceux
 *   d'un modèle lancé). À la première réponse du serveur, ou au montage s'il
 *   répond déjà, aux mêmes conditions que l'adoption, la page passe à la
 *   séance réelle et les montre. Elle restait vide jusqu'à un rechargement, et
 *   un exercice ajouté rejoignait ceux du modèle sans qu'on les voie.
 *
 * Une séance du serveur, sous sa propre adresse, n'est rien de tout cela :
 * `seanceProvisoire` est faux, et rien ne s'écoute.
 *
 * @param {{
 *   seance: object,
 *   adresse?: string|null,
 *   pendingIds: import('@/Utils/pendingIds').PendingIds,
 *   occupee?: () => boolean,
 *   valeursDeRepli?: () => Array<unknown>,
 *   routeur?: { visit: (url: string, options?: object) => void },
 * }} options la séance des props, l'adresse de la page (la clé s'y lit quand
 *   la séance ne la porte pas), les identifiants provisoires de la page, ce
 *   qui occupe la personne (lu à chaque essai, et suivi par Vue dès le
 *   montage), et les valeurs qui attendent leur envoi ou leur réponse
 *   (`useBrouillonsDeSeries`)
 * @returns {{
 *   seanceProvisoire: boolean,
 *   etatDeLaSeance: import('vue').Ref<string|null>,
 *   seanceEnLectureSeule: import('vue').ComputedRef<boolean>,
 * }} si la séance porte l'identifiant provisoire de sa création, son état
 *   (`ETAT_EN_ATTENTE`…, null pour une séance du serveur), et si la page est
 *   en lecture seule faute de séance, ou pendant la visite qui l'ouvre
 */
export const useSeanceEnAttente = ({
    seance,
    adresse = null,
    pendingIds,
    occupee = () => false,
    valeursDeRepli = () => [],
    routeur = router,
}) => {
    const idProvisoire = seance?.id
    const queueId = entreeDeLaRangee(idProvisoire)
    const provisoire = queueId !== null
    const etat = ref(null)
    /** Une visite vers la séance réelle, ou vers l'adresse de sa clé, est partie. */
    const visiteEnCours = ref(false)
    const lectureSeule = computed(() => ETATS_SANS_SEANCE.has(etat.value) || visiteEnCours.value)
    /** La séance reçue du serveur, ouverte sous l'adresse de sa clé faute de sa propre page. */
    const recueSousSaCle =
        !provisoire && /^\d+$/.test(String(idProvisoire ?? '')) && cleDeLAdresseEnAttente(adresse) !== null

    if (!provisoire && !recueSousSaCle) {
        return { seanceProvisoire: provisoire, etatDeLaSeance: etat, seanceEnLectureSeule: lectureSeule }
    }

    const { joignable } = useEtatDuReseau()
    /** Ce qu'une visite interromprait : un repos, une modale, une valeur qui attend son envoi ou sa réponse. */
    const occupeeOuEnSaisie = () => occupee() || valeursDeRepli().length > 0
    let enPlace = true
    let essaiPrevu = null

    /** Refusée parce qu'une autre séance était en cours, ou pour une autre raison. */
    const etatDuRefus = (raison) => (raison === RAISON_SEANCE_DEJA_EN_COURS ? ETAT_REFUSEE : ETAT_NON_ENREGISTREE)

    /**
     * Ouvre `url` à la place de la page, l'entrée d'historique comprise. Sans
     * réponse, la page reste, et `sansReponse` dit ce qu'elle devient ; le
     * filet des visites hors ligne sert la copie de la séance s'il en a une.
     *
     * @param {string} url
     * @param {() => void} sansReponse
     */
    const visiter = (url, sansReponse) => {
        visiteEnCours.value = true

        // La file vide, rien en vol : la réponse a tout ce que le vidage a fait aboutir, la page ne se recharge pas après.
        if (SyncService.enAttente() === 0 && SyncService.ecrituresEnCours() === 0) {
            SyncService.visiteApresLeVidage()
        }

        routeur.visit(url, {
            replace: true,
            preserveScroll: true,
            onNetworkError: () => {
                visiteEnCours.value = false
                sansReponse()
            },
        })
    }

    /**
     * Passe à la séance réelle quand rien ne retient la visite : la file du
     * compte vide, rien en vol, et la personne ne fait rien qu'une visite
     * interromprait.
     *
     * @param {number|string|null} realWorkoutId
     */
    const passerALaSeanceReelle = (realWorkoutId) => {
        if (
            realWorkoutId === null ||
            SyncService.enAttente() > 0 ||
            occupeeOuEnSaisie() ||
            champEnSaisie() ||
            fenetreOuverte()
        ) {
            return
        }

        // Une écriture directe vole encore, la réponse d'une série adoptée par exemple : on revient après elle.
        if (SyncService.ecrituresEnCours() > 0) {
            SyncService.attendreLesEcritures().then(planifierLAdoption)

            return
        }

        visiter(route('workouts.show', { workout: realWorkoutId }), () => {})
    }

    const essayerLAdoption = () => {
        essaiPrevu = null

        if (!enPlace || visiteEnCours.value) {
            return
        }

        if (recueSousSaCle) {
            // Ses exercices sont au serveur : il faut qu'il réponde.
            if (joignable.value) {
                passerALaSeanceReelle(idProvisoire)
            }

            return
        }

        if (etat.value === ETAT_EN_ATTENTE) {
            passerALaSeanceReelle(SyncService.produitDe(queueId))
        }
    }

    /**
     * Une tâche plus tard : la ligne ou la série que le vidage vient de rendre
     * s'adopte d'abord, et ce qu'elle renvoie part avant la visite.
     */
    const planifierLAdoption = () => {
        if (essaiPrevu === null && enPlace) {
            essaiPrevu = setTimeout(essayerLAdoption, 0)
        }
    }

    let arreterLeSuivi = () => {}

    if (recueSousSaCle) {
        let arreterLesReponses = () => {}

        if (getCurrentInstance()) {
            onMounted(() => {
                arreterLeSuivi = watch(
                    () => occupeeOuEnSaisie(),
                    (estOccupee) => {
                        if (!estOccupee) {
                            planifierLAdoption()
                        }
                    },
                )
                arreterLesReponses = surUneReponseDuServeur(planifierLAdoption)
                ANNONCES_DE_LA_FILE.forEach((annonce) => window.addEventListener(annonce, planifierLAdoption))
                document.addEventListener('focusout', planifierLAdoption)
                planifierLAdoption()
            })

            onUnmounted(() => {
                enPlace = false
                arreterLeSuivi()
                arreterLesReponses()
                clearTimeout(essaiPrevu)
                essaiPrevu = null
                ANNONCES_DE_LA_FILE.forEach((annonce) => window.removeEventListener(annonce, planifierLAdoption))
                document.removeEventListener('focusout', planifierLAdoption)
            })
        }

        return { seanceProvisoire: provisoire, etatDeLaSeance: etat, seanceEnLectureSeule: lectureSeule }
    }

    const attentes = creerLesAttentesDeRejeu()
    const entree = SyncService.entreeStockee(queueId)
    const enFile = entree !== undefined && entree.aAnnuler === undefined

    const surUneAnnonce = (event) => {
        const detail = event.detail ?? {}

        if (etat.value === ETAT_EN_ATTENTE) {
            if (event.type === 'sync:failed' && detail.queueId === queueId) {
                etat.value = etatDuRefus(detail.raison)
            }

            if (event.type === 'sync:retired' && (detail.queueIds ?? []).includes(queueId)) {
                etat.value = ETAT_NON_ENREGISTREE
            }
        }

        planifierLAdoption()
    }

    /** Rouverte par l'historique, sa création partie : ce que la page en sait. */
    const ouvrirUneEntreePerimee = () => {
        const refus = SyncService.failedRequests().find((refusee) => refusee?.id === queueId)

        if (refus !== undefined) {
            etat.value = etatDuRefus(refus.raison)

            return
        }

        const realWorkoutId = SyncService.produitDe(queueId)
        const aRetrouver = () => (etat.value = ETAT_A_RETROUVER)

        if (realWorkoutId !== null) {
            visiter(route('workouts.show', { workout: realWorkoutId }), aRetrouver)

            return
        }

        const adresseDeLaCle = adresseDUneSeanceEnAttente(seance.idempotency_key ?? cleDeLAdresseEnAttente(adresse))

        if (joignable.value && adresseDeLaCle !== null) {
            visiter(adresseDeLaCle, aRetrouver)

            return
        }

        aRetrouver()
    }

    if (enFile) {
        etat.value = ETAT_EN_ATTENTE
        pendingIds.noterEnFile(idProvisoire, queueId)
        pendingIds.track(
            idProvisoire,
            attentes.attendre(queueId).then((issue) => issue?.data?.id ?? null),
        )
    } else {
        // Plus en file : ses écritures ne nomment ni une entrée qui ne viendra plus, ni un identifiant provisoire.
        pendingIds.track(idProvisoire, Promise.resolve(SyncService.produitDe(queueId)))
    }

    if (getCurrentInstance()) {
        onMounted(() => {
            /*
             * Au montage, pas avant : ce qui occupe la personne se lit sur des
             * états que la page déclare après cet appel.
             */
            arreterLeSuivi = watch(
                () => occupeeOuEnSaisie(),
                (estOccupee) => {
                    if (!estOccupee) {
                        planifierLAdoption()
                    }
                },
            )
            ANNONCES_DE_LA_FILE.forEach((annonce) => window.addEventListener(annonce, surUneAnnonce))
            document.addEventListener('focusout', planifierLAdoption)

            if (enFile) {
                planifierLAdoption()
            } else {
                ouvrirUneEntreePerimee()
            }
        })

        onUnmounted(() => {
            enPlace = false
            arreterLeSuivi()
            clearTimeout(essaiPrevu)
            essaiPrevu = null
            attentes.oublierTout()
            ANNONCES_DE_LA_FILE.forEach((annonce) => window.removeEventListener(annonce, surUneAnnonce))
            document.removeEventListener('focusout', planifierLAdoption)
        })
    }

    return { seanceProvisoire: provisoire, etatDeLaSeance: etat, seanceEnLectureSeule: lectureSeule }
}
