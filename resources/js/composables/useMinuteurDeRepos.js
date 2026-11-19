import { computed, getCurrentInstance, onUnmounted, ref } from 'vue'
import { router, usePage } from '@inertiajs/vue3'
import { useVerrouDEcran } from '@/composables/useVerrouDEcran'
import { direSansReseau } from '@/Utils/visitesHorsLigne'
import { revenirSiLaVisiteEchoue } from '@/Utils/miseAJourOptimiste'
import { deposerLeRepos, reprendreLeRepos } from '@/Utils/reposEnCours'

/**
 * Ce que dit un réglage du repos automatique resté sans réponse (#2048). Pas
 * « rien n'a changé » : la requête a pu arriver, et seule sa réponse se perdre.
 */
export const REPOS_AUTOMATIQUE_SANS_RESEAU =
    "Pas de réponse du serveur : le changement du repos automatique n'est pas confirmé."

/**
 * La mémoire d'une page de séance (`memoire` de `useMinuteurDeRepos`) : lue au
 * montage, parce qu'au démontage la page de la visite qui part a déjà pris la
 * place de celle-ci (`page.url` a changé), et qu'une séance close ne laisse
 * rien.
 *
 * @param {{ page: { url: string, props: object }, localWorkout: import('vue').Ref<object>, estClose: import('vue').Ref<boolean> }} options
 */
export const memoireDeLaPage = ({ page, localWorkout, estClose }) => {
    const adresse = page.url

    return {
        adresse,
        compte: page.props.auth?.user?.id ?? null,
        intitule: () => localWorkout.value.name || 'Séance',
        depart: () => page.url !== adresse && !estClose.value,
    }
}

/**
 * Le minuteur de repos de la seance : son etat, son reglage, et ce qui le lance.
 *
 * `sortieAudio`, obligatoire, est la sortie où sonne la fin de chaque repos
 * (#2112) : celle de la page (`useSortieAudio`), qui la passe aussi au minuteur
 * (`sortie-audio`) et la rend en partant. Le minuteur, remonté à chaque repos
 * et démonté à sa fin, ne peut pas la tenir : la fermer avec lui couperait le
 * bip qu'il vient de lancer. Le composable ne l'ouvre pas non plus à la place
 * de la page : il ne la rendrait pas, le minuteur en ouvrirait une autre, que
 * le geste du départ n'aurait pas préparée, et la fin naturelle se tairait.
 * Sans elle, il lève dès sa création, plutôt que dans le geste d'une série.
 *
 * `memoire` (#2434), facultative, fait survivre le repos à la page : à son
 * départ, la page dépose l'ÉCHÉANCE du repos ouvert (`Utils/reposEnCours.js`),
 * jamais un compte à rebours en mémoire de page, et le montage suivant de la
 * même séance la reprend là où elle en est. Sans elle, rien ne survit.
 *
 * @param {{
 *   sortieAudio: ReturnType<typeof import('@/composables/useSortieAudio').useSortieAudio>,
 *   memoire?: { adresse: string, compte: number|string|null, intitule: () => string, depart: () => boolean },
 * }} options `memoire.depart` dit que la page s'en va pour une autre et laisse sa séance ouverte
 * @throws {TypeError} sans la sortie de la page
 */
export const useMinuteurDeRepos = ({ sortieAudio, memoire = null } = {}) => {
    if (typeof sortieAudio?.preparer !== 'function') {
        throw new TypeError(
            'useMinuteurDeRepos attend la sortie audio de la page (useSortieAudio), qu’elle passe aussi au minuteur.',
        )
    }

    const verrouDEcran = useVerrouDEcran()

    const carteOuverte = ref(false)

    /** Fermer la carte (`showTimer = false`) rend le verrou d'écran : l'écran peut s'éteindre de nouveau. */
    const showTimer = computed({
        get: () => carteOuverte.value,
        set: (ouverte) => {
            carteOuverte.value = ouverte

            if (!ouverte) {
                verrouDEcran.relacher()
            }
        },
    })
    const timerDuration = ref(90)

    /**
     * Le reglage est tenu localement pour que l'interrupteur bouge tout de suite,
     * puis ecrit. La page reste sur place : basculer un reglage ne doit pas sortir
     * de la seance en cours.
     */
    const autoRestTimer = ref(usePage().props.auth.user.auto_rest_timer !== false)

    /**
     * Le repos, demande explicitement.
     *
     * Sans lui, couper le demarrage automatique fermait une porte a sens unique :
     * plus rien n'ouvrait le minuteur, et l'interrupteur qui le rallume vit DANS le
     * minuteur. Le reglage etait donc irreversible depuis l'interface.
     *
     * Appelé dans le geste qui lance le repos (une série validée, « Démarrer un
     * repos »), avant tout `await` : c'est là que la sortie s'ouvre ou reprend
     * (#2112). Le minuteur ne se monte qu'au rendu suivant, hors du geste, et sa
     * fin tombe dans le rappel d'une minuterie, où WebKit ne démarre aucune
     * sortie.
     */
    const demarrerLeRepos = (duree) => {
        sortieAudio.preparer()
        verrouDEcran.garder()
        timerDuration.value = duree || usePage().props.auth.user.default_rest_time || 90
        timerRun.value += 1
        restantInitial.value = null
        demarrageEnPause.value = false
        echeance = Date.now() + timerDuration.value * 1000
        restantEnPause = null
        carteOuverte.value = true
    }

    const openRestTimer = () => demarrerLeRepos()

    /**
     * Le repos que la page avait laissé en partant (`Utils/reposEnCours.js`,
     * #2434), repris là où il en est : l'échéance a couru pendant l'absence.
     * Pas un geste : la sortie audio ne s'ouvre pas ici, et un repos qui a fini
     * dans l'intervalle ne se reprend pas.
     *
     * @param {{ duree: number, restant: number, enPause: boolean }} repos
     */
    const reprendreUnRepos = ({ duree, restant, enPause }) => {
        verrouDEcran.garder()
        timerDuration.value = duree
        timerRun.value += 1
        restantInitial.value = restant
        demarrageEnPause.value = enPause
        echeance = enPause ? null : Date.now() + restant * 1000
        restantEnPause = enPause ? restant : null
        carteOuverte.value = true
    }

    /** La pause du minuteur, avec ce qu'il lui reste : l'échéance se fige. */
    const noterLaPause = (restant) => {
        echeance = null
        restantEnPause = restant
    }

    /** La reprise après une pause : une nouvelle échéance. */
    const noterLaReprise = (restant) => {
        echeance = Date.now() + restant * 1000
        restantEnPause = null
    }

    /**
     * Ce que le repos ouvert tient à déposer quand la page part, ou null sans
     * repos ouvert ni reste. L'échéance, jamais un compte à rebours.
     *
     * @returns {{ duree: number, echeance: number|null, restantEnPause: number|null }|null}
     */
    const instantane = () => {
        if (!carteOuverte.value || (echeance === null && restantEnPause === null)) {
            return null
        }

        return { duree: timerDuration.value, echeance, restantEnPause }
    }

    /** Une serie validee lance le repos, si l'utilisateur le veut automatique. */
    const apresValidation = (exerciseRestTime) => {
        if (!autoRestTimer.value) return

        demarrerLeRepos(exerciseRestTime)
    }

    /** Le numéro du dernier réglage envoyé : seul son échec ramène l'interrupteur. */
    let dernierEnvoi = 0

    /**
     * L'interrupteur revient au réglage que le serveur tient (celui de la page)
     * sur tout échec du dernier envoi : refus, réseau absent, réponse en
     * erreur, visite annulée ou interrompue (#2077). Il ne revenait que sans
     * réseau (#2048) : un 419 ou un 500 le laissaient changé à l'écran, et la
     * séance suivante repartait sur l'ancien réglage sans que rien ne l'ait dit.
     * Sans réseau, un message le dit, comme avant.
     */
    const setAutoRestTimer = (valeur) => {
        const envoi = ++dernierEnvoi
        autoRestTimer.value = valeur

        router.patch(
            route('profile.rest-timer.update'),
            { auto_rest_timer: valeur },
            revenirSiLaVisiteEchoue(
                () => {
                    if (envoi === dernierEnvoi) {
                        autoRestTimer.value = usePage().props.auth.user.auto_rest_timer !== false
                    }
                },
                {
                    preserveScroll: true,
                    preserveState: true,
                    onNetworkError: direSansReseau(REPOS_AUTOMATIQUE_SANS_RESEAU),
                },
            ),
        )
    }

    /**
     * Où en est le repos ouvert : l'instant où il finit (ms), ou son reste en
     * pause. Seuls `instantane()` et la page qui part les lisent.
     */
    let echeance = null
    let restantEnPause = null

    /** Un repos repris en route : ce qu'il reste au minuteur remonté, et s'il attend en pause. */
    const restantInitial = ref(null)
    const demarrageEnPause = ref(false)

    /**
     * Counts rest periods, so each one gets a fresh timer.
     *
     * The timer only reset itself while it was NOT running, and completing a set
     * while it was already counting down neither remounted it nor restarted it. The
     * second set of a superset was therefore given whatever was left of the first
     * one's rest — the shorter the gap between sets, the shorter the rest, which is
     * precisely backwards.
     */
    const timerRun = ref(0)

    /**
     * Tout ce que la page lie au minuteur (`v-bind` sur `RestTimer`) : sa clé,
     * sa durée, le réglage et ce qu'elle en écoute, et, d'un repos repris en
     * route, ce qu'il reste et la pause.
     */
    const liaisonDuMinuteur = computed(() => ({
        key: timerRun.value,
        duration: timerDuration.value,
        autoRestTimer: autoRestTimer.value,
        'onUpdate:autoRestTimer': setAutoRestTimer,
        secondesRestantes: restantInitial.value,
        enPause: demarrageEnPause.value,
        onPause: noterLaPause,
        onReprise: noterLaReprise,
    }))

    if (memoire !== null) {
        const repris = reprendreLeRepos({ compte: memoire.compte, adresse: memoire.adresse })

        if (repris !== null) {
            reprendreUnRepos(repris)
        }

        if (getCurrentInstance()) {
            onUnmounted(() => {
                const repos = memoire.depart() ? instantane() : null

                if (repos !== null) {
                    deposerLeRepos({
                        ...repos,
                        adresse: memoire.adresse,
                        intitule: memoire.intitule(),
                        compte: memoire.compte,
                    })
                }
            })
        }
    }

    return {
        showTimer,
        timerDuration,
        autoRestTimer,
        timerRun,
        liaisonDuMinuteur,
        openRestTimer,
        reprendreUnRepos,
        instantane,
        setAutoRestTimer,
        apresValidation,
    }
}
