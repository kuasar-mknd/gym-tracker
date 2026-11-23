import { computed, ref } from 'vue'

/**
 * Ce que dit la carte « Repos » de la série qui suit : « Ensuite, série 3 sur 4 »,
 * ou « Exercice terminé » quand celle qui vient d'être validée était la dernière.
 * Le repos part de la série validée, qu'`apresLaValidation` retient avant de le lancer.
 *
 * @param {{
 *   localWorkout: import('vue').Ref<object>,
 *   apresValidation: (exerciseRestTime: number|undefined) => void,
 * }} page
 */
export const useEnsuiteDuRepos = ({ localWorkout, apresValidation }) => {
    const serieDuRepos = ref(null)

    const apresLaValidation = (exerciseRestTime, set) => {
        serieDuRepos.value = set
        apresValidation(exerciseRestTime)
    }

    const ensuiteDuRepos = computed(() => {
        const ligne = localWorkout.value.workout_lines.find((l) => l.sets?.includes(serieDuRepos.value))

        if (!ligne) {
            return ''
        }

        const rang = ligne.sets.indexOf(serieDuRepos.value)
        const suivante = ligne.sets.findIndex((serie, i) => i > rang && !serie.is_completed)

        return suivante === -1 ? 'Exercice terminé' : `Ensuite, série ${suivante + 1} sur ${ligne.sets.length}`
    })

    return { apresLaValidation, ensuiteDuRepos }
}
