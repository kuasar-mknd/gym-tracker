import { getCurrentInstance, onMounted, onUnmounted, watch } from 'vue'
import SyncService from '@/Utils/SyncService'
import { ecritureDesCopiesInstallee } from '@/Utils/ecritureDesCopies'
import { isTemporaryId } from '@/Utils/pendingIds'
import { seanceConnueDuServeur } from '@/Utils/seanceEtFile'

/**
 * L'attente après le dernier changement de la séance avant d'en prendre
 * l'instantané : une seconde, celle de l'envoi d'une valeur tapée.
 */
export const DELAI_DE_L_INSTANTANE_MS = 1000

/** Le composant de la page de séance, le seul dont ce module garde la copie. */
const COMPOSANT = 'Workouts/Show'

/**
 * Une copie stable d'une valeur, ses clés triées : deux séances égales,
 * rangées dans un autre ordre, s'y écrivent pareil.
 *
 * @param {unknown} valeur
 * @returns {string}
 */
const enTexteStable = (valeur) =>
    JSON.stringify(valeur, (_cle, champ) =>
        champ !== null && typeof champ === 'object' && !Array.isArray(champ)
            ? Object.fromEntries(Object.entries(champ).sort(([premiere], [seconde]) => (premiere < seconde ? -1 : 1)))
            : champ,
    )

/**
 * Si la séance porte une rangée que le serveur n'a pas encore nommée : sa
 * création vole, ou attend en file.
 *
 * @param {object | null | undefined} seance
 */
const aDesRangeesProvisoires = (seance) =>
    (seance?.workout_lines ?? []).some(
        (ligne) => isTemporaryId(ligne?.id) || (ligne?.sets ?? []).some((serie) => isTemporaryId(serie?.id)),
    )

/**
 * La copie hors ligne de la séance ouverte (#2048, partie D, section « La
 * séance » de l'issue).
 *
 * La page de séance s'écrit par l'API, sans visite : la copie que la page
 * garde de sa réponse (`ecritureDesCopies.js`) n'a rien de ce que la page a
 * fait accepter depuis. Elle y verse donc un instantané de sa copie locale,
 * la prop `workout` seule, une réponse partielle pour le magasin : jamais
 * `auth`, jamais de copie créée.
 *
 * **Seulement quand tout est enregistré** : rien n'attend en file
 * (`SyncService.enAttente()`), aucune écriture directe ne vole
 * (`ecrituresEnCours()`), aucune série n'est marquée non synchronisée
 * (`unsyncedSetIds`), aucune rangée ne porte d'identifiant provisoire, aucun
 * ordre n'est en vol (`ordreEnVol`), et aucune valeur n'attend son envoi ou
 * sa réponse (`valeursDeRepli`). L'instantané est alors ce que le serveur
 * connaît (`seanceConnueDuServeur`). Une valeur qu'il pourrait refuser n'y
 * entre jamais ; ce qui attend en file revient par la file. Sur la vraie
 * page, un ordre en vol et une rangée provisoire vont avec une écriture que
 * le service compte aussi (en vol, ou en file) : ces deux conditions, que
 * l'issue impose, gardent l'instantané si un jour elles s'en séparent, et
 * `useCopieDeLaSeance.test.js` les vérifie chacune seule.
 *
 * **Quand.** Une seconde après le dernier changement (anti-rebond), et
 * aussitôt quand la page se cache (`visibilitychange` vers `hidden`) ou part
 * (`pagehide`) : verrouiller un iPhone suspend la page avant la fin de
 * l'anti-rebond, et la file des copies passe alors sans attendre le repos
 * (`presser`). Au démontage aussi, quand un instantané attendait. Une
 * écriture directe encore en vol relance l'attente ; une file qui attend se
 * réveille sur les annonces du vidage (`sync:replayed`, `sync:failed`,
 * `sync:retired`).
 *
 * **D'une séance venue du serveur.** Rendue par l'historique de l'onglet
 * (boutons Retour et Avance), la séance de départ est celle d'une visite, qui
 * peut être plus ancienne que la copie : une visite antérieure de la même
 * séance n'a pas ce qu'une visite plus récente a fait enregistrer. Son
 * instantané effacerait de la copie ce qui est enregistré. Il n'en est pris
 * que si la séance de départ est exactement celle de la copie, ou après une
 * réponse du serveur qui la rafraîchit (le rechargement d'après le vidage).
 * Une visite côté client, ou une page qui porte la marque d'une copie
 * (`props.horsLigne`, parties E et F), n'en prend jamais.
 *
 * Les écritures directes de la séance que le serveur accepte se notent
 * aussitôt (`Utils/ecrituresAcceptees.js`) : la page dit au service de
 * synchronisation quelle séance elle montre
 * (`suivreLesEcrituresDeLaSeance`), et une copie qui n'aurait pas eu le temps
 * de les prendre pourra le dire.
 *
 * Sans écriture installée (`main.js` l'installe ; un test qui ne l'installe
 * pas), rien d'autre que la séance suivie.
 *
 * @param {{
 *   localWorkout: import('vue').Ref<object>,
 *   seanceDeDepart: object,
 *   seance: () => object,
 *   page: object,
 *   valeursDeRepli: () => Array<unknown>,
 *   unsyncedSetIds: import('vue').Ref<Set<string>>,
 *   ordreEnVol: import('vue').Ref<number>,
 *   ecriture?: ReturnType<typeof ecritureDesCopiesInstallee>,
 *   fenetre?: Window,
 * }} seanceOuverte la copie locale et la séance d'où elle part (useSeanceEtSaFile),
 *   la séance des props, la page d'Inertia (`usePage()`), et ce qui dit
 *   qu'une écriture attend
 */
export const useCopieDeLaSeance = ({
    localWorkout,
    seanceDeDepart,
    seance,
    page,
    valeursDeRepli,
    unsyncedSetIds,
    ordreEnVol,
    ecriture = ecritureDesCopiesInstallee(),
    fenetre = window,
}) => {
    SyncService.suivreLesEcrituresDeLaSeance?.(localWorkout.value?.id)

    // Une page qui porte la marque d'une copie (partie E) n'est pas la séance du serveur : rien ne s'en verse.
    if (
        ecriture === null ||
        page?.component !== COMPOSANT ||
        page.props?.horsLigne !== undefined ||
        !getCurrentInstance()
    ) {
        return
    }

    /*
     * La page de la séance telle qu'à l'ouverture : au démontage, Inertia
     * tient déjà la page suivante. Le compte ne sert qu'aux vérifications du
     * magasin, qui ne remplace jamais `auth` d'une copie.
     */
    const modele = {
        component: page.component,
        url: page.url,
        version: page.version ?? null,
        encryptHistory: page.encryptHistory === true,
        compte: page.props?.auth?.user?.id ?? null,
    }
    let aJour = ecriture.origineDeLaPage() === 'reseau'
    let derniere = aJour ? enTexteStable(seanceConnueDuServeur(localWorkout.value)) : null
    let minuterie = null
    let enPlace = true

    const toutEstEnregistre = () =>
        SyncService.enAttente() === 0 &&
        SyncService.ecrituresEnCours() === 0 &&
        unsyncedSetIds.value.size === 0 &&
        ordreEnVol.value === 0 &&
        valeursDeRepli().length === 0 &&
        !aDesRangeesProvisoires(localWorkout.value)

    const annulerLAttente = () => {
        clearTimeout(minuterie)
        minuterie = null
    }

    /** L'instantané dans une seconde, sauf changement d'ici là. */
    const planifier = () => {
        annulerLAttente()
        minuterie = setTimeout(() => {
            minuterie = null
            prendre()
        }, DELAI_DE_L_INSTANTANE_MS)
    }

    /**
     * Verse l'instantané dans la copie, si tout est enregistré et qu'il a
     * changé depuis le dernier versé.
     *
     * @param {{ toutDeSuite?: boolean }} [options] sans attendre le repos
     */
    const prendre = ({ toutDeSuite = false } = {}) => {
        if (!aJour) {
            return
        }

        if (!toutEstEnregistre()) {
            // Une écriture directe en vol ne change pas toujours l'écran à sa réponse : on revient voir.
            if (enPlace && SyncService.ecrituresEnCours() > 0) {
                planifier()
            }

            return
        }

        const instantane = seanceConnueDuServeur(localWorkout.value)
        const empreinte = enTexteStable(instantane)

        if (empreinte !== derniere) {
            derniere = empreinte
            ecriture.completer(
                {
                    component: modele.component,
                    url: modele.url,
                    version: modele.version,
                    encryptHistory: modele.encryptHistory,
                    props: { auth: { user: { id: modele.compte } }, workout: instantane },
                },
                ['workout'],
            )
        }

        if (toutDeSuite) {
            ecriture.presser()
        }
    }

    /** La page se cache ou part : l'instantané tout de suite. */
    const surLaPageQuiSeCache = () => {
        if (fenetre.document?.visibilityState === 'hidden') {
            annulerLAttente()
            prendre({ toutDeSuite: true })
        }
    }

    const surLaPageQuiPart = () => {
        annulerLAttente()
        prendre({ toutDeSuite: true })
    }

    const surUneAnnonceDuVidage = () => planifier()

    /*
     * Une séance rendue par l'historique n'est à jour que si elle est
     * exactement celle de la copie : la copie se lit, sans rien servir.
     */
    if (!aJour && ecriture.origineDeLaPage() === 'historique') {
        const depart = enTexteStable(seanceConnueDuServeur(seanceDeDepart))

        ecriture
            .lireLaCopie(modele.url)
            .then((copie) => {
                const gardee = copie?.page?.props?.workout

                if (
                    enPlace &&
                    !aJour &&
                    gardee !== undefined &&
                    enTexteStable(seanceConnueDuServeur(gardee)) === depart
                ) {
                    aJour = true
                    derniere = depart
                    planifier()
                }
            })
            .catch(() => {})
    }

    // Une réponse du serveur rafraîchit la séance ; l'historique, non.
    watch(seance, () => {
        aJour = ecriture.origineDeLaPage() === 'reseau'
    })

    watch(localWorkout, planifier, { deep: true })
    watch(() => [unsyncedSetIds.value.size, ordreEnVol.value, valeursDeRepli().length], planifier)

    onMounted(() => {
        fenetre.document?.addEventListener('visibilitychange', surLaPageQuiSeCache)
        fenetre.addEventListener('pagehide', surLaPageQuiPart)

        for (const annonce of ['sync:replayed', 'sync:failed', 'sync:retired']) {
            fenetre.addEventListener(annonce, surUneAnnonceDuVidage)
        }
    })

    onUnmounted(() => {
        enPlace = false
        fenetre.document?.removeEventListener('visibilitychange', surLaPageQuiSeCache)
        fenetre.removeEventListener('pagehide', surLaPageQuiPart)

        for (const annonce of ['sync:replayed', 'sync:failed', 'sync:retired']) {
            fenetre.removeEventListener(annonce, surUneAnnonceDuVidage)
        }

        // Un instantané attendait : la page part, il passe maintenant, si tout est enregistré.
        if (minuterie !== null) {
            annulerLAttente()
            prendre()
        }
    })
}
