import { ref, watch } from 'vue'

/**
 * Un filtre qui vit dans l'URL, pas dans localStorage.
 *
 * Mémorisé, un filtre survivait à la raison de l'avoir posé : filtré sur
 * « Jambes » un jour de jambes, la bibliothèque s'ouvrait encore filtrée des
 * semaines après, comme si la plupart des exercices avaient disparu. Dans
 * l'URL, il fait ce qu'on attend d'un filtre : il survit à un rechargement, se
 * partage, se quitte par le bouton retour, et une ouverture depuis le menu
 * montre tout.
 *
 * @param {string} parametre le nom du paramètre de requête
 * @param {string} defaut la valeur quand le paramètre est absent
 * @returns {import('vue').Ref<string>}
 */
export function useFiltreDansLUrl(parametre, defaut = 'all') {
    const valeur = ref(new URLSearchParams(window.location.search).get(parametre) || defaut)

    watch(valeur, (nouvelle) => {
        const url = new URL(window.location.href)

        if (nouvelle === defaut) {
            url.searchParams.delete(parametre)
        } else {
            url.searchParams.set(parametre, nouvelle)
        }

        // Le filtrage se fait côté client : ceci ne doit pas devenir une visite
        // Inertia. Rendre l'état courant de l'historique garde intacte la
        // restauration avant/arrière d'Inertia.
        window.history.replaceState(window.history.state, '', url)
    })

    return valeur
}
