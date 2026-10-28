import { ref } from 'vue'
import { router, usePage } from '@inertiajs/vue3'
import { direSansReseau } from '@/Utils/visitesHorsLigne'
import { revenirSiLaVisiteEchoue } from '@/Utils/miseAJourOptimiste'
import { useSortieAudio } from '@/composables/useSortieAudio'

/**
 * Ce que dit un réglage du repos automatique resté sans réponse (#2048). Pas
 * « rien n'a changé » : la requête a pu arriver, et seule sa réponse se perdre.
 */
export const REPOS_AUTOMATIQUE_SANS_RESEAU =
    "Pas de réponse du serveur : le changement du repos automatique n'est pas confirmé."

/**
 * Le minuteur de repos de la seance : son etat, son reglage, et ce qui le lance.
 *
 * `sortieAudio` est la sortie où sonne la fin de chaque repos (#2112) : celle
 * de la page (`useSortieAudio`), qui la passe aussi au minuteur et la rend en
 * partant. Le minuteur, remonté à chaque repos et démonté à sa fin, ne peut pas
 * la tenir : la fermer avec lui couperait le bip qu'il vient de lancer. Sans
 * elle, le composable ouvre la sienne.
 *
 * @param {{ sortieAudio?: ReturnType<typeof useSortieAudio> }} [options]
 */
export const useMinuteurDeRepos = ({ sortieAudio = useSortieAudio() } = {}) => {
    const showTimer = ref(false)
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
        timerDuration.value = duree || usePage().props.auth.user.default_rest_time || 90
        timerRun.value += 1
        showTimer.value = true
    }

    const openRestTimer = () => demarrerLeRepos()

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
     * Counts rest periods, so each one gets a fresh timer.
     *
     * The timer only reset itself while it was NOT running, and completing a set
     * while it was already counting down neither remounted it nor restarted it. The
     * second set of a superset was therefore given whatever was left of the first
     * one's rest — the shorter the gap between sets, the shorter the rest, which is
     * precisely backwards.
     */
    const timerRun = ref(0)

    return { showTimer, timerDuration, autoRestTimer, timerRun, openRestTimer, setAutoRestTimer, apresValidation }
}
