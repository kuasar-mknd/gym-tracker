import { ref } from 'vue'
import { router, usePage } from '@inertiajs/vue3'
import { direSansReseau } from '@/Utils/visitesHorsLigne'

/**
 * Ce que dit un réglage du repos automatique resté sans réponse (#2048). Pas
 * « rien n'a changé » : la requête a pu arriver, et seule sa réponse se perdre.
 */
export const REPOS_AUTOMATIQUE_SANS_RESEAU =
    "Pas de réponse du serveur : le changement du repos automatique n'est pas confirmé."

/** Le minuteur de repos de la seance : son etat, son reglage, et ce qui le lance. */
export const useMinuteurDeRepos = () => {
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
     */
    const demarrerLeRepos = (duree) => {
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

    /**
     * Sans réseau, l'interrupteur revient où il était, et un message le dit
     * (#2048) : il ne changeait qu'à l'écran, et la séance suivante repartait
     * sur l'ancien réglage sans que rien ne l'ait dit.
     */
    const setAutoRestTimer = (valeur) => {
        const precedent = autoRestTimer.value
        autoRestTimer.value = valeur

        router.patch(
            route('profile.rest-timer.update'),
            { auto_rest_timer: valeur },
            {
                preserveScroll: true,
                preserveState: true,
                onNetworkError: direSansReseau(REPOS_AUTOMATIQUE_SANS_RESEAU, () => {
                    autoRestTimer.value = precedent
                }),
            },
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
