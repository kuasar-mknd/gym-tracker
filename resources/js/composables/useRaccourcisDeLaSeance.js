import { avecCommande, useRaccourciClavier } from '@/composables/useRaccourciClavier'

/**
 * Le clavier pendant une séance.
 *
 * Le geste au doigt est le bouton de la carte ; au clavier, il fallait tabuler
 * jusqu'à lui à travers tous les champs de la séance (#1817). La commande
 * ajoute au DERNIER exercice, celui qu'on est en train de faire.
 *
 * @param {{ localWorkout: import('vue').Ref, isFinished: import('vue').Ref, addSet: (id: number) => void }} outils
 */
export const useRaccourcisDeLaSeance = ({ localWorkout, isFinished, addSet }) => {
    const ajouterUneSerieAuDernierExercice = () => {
        const lignes = localWorkout.value?.workout_lines ?? []
        const derniere = lignes[lignes.length - 1]

        if (derniere && !isFinished.value) {
            addSet(derniere.id)
        }
    }

    useRaccourciClavier(
        (evenement) => avecCommande(evenement) && evenement.key === 'Enter',
        ajouterUneSerieAuDernierExercice,
    )

    return { ajouterUneSerieAuDernierExercice }
}
