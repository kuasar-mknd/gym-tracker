import { computed, ref, toValue, watch } from 'vue'
import SyncService from '@/Utils/SyncService'
import { appareilDonneAUnAutreCompte, pushPrisEnCharge, useAbonnementPush } from '@/composables/useAbonnementPush'

/**
 * L'invitation à activer les notifications, au retour d'une séance (#1848).
 *
 * Rien ne proposait jamais d'activer les notifications : le seul chemin était
 * un bandeau du profil, à trois navigations de l'accueil, et un compte pouvait
 * vivre sans que l'autorisation soit demandée une seule fois. La fin d'une
 * séance est le moment où elle a un sens — un record à annoncer — plutôt
 * qu'une demande à froid au premier écran.
 *
 * Le serveur dit si le COMPTE est à inviter : il n'envoie la donnée flash
 * `proposerLesNotifications` qu'à la fin d'une séance, et seulement quand il ne
 * tient aucun abonnement et que les records ne sont pas coupés
 * (WorkoutController). Ce composable juge ce que seul l'APPAREIL sait : le push
 * pris en charge, la permission, l'abonnement tenu et à qui il a été donné, le
 * refus mémorisé.
 */

/**
 * Le refus vaut pour l'appareil, comme l'abonnement qu'il refuse : un autre
 * téléphone du même compte sera invité à son tour.
 */
const CLEF_REFUS = 'gym-tracker:invitation-notifications-refusee'

/**
 * Les envois que l'activation allume : les records seuls. Les rappels
 * d'entraînement restent à activer dans le profil, avec leurs jours.
 */
const TYPES_ACTIVES = ['personal_record']

/** `fetch` n'a pas de délai maximum : passé celui-ci, on le dit. */
const DELAI_D_ECRITURE_MS = 20_000

const aDejaRefuse = () => {
    try {
        return window.localStorage.getItem(CLEF_REFUS) !== null
    } catch {
        // Navigation privée, stockage bloqué : l'invitation reste possible.
        return false
    }
}

const memoriserLeRefus = () => {
    try {
        window.localStorage.setItem(CLEF_REFUS, '1')
    } catch {
        // Rien à faire : elle reviendra à une autre fin de séance, pas plus.
    }
}

/**
 * @param {{
 *   vapidPublicKey: string|null|undefined,
 *   utilisateurId: number|string|null|undefined,
 *   uneAutreInvitationPasseAvant: import('vue').MaybeRefOrGetter<boolean>,
 * }} page `uneAutreInvitationPasseAvant` : l'invitation d'installation est à
 *   l'écran. Une seule carte à la fois ; elle passe d'abord, et celle-ci attend
 *   une autre fin de séance.
 */
export const useInvitationAuxNotifications = ({ vapidPublicKey, utilisateurId, uneAutreInvitationPasseAvant }) => {
    /*
     * Ce qui se sait sans attendre le navigateur. Une permission déjà refusée
     * ne peut plus être redemandée par la page : elle vaut refus définitif. Un
     * appareil donné à un autre compte ne change de mains que par l'activation
     * du profil (.ai/rules/js.md) : la carte n'y invite pas d'un seul appui.
     */
    const proposable =
        pushPrisEnCharge() &&
        Boolean(vapidPublicKey) &&
        window.Notification.permission !== 'denied' &&
        !aDejaRefuse() &&
        !appareilDonneAUnAutreCompte(utilisateurId)

    /*
     * L'abonnement passe par le chemin du profil, mémo compris. `dejaAbonne`
     * vaut faux parce que le serveur vient de dire, par la donnée flash, qu'il
     * ne tient rien pour ce compte. Inutile d'interroger le worker pour une
     * carte qui ne se montrera pas.
     */
    const push = proposable
        ? useAbonnementPush({ vapidPublicKey, dejaAbonne: false, utilisateurId, apresAbonnement: () => {} })
        : null

    const enCours = ref(false)
    const ecritureEnCours = ref(false)
    const erreur = ref(null)
    const activee = ref(false)
    const fermee = ref(false)

    /** L'utilisateur a appuyé sur « Activer » : la carte lui doit le résultat. */
    const engagee = ref(false)

    /** L'invitation d'installation s'est montrée pendant cette visite. */
    const cedee = ref(false)

    watch(
        () => toValue(uneAutreInvitationPasseAvant),
        (autreALEcran) => {
            if (autreALEcran && !engagee.value) {
                cedee.value = true
            }
        },
        { immediate: true },
    )

    const visible = computed(() => {
        if (push === null || fermee.value) {
            return false
        }

        if (engagee.value) {
            return true
        }

        // Pas avant la réponse du navigateur : une carte qui apparaît puis
        // disparaît aussitôt se lit comme un défaut. Un appareil qui tient déjà
        // un abonnement transmis pour ce compte n'a rien à activer.
        return !cedee.value && push.appareilVerifie.value && !push.pushRegistered.value
    })

    const etapeEnCours = computed(() => (ecritureEnCours.value ? 'Préférences' : (push?.etapeEnCours.value ?? null)))

    const refuser = () => {
        memoriserLeRefus()
        fermee.value = true
    }

    const fermer = () => {
        fermee.value = true
    }

    /**
     * Par la file hors ligne, comme les préférences du profil : l'appareil est
     * déjà abonné à ce moment, et une écriture perdue le laisserait abonné sans
     * rien d'allumé, sans que la carte revienne jamais, puisque le serveur
     * tient désormais un abonnement. Un réseau qui lâche met l'écriture en
     * file, rejouée au retour du réseau ; un refus du serveur garde la carte.
     */
    const allumerLesRecords = async () => {
        ecritureEnCours.value = true

        try {
            await SyncService.patch(
                route('profile.push-preferences.update'),
                { types: TYPES_ACTIVES },
                { timeout: DELAI_D_ECRITURE_MS },
            )
            activee.value = true
        } catch (echec) {
            if (echec?.isOffline) {
                activee.value = true

                return
            }

            erreur.value = 'Tes notifications de records n’ont pas pu être activées. Réessaie.'
        } finally {
            ecritureEnCours.value = false
        }
    }

    /**
     * Permission, abonnement, puis l'envoi push des records côté serveur.
     *
     * Rien n'est attendu avant `enablePush` : il demande la permission tout de
     * suite, et iOS n'ouvre l'invite que dans le geste de l'utilisateur. Une
     * invite écartée ou refusée vaut refus mémorisé, sans quoi la carte
     * reviendrait à chaque séance pour une question déjà tranchée. Une panne
     * garde la carte et dit l'étape.
     *
     * Une écriture des préférences qui échoue laisse l'appareil abonné sans
     * rien d'allumé : le second appui ne refait alors que cette écriture.
     */
    const activer = async () => {
        if (push === null || enCours.value) {
            return
        }

        enCours.value = true
        engagee.value = true
        erreur.value = null

        try {
            if (!push.pushRegistered.value) {
                const issue = await push.enablePush()

                if (issue === 'refuse') {
                    memoriserLeRefus()
                    fermee.value = true

                    return
                }

                if (issue !== 'abonne') {
                    erreur.value = push.pushError.value

                    return
                }
            }

            await allumerLesRecords()
        } finally {
            enCours.value = false
        }
    }

    return { visible, activee, enCours, etapeEnCours, erreur, activer, refuser, fermer }
}
