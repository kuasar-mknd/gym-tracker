import { computed, ref } from 'vue'

/**
 * Retenir ce qu'on s'apprête à supprimer, le temps de poser la question.
 *
 * Quatorze écrans appelaient `confirm()` et n'avaient donc rien à retenir : la
 * boîte native bloque le fil d'exécution, et la réponse revient dans la même
 * expression. Un dialogue de l'application ne bloque rien — il faut garder la
 * cible quelque part entre la question et la réponse.
 *
 * Sans ce composable, ce « quelque part » serait un `ref`, deux gestionnaires et
 * un `computed` recopiés dans treize fichiers. On sait ce que devient une chose
 * recopiée treize fois : on l'a passé la journée à le défaire.
 *
 * @param {(cible: unknown, termine: () => void) => void} supprimer
 *        Ce qu'il faut faire une fois la question tranchée. Le second argument
 *        referme le dialogue : appelez-le dans `onFinish`, pas dans
 *        `onSuccess` — un échec doit rendre l'écran à l'utilisateur, sinon une
 *        coupure réseau laisse le dialogue ouvert sur un bouton qui tourne.
 */
export function useConfirmation(supprimer) {
    /** La cible en attente, ou `null` quand aucune question n'est posée. */
    const cible = ref(null)

    const ouvert = computed(() => cible.value !== null)

    const demander = (element) => {
        cible.value = element
    }

    const annuler = () => {
        cible.value = null
    }

    const confirmer = () => {
        const element = cible.value

        /*
         * Un second appui pendant que la requête part ne doit rien relancer.
         * Le bouton du dialogue se verrouille déjà par `:loading`, mais ce
         * garde-ci tient même si un appelant oublie de le passer.
         */
        if (element === null) {
            return
        }

        supprimer(element, () => {
            cible.value = null
        })
    }

    return { cible, ouvert, demander, annuler, confirmer }
}
