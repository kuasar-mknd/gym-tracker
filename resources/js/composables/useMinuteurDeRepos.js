import { ref } from 'vue'
import { router, usePage } from '@inertiajs/vue3'

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

    const setAutoRestTimer = (valeur) => {
        autoRestTimer.value = valeur

        router.patch(
            route('profile.rest-timer.update'),
            { auto_rest_timer: valeur },
            { preserveScroll: true, preserveState: true },
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
